<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\FilterGraph;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\MeasuresDuration;
use ProtoneMedia\LaravelFFMpeg\Pipeline\OwnsGraph;
use ProtoneMedia\LaravelFFMpeg\Pipeline\RunsAnalysis;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesCommand;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesOutputs;

/**
 * A mix of the first audio tracks of two sources that keeps the primary media's presentation length.
 */
class PendingAudioMix implements Exportable, MeasuresDuration, OwnsGraph, RunsAnalysis, ShapesCommand, ShapesOutputs
{
    /**
     * The sample rate in hertz both tracks are converted to before mixing.
     */
    protected const int SAMPLE_RATE = 48000;

    use Concerns\AudioFilters;
    use Concerns\Fades;
    use Concerns\GlobalOptions;
    use Concerns\Metadata;
    use Concerns\OutputOptions;
    use Concerns\ResourceFilters;
    use Concerns\Resources;
    use Concerns\RunsExport;
    use Concerns\VideoCodec;
    use Concerns\VideoFilters;
    use Conditionable, Macroable;

    /**
     * The export pipeline this builder configures.
     */
    protected readonly PendingExport $export;

    /**
     * Indicates if the added track repeats until the primary presentation ends.
     */
    protected bool $loop = false;

    /**
     * The offset of the added track in the primary presentation, in seconds.
     */
    protected float $offset = 0;

    /**
     * Create a new pending audio mix instance.
     */
    public function __construct(
        protected readonly Media $source,
        protected readonly Media $added,
        string|Filesystem $disk,
        protected readonly float $volume = 1,
        protected readonly float $originalVolume = 1,
        protected readonly bool $normalize = false,
    ) {
        if (! is_finite($volume) || $volume < 0) {
            throw new InvalidArgumentException('The mix volume must be finite and nonnegative.');
        }

        if (! is_finite($originalVolume) || $originalVolume < 0) {
            throw new InvalidArgumentException(
                'The original volume must be finite and nonnegative.'
            );
        }

        $this->export = new PendingExport($source, $disk, $this);
        $this->export->addInput($added);

        // Adding an input resets the destination to the default disk, so we will set the
        // primary input's disk again.
        $this->export->toDisk($disk);
    }

    /**
     * Start the added track at the given nonnegative offset in the primary presentation.
     */
    public function at(float $seconds): self
    {
        $this->export->assertOutputMutable();
        if (! is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('Audio mix offsets require finite nonnegative seconds.');
        }

        $this->offset = $seconds;

        return $this;
    }

    /**
     * Repeat the added track from its offset without repeating the initial delay.
     */
    public function loop(bool $loop = true): self
    {
        $this->export->assertOutputMutable();
        $this->loop = $loop;

        return $this;
    }

    /**
     * Set the audio encoder and bitrate.
     */
    public function audio(?string $codec = null, mixed $bitrate = null): self
    {
        $this->export->audio($codec, $bitrate);

        return $this;
    }

    /**
     * Set allowed encoder options for every output audio track.
     */
    public function audioOptions(array $tokens): self
    {
        $this->export->audioOptions($tokens);

        return $this;
    }

    /**
     * Omit the video from the output.
     */
    public function withoutVideo(): self
    {
        $this->export->withoutVideo();

        return $this;
    }

    /**
     * Save the mix to the given path.
     */
    public function save(?string $path = null): ExportResult
    {
        return $this->export->save($path);
    }

    /**
     * Map the primary video and the mixed audio to the output.
     *
     * @internal
     */
    public function outputs(array $default, ?string $path): array
    {
        $settings = $this->export->outputConfiguration();

        if (($settings['audio']['codec'] ?? null) === 'copy') {
            throw new InvalidArgumentException('Audio mixing requires encoded audio.');
        }

        if ($this->loop && $this->added->protocolWhitelist() !== 'file') {
            throw new InvalidArgumentException(
                'Looped audio requires a local or downloaded input. Use openUrl(download: true).'
            );
        }

        $settings['maps'] = $settings['omitVideo'] ? [] : ['0:V:0?'];
        $settings['maps'][] = '[mixed_audio]';
        $settings['omitVideo'] = false;

        // The caller's audio filters run after mixing, inside the graph this builder owns.
        $settings['audioFilters'] = [];
        $settings['metadataSource'] ??= 0;
        $settings['chapterSource'] ??= 0;

        return [new ExportOutput($path ?? 'inspection.mp4', $settings['disk'])->configure($settings)];
    }

    /**
     * Get the mix graph, assuming the primary media has audio until it has been probed.
     *
     * @internal
     */
    public function graph(): string
    {
        return $this->mixGraph(true);
    }

    /**
     * Build the mix graph, with or without the primary media's original audio.
     */
    protected function mixGraph(bool $originalAudio, ?float $duration = null): string
    {
        // We will fill timestamp gaps and convert both tracks to a common format before
        // mixing them. Padding keeps a short track from shortening the presentation.
        $format = 'aresample='.self::SAMPLE_RATE.':async=1:first_pts=0,aformat=sample_rates='.self::SAMPLE_RATE.':channel_layouts=stereo';

        // Nothing beyond the primary presentation is audible, so we bound the delay buffer
        // to that presentation, even for very large finite offsets.
        $offset = min($this->offset, $duration ?? PHP_INT_MAX / (self::SAMPLE_RATE * 2));
        $delay = $offset > 0 ? ',adelay=delays='.sprintf('%.0f', round($offset * self::SAMPLE_RATE)).'S:all=1' : '';
        $branches = ["[1:a:0]asetpts=PTS-STARTPTS,{$format},volume="
            .json_encode($this->volume, JSON_THROW_ON_ERROR)."{$delay},apad[added_audio]"];

        if ($originalAudio) {
            $branches[] = "[0:a:0]{$format},volume="
                .json_encode($this->originalVolume, JSON_THROW_ON_ERROR).',apad[original_audio]';
            $branches[] = '[original_audio][added_audio]amix=inputs=2:duration=first:dropout_transition=0:normalize='
                .($this->normalize ? '1' : '0').'[audio_mix]';
        } else {
            $branches[] = '[added_audio]anull[audio_mix]';
        }

        $filters = $this->export->outputConfiguration()['audioFilters'];
        $branches[] = '[audio_mix]'.($filters === [] ? 'anull' : implode(',', $filters)).'[mixed_audio]';

        return implode(';', $branches);
    }

    /**
     * Loop the added input when requested and limit the output to the primary duration.
     *
     * @internal
     */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        $command = $default;
        if ($this->loop) {
            $inputs = array_keys($command, '-i', true);
            array_splice($command, $inputs[1], 0, ['-stream_loop', '-1']);
        }

        $destination = array_pop($command);

        // The real duration is only known once the staged source has been probed, so we
        // will leave a placeholder that analyze() replaces. Inspection must not read files,
        // download media, or run FFprobe.
        return [...$command, '-t', '[source-duration]', $destination];
    }

    /**
     * Ensure both sources can be mixed and get the duration for progress.
     *
     * @internal
     */
    public function duration(array $metadata, ?float $default): ?float
    {
        [$source, $added] = $metadata;
        $duration = $source->duration();

        // The exception category names the condition: metadata for durations and origins,
        // and mapping for missing tracks.
        if (! $source->hasVideo() && ! $source->hasAudio()) {
            throw MediaException::probing('Audio mixing requires primary media with video or audio.', 'mapping');
        }

        if ($duration === null || $duration <= 0) {
            throw MediaException::probing('Audio mixing requires finite primary media.', 'metadata');
        }

        PendingExport::assertZeroOrigins($source, 'Audio mixing', audioOnlyOffset: true);
        $audio = $source->audio();
        if ($source->hasVideo() && $audio !== null
            && (! is_numeric($audio->get('start_time')) || (float) $audio->get('start_time') < 0)) {
            throw MediaException::probing(
                'Original audio requires a known nonnegative timestamp origin relative to video.',
                'metadata',
            );
        }

        if ($added->audio() === null) {
            throw MediaException::probing('Added audio requires an audio track.', 'mapping');
        }

        if ($added->duration() === null || $added->duration() <= 0) {
            throw MediaException::probing('Added audio requires a finite duration.', 'metadata');
        }

        return $this->export->outputConfiguration()['audioFilters'] === [] ? $duration : null;
    }

    /**
     * Prepare a looped track and put the final graph and duration into the command.
     *
     * @internal
     */
    public function analyze(Workspace $workspace, array $command, array $outputPaths, Closure $analyze): array
    {
        if ($this->loop) {
            // We loop decoded samples rather than compressed packets, because encoder delay
            // and end padding would otherwise introduce a gap on each repetition.
            $inputs = array_keys($command, '-i', true);
            $protocols = array_keys($command, '-protocol_whitelist', true);
            $options = array_slice($command, $protocols[1], $inputs[1] + 2 - $protocols[1]);
            array_splice($options, array_search('-stream_loop', $options, true), 2);
            $prepared = 'audio-mix-loop.wav';
            $analyze([
                $command[0], '-hide_banner', '-nostdin', '-y', '-xerror', '-progress', 'pipe:1',
                ...$options, '-map', '0:a:0', '-af',
                'atrim=duration='.CommandCompiler::timestamp($workspace->probe($this->added)->duration())
                    .',asetpts=PTS-STARTPTS',
                '-c:a', 'pcm_f64le', '-f', 'wav', '-rf64', 'auto', $prepared,
            ], 'Audio loop preparation failed.');
            try {
                $duration = $this->added->probeArtifact("{$workspace->path}/{$prepared}", $workspace)->duration();
            } catch (MediaException $exception) {
                if ($exception->category === 'timeout') {
                    throw $exception;
                }

                throw MediaException::analysis(
                    'Looped audio must decode to nonempty samples.',
                    'artifact',
                    $exception->diagnostics,
                );
            }

            if (! $duration) {
                throw MediaException::analysis('Looped audio must decode to nonempty samples.', 'artifact');
            }

            array_splice($command, $protocols[1], $inputs[1] + 2 - $protocols[1], [
                '-protocol_whitelist', 'file', '-stream_loop', '-1', '-i', $prepared,
            ]);
        }

        $source = $workspace->probe($this->source);
        $graph = $this->mixGraph($source->hasAudio(), $source->duration());
        FilterGraph::validate($graph, 2, ['mixed_audio']);
        $command[array_search('-filter_complex', $command, true) + 1] = $graph;
        $command[array_search('-t', $command, true) + 1] = CommandCompiler::timestamp($source->duration());

        return $command;
    }
}

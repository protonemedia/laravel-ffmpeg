<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
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

/** Mix the first audio tracks, retaining the primary media's presentation length. */
final class PendingAudioMix implements Exportable, MeasuresDuration, OwnsGraph, RunsAnalysis, ShapesCommand, ShapesOutputs
{
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
    use Conditionable;

    private readonly PendingExport $export;

    private bool $loop = false;

    private float $offset = 0;

    public function __construct(
        private readonly Media $source,
        private readonly Media $added,
        string|Filesystem $disk,
        private readonly float $volume = 1,
        private readonly float $originalVolume = 1,
        private readonly bool $normalize = false,
    ) {
        if (! is_finite($volume) || $volume < 0 || ! is_finite($originalVolume) || $originalVolume < 0) {
            throw new InvalidArgumentException('Mix volumes require finite nonnegative amplitude multipliers.');
        }

        $this->export = new PendingExport($source, $disk, $this);
        $this->export->addInput($added);
        // addInput() falls back to the default disk; keep the primary input's.
        $this->export->toDisk($disk);
    }

    /** Start the added track at this nonnegative offset in the primary presentation. */
    public function at(float $seconds): self
    {
        $this->export->assertOutputMutable();
        if (! is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('Audio mix offsets require finite nonnegative seconds.');
        }

        $this->offset = $seconds;

        return $this;
    }

    /** Repeat the added track from its offset, without repeating the initial delay. */
    public function loop(bool $loop = true): self
    {
        $this->export->assertOutputMutable();
        $this->loop = $loop;

        return $this;
    }

    public function audio(?string $codec = null, mixed $bitrate = null): self
    {
        $this->export->audio($codec, $bitrate);

        return $this;
    }

    public function audioOptions(array $tokens): self
    {
        $this->export->audioOptions($tokens);

        return $this;
    }

    public function withoutVideo(): self
    {
        $this->export->withoutVideo();

        return $this;
    }

    public function save(?string $path = null): ExportResult
    {
        return $this->export->save($path);
    }

    /** @internal */
    public function outputs(array $default, ?string $path): array
    {
        $settings = $this->export->outputConfiguration();

        if (($settings['audio']['codec'] ?? null) === 'copy') {
            throw new InvalidArgumentException('Audio mixing requires encoded audio.');
        }

        if ($this->loop && $this->added->protocolWhitelist() !== 'file') {
            throw new InvalidArgumentException('Looped audio requires a local or downloaded input. Use openUrl(download: true).');
        }

        $settings['maps'] = $settings['omitVideo'] ? [] : ['0:V:0?'];
        $settings['maps'][] = '[mixed_audio]';
        $settings['omitVideo'] = false;
        $settings['audioFilters'] = []; // Apply these after mixing, inside the owned graph.
        $settings['metadataSource'] ??= 0;
        $settings['chapterSource'] ??= 0;

        return [new ExportOutput($path ?? 'inspection.mp4', $settings['disk'])->configure($settings)];
    }

    /** @internal */
    public function graph(): string
    {
        return $this->mixGraph(true);
    }

    private function mixGraph(bool $originalAudio, ?float $duration = null): string
    {
        // Fill timestamp gaps and convert both tracks to a common format before
        // mixing. Padding keeps a short track from shortening the presentation.
        $format = 'aresample=48000:async=1:first_pts=0,aformat=sample_rates=48000:channel_layouts=stereo';
        // Nothing beyond the primary presentation is audible. Bound the delay
        // buffer to that presentation, even for very large finite offsets.
        $offset = min($this->offset, $duration ?? PHP_INT_MAX / 96000);
        $delay = $offset > 0 ? ',adelay=delays='.sprintf('%.0f', round($offset * 48000)).'S:all=1' : '';
        $branches = ['[1:a:0]asetpts=PTS-STARTPTS,'.$format.',volume='.json_encode($this->volume, JSON_THROW_ON_ERROR).$delay.',apad[added_audio]'];

        if ($originalAudio) {
            $branches[] = '[0:a:0]'.$format.',volume='.json_encode($this->originalVolume, JSON_THROW_ON_ERROR).',apad[original_audio]';
            $branches[] = '[original_audio][added_audio]amix=inputs=2:duration=first:dropout_transition=0:normalize='.($this->normalize ? '1' : '0').'[audio_mix]';
        } else {
            $branches[] = '[added_audio]anull[audio_mix]';
        }

        $filters = $this->export->outputConfiguration()['audioFilters'];
        $branches[] = '[audio_mix]'.($filters === [] ? 'anull' : implode(',', $filters)).'[mixed_audio]';

        return implode(';', $branches);
    }

    /** @internal */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        $command = $default;
        if ($this->loop) {
            $inputs = array_keys($command, '-i', true);
            array_splice($command, $inputs[1], 0, ['-stream_loop', '-1']);
        }

        $destination = array_pop($command);

        // The actual finite duration is resolved only after the staged source is
        // probed. Inspection must not read files, download media, or run FFprobe.
        return [...$command, '-t', '[source-duration]', $destination];
    }

    /** @internal */
    public function duration(array $metadata, ?float $default): ?float
    {
        [$source, $added] = $metadata;
        $duration = $source->duration();
        // Categories name the condition: metadata for durations and origins, mapping for missing tracks.
        if (! $source->hasVideo() && ! $source->hasAudio()) {
            throw new MediaException('Audio mixing requires primary media with video or audio.', 'probing', 'mapping');
        }

        if ($duration === null || $duration <= 0) {
            throw new MediaException('Audio mixing requires finite primary media.', 'probing', 'metadata');
        }

        PendingExport::assertZeroOrigins($source, 'Audio mixing', audioOnlyOffset: true);
        $audio = $source->audio();
        if ($source->hasVideo() && $audio !== null
            && (! is_numeric($audio->get('start_time')) || (float) $audio->get('start_time') < 0)) {
            throw new MediaException('Original audio requires a known nonnegative timestamp origin relative to video.', 'probing', 'metadata');
        }

        if ($added->audio() === null) {
            throw new MediaException('Added audio requires an audio track.', 'probing', 'mapping');
        }

        if ($added->duration() === null || $added->duration() <= 0) {
            throw new MediaException('Added audio requires a finite duration.', 'probing', 'metadata');
        }

        return $this->export->outputConfiguration()['audioFilters'] === [] ? $duration : null;
    }

    /** @internal */
    public function analyze(Workspace $workspace, array $command, array $outputPaths, Closure $analyze): array
    {
        if ($this->loop) {
            // Loop decoded samples, not compressed packets: encoder delay and
            // end padding would otherwise introduce gaps on each repetition.
            $inputs = array_keys($command, '-i', true);
            $protocols = array_keys($command, '-protocol_whitelist', true);
            $options = array_slice($command, $protocols[1], $inputs[1] + 2 - $protocols[1]);
            array_splice($options, array_search('-stream_loop', $options, true), 2);
            $prepared = 'audio-mix-loop.wav';
            $analyze([
                $command[0], '-hide_banner', '-nostdin', '-y', '-xerror', '-progress', 'pipe:1',
                ...$options, '-map', '0:a:0', '-af', 'atrim=duration='.CommandCompiler::timestamp($workspace->probe($this->added)->duration()).',asetpts=PTS-STARTPTS',
                '-c:a', 'pcm_f64le', '-f', 'wav', '-rf64', 'auto', $prepared,
            ], 'Audio loop preparation failed.');
            try {
                $duration = $this->added->probeArtifact($workspace->path.'/'.$prepared, $workspace)->duration();
            } catch (MediaException $exception) {
                if ($exception->category === 'timeout') {
                    throw $exception;
                }

                throw new MediaException('Looped audio must decode to nonempty samples.', Progress::ANALYSIS, 'artifact', $exception->diagnostics);
            }

            if (! $duration) {
                throw new MediaException('Looped audio must decode to nonempty samples.', Progress::ANALYSIS, 'artifact');
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

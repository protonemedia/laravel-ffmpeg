<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\FilterGraph;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/** Mix the first audio tracks, retaining the primary media's presentation length. */
class PendingAudioMix extends PendingExport
{
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

        parent::__construct($source, $disk);
        parent::addInput($added);
        $this->toDisk($disk);
    }

    /** Start the added track at this nonnegative offset in the primary presentation. */
    public function at(float $seconds): self
    {
        $this->assertOutputMutable();
        if (! is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('Audio mix offsets require finite nonnegative seconds.');
        }
        $this->offset = $seconds;

        return $this;
    }

    /** Repeat the added track from its offset, without repeating the initial delay. */
    public function loopAudio(bool $loop = true): self
    {
        $this->assertOutputMutable();
        $this->loop = $loop;

        return $this;
    }

    public function addInput(Media $media): int
    {
        throw new InvalidArgumentException('Audio mixing supports exactly the primary media and one added input.');
    }

    public function output(string $path, callable $configure): self
    {
        throw new InvalidArgumentException('Audio mixing supports one root-configured output.');
    }

    public function filterComplex(string $expression): self
    {
        throw new InvalidArgumentException('Audio mixing owns its graph; use export()->filterComplex() for custom compositions.');
    }

    public function watermark(Media $image, string $position = 'bottom-right', int $x = 0, int $y = 0, ?int $width = null, ?int $height = null, float $opacity = 1): self
    {
        throw new InvalidArgumentException('Audio mixing does not support additional watermark inputs.');
    }

    public function twoPass(): self
    {
        throw new InvalidArgumentException('Audio mixing does not support two-pass video encoding.');
    }

    protected function configuredOutputs(?string $path = null): array
    {
        $settings = $this->outputConfiguration();

        if ($settings['maps'] !== [] || $settings['omitAudio'] || $settings['clip'] !== null
            || $settings['audioStreams'] !== [] || ($settings['audio']['codec'] ?? null) === 'copy') {
            throw new InvalidArgumentException('Audio mixing requires encoded audio, automatic maps, and an unclipped primary source.');
        }
        if ($this->loop && $this->added->protocolWhitelist() !== 'file') {
            throw new InvalidArgumentException('Looped audio requires a local or downloaded input. Use openUrl(download: true).');
        }

        $settings['maps'] = $settings['omitVideo'] ? [] : ['0:V:0?'];
        $settings['maps'][] = '[mixed_audio]';
        $settings['omitAudio'] = $settings['omitVideo'] = false;
        $settings['audioFilters'] = []; // Apply these after mixing, inside the owned graph.
        $settings['metadataSource'] ??= 0;
        $settings['chapterSource'] ??= 0;

        return [(new ExportOutput($path ?? 'inspection.mp4', $settings['disk']))->configure($settings)];
    }

    protected function filterGraph(): ?string
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

        $filters = $this->outputConfiguration()['audioFilters'];
        $branches[] = '[audio_mix]'.($filters === [] ? 'anull' : implode(',', $filters)).'[mixed_audio]';

        return implode(';', $branches);
    }

    protected function command(array $outputs, array $paths, ?array $outputPaths = null): array
    {
        $command = parent::command($outputs, $paths, $outputPaths);
        if ($this->loop) {
            $inputs = array_keys($command, '-i', true);
            array_splice($command, $inputs[1], 0, ['-stream_loop', '-1']);
        }
        $destination = array_pop($command);

        // The actual finite duration is resolved only after the staged source is
        // probed. Inspection must not read files, download media, or run FFprobe.
        return [...$command, '-t', '[source-duration]', $destination];
    }

    protected function prepareEncoding(array $metadata): ?float
    {
        [$source, $added] = $metadata;
        $duration = $source->duration();
        $origin = $source->get('format.start_time');
        // Categories name the condition: metadata for durations and origins, mapping for missing tracks.
        if (! $source->hasVideo() && ! $source->hasAudio()) {
            throw new MediaException('Audio mixing requires primary media with video or audio.', 'probing', 'mapping');
        }
        if ($duration === null || $duration <= 0
            || ($source->hasVideo() && (! is_numeric($origin) || abs((float) $origin) > 0.000001))) {
            throw new MediaException('Audio mixing requires finite primary media and zero video presentation origins.', 'probing', 'metadata');
        }
        foreach ($source->videoStreams() as $stream) {
            if (! is_numeric($stream->get('start_time')) || abs((float) $stream->get('start_time')) > 0.000001) {
                throw new MediaException('Audio mixing requires zero video origins.', 'probing', 'metadata');
            }
        }
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

        return $this->outputConfiguration()['audioFilters'] === [] ? $duration : null;
    }

    protected function prepareCommand(Workspace $workspace, array $command, array $outputPaths, Closure $analyze): array
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
                ...$options, '-map', '0:a:0', '-af', 'atrim=duration='.$this->clipTimestamp($workspace->probe($this->added)->duration()).',asetpts=PTS-STARTPTS',
                '-c:a', 'pcm_f64le', '-f', 'wav', '-rf64', 'auto', $prepared,
            ], 'Audio loop preparation failed.');
            $probe = $workspace->runner->run([
                config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'), '-v', 'error',
                '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $prepared,
            ], $workspace->path, outputLimit: 65536, phase: Progress::ANALYSIS);
            if ($probe->exitCode !== 0 || ! is_numeric(trim($probe->output)) || (float) $probe->output <= 0) {
                throw new MediaException('Looped audio must decode to nonempty samples.', Progress::ANALYSIS, 'artifact', $probe->diagnostics);
            }
            array_splice($command, $protocols[1], $inputs[1] + 2 - $protocols[1], [
                '-protocol_whitelist', 'file', '-stream_loop', '-1', '-i', $prepared,
            ]);
        }
        $source = $workspace->probe($this->source);
        $graph = $this->mixGraph($source->hasAudio(), $source->duration());
        FilterGraph::validate($graph, 2, ['mixed_audio']);
        $command[array_search('-filter_complex', $command, true) + 1] = $graph;
        $command[array_search('-t', $command, true) + 1] = $this->clipTimestamp($source->duration());

        return $command;
    }
}

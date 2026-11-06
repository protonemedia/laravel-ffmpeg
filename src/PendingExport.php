<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use League\Flysystem\WhitespacePathNormalizer;
use ProtoneMedia\LaravelFFMpeg\Events;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\ProcessRunner;
use ProtoneMedia\LaravelFFMpeg\Execution\ProgressParser;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use Throwable;

class PendingExport
{
    use Conditionable;

    private array $video = [];

    private array $audio = [];

    private array $maps = [];

    private array $videoFilters = [];

    private array $audioFilters = [];

    private array $globalOptions = [];

    private array $outputOptions = [];

    private array $writeOptions = [];

    private array $metadata = [];

    private bool $omitAudio = false;

    private bool $omitVideo = false;

    private bool $copyMetadata = true;

    private ?int $chapterSource = null;

    private ?array $clip = null;

    private ?Closure $progress = null;

    public function __construct(private readonly Media $media, private string|Filesystem $disk) {}

    public function video(?string $codec = null, mixed $bitrate = null, ?float $crf = null, ?string $preset = null): self
    {
        $this->codec($codec);
        $this->bitrate($bitrate);

        if ($crf !== null && (! is_finite($crf) || $crf < 0)) {
            throw new InvalidArgumentException('CRF must be a finite, nonnegative encoder-specific value.');
        }

        if ($preset !== null && ! preg_match('/^[a-zA-Z0-9_-]+$/D', $preset)) {
            throw new InvalidArgumentException('Invalid encoder preset.');
        }

        $this->video = array_replace($this->video, array_filter(compact('codec', 'bitrate', 'crf', 'preset'), fn ($value) => $value !== null));

        return $this;
    }

    public function audio(?string $codec = null, mixed $bitrate = null): self
    {
        $this->codec($codec);
        $this->bitrate($bitrate);
        $this->audio = array_replace($this->audio, array_filter(compact('codec', 'bitrate'), fn ($value) => $value !== null));

        return $this;
    }

    private function codec(?string $codec): void
    {
        if ($codec !== null && ! preg_match('/^[a-zA-Z0-9_]+$/D', $codec)) {
            throw new InvalidArgumentException('Invalid FFmpeg encoder name.');
        }
    }

    private function bitrate(mixed $bitrate): void
    {
        if ($bitrate !== null && (! is_string($bitrate) || ! preg_match('/^[0-9]+(?:\.[0-9]+)?[kKmMgG]$/D', $bitrate) || (float) $bitrate <= 0)) {
            throw new InvalidArgumentException('Bitrates require an explicit unit string, such as 2500k.');
        }
    }

    public function map(string $specifier): self
    {
        if (! preg_match('/^-?0(?::[^\x00\r\n]+)?\??$/D', $specifier)) {
            throw new InvalidArgumentException('Maps must reference input 0. Graph and multiple-input composition are not implemented yet.');
        }

        $this->maps[] = $specifier;

        return $this;
    }

    public function withoutAudio(): self
    {
        $this->omitAudio = true;

        return $this;
    }

    public function withoutVideo(): self
    {
        $this->omitVideo = true;

        return $this;
    }

    public function videoFilter(string $expression): self
    {
        $this->filter($expression);
        $this->videoFilters[] = $expression;

        return $this;
    }

    public function audioFilter(string $expression): self
    {
        $this->filter($expression);
        $this->audioFilters[] = $expression;

        return $this;
    }

    private function filter(string $expression): void
    {
        if ($expression === '' || str_contains($expression, "\0") || preg_match('~[a-z][a-z0-9+.-]*://~i', $expression)) {
            throw new InvalidArgumentException('Filters require a nonempty, developer-trusted expression without network URLs.');
        }
    }

    public function globalOptions(array $tokens): self
    {
        $this->globalOptions = CommandCompiler::options([...$this->globalOptions, ...$tokens], 'global');

        return $this;
    }

    public function outputOptions(array $tokens): self
    {
        $this->outputOptions = CommandCompiler::options([...$this->outputOptions, ...$tokens], 'output');

        return $this;
    }

    public function withoutMetadata(): self
    {
        $this->copyMetadata = false;

        return $this;
    }

    public function metadata(string $key, string $value): self
    {
        if (! preg_match('/^[a-zA-Z0-9_.:-]+$/D', $key) || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Invalid metadata tag.');
        }

        $this->metadata[$key] = $value;

        return $this;
    }

    public function chaptersFrom(?int $input): self
    {
        if ($input !== null && $input !== 0) {
            throw new InvalidArgumentException('The chapter source must be input 0 or null to omit chapters.');
        }

        $this->chapterSource = $input ?? -1;

        return $this;
    }

    public function clip(float $start, ?float $duration = null, ?float $end = null, string $seek = 'accurate'): self
    {
        if (! is_finite($start) || $start < 0 || ($duration === null) === ($end === null)
            || ($duration !== null && (! is_finite($duration) || $duration <= 0))
            || ($end !== null && (! is_finite($end) || $end <= $start))
            || ! in_array($seek, ['accurate', 'keyframe'], true)) {
            throw new InvalidArgumentException('A clip needs a nonnegative start and either a positive duration or a later end.');
        }

        $this->clip = ['start' => $start, 'duration' => $duration ?? $end - $start, 'seek' => $seek];

        return $this;
    }

    public function toDisk(string|Filesystem $disk, array $options = []): self
    {
        $this->disk = $disk;
        $this->writeOptions = $options;

        return $this;
    }

    public function visibility(string $visibility): self
    {
        $this->writeOptions['visibility'] = $visibility;

        return $this;
    }

    public function onProgress(callable $callback): self
    {
        $this->progress = Closure::fromCallable($callback);

        return $this;
    }

    /** Pure, redacted inspection. No files or processes are created. */
    public function inspect(): array
    {
        return $this->command('[input:0]', '[output:0]');
    }

    private function command(string $input, string $output): array
    {
        if (isset($this->video['bitrate'], $this->video['crf'])) {
            throw new InvalidArgumentException('Choose either a target bitrate or CRF.');
        }

        if ($this->maps !== [] && ($this->omitAudio || $this->omitVideo)) {
            throw new InvalidArgumentException('Use maps alone to omit tracks when explicit mapping is enabled.');
        }

        if ($this->maps === [] && $this->omitAudio && $this->omitVideo) {
            throw new InvalidArgumentException('An export must select audio or video.');
        }

        foreach ([[$this->video, $this->videoFilters], [$this->audio, $this->audioFilters]] as [$encoding, $filters]) {
            if (($encoding['codec'] ?? null) === 'copy' && ($filters !== [] || count($encoding) > 1
                || ($this->clip !== null && $this->clip['seek'] === 'accurate'))) {
                throw new InvalidArgumentException('Copied streams cannot have filters, encoder settings, or accurate clipping.');
            }
        }

        $inputOptions = $this->media->inputOptions();
        $options = [];

        if ($this->clip !== null) {
            if ($this->clip['seek'] === 'keyframe') {
                array_push($inputOptions, '-ss', (string) $this->clip['start'], '-noaccurate_seek');
            } else {
                array_push($options, '-ss', (string) $this->clip['start']);
            }

            array_push($options, '-t', (string) $this->clip['duration']);
        }

        $maps = $this->maps !== [] ? $this->maps : array_filter([
            $this->omitVideo ? null : '0:V:0?', $this->omitAudio ? null : '0:a:0?',
        ]);

        foreach ($maps as $map) {
            array_push($options, '-map', $map);
        }

        array_push($options, '-map_metadata', $this->copyMetadata ? '0' : '-1', '-map_chapters', (string) ($this->chapterSource ?? ($this->clip === null ? 0 : -1)));

        foreach ([['v', $this->video, $this->videoFilters], ['a', $this->audio, $this->audioFilters]] as [$type, $encoding, $filters]) {
            foreach ($encoding as $name => $value) {
                $option = match ($name) { 'codec' => 'c', 'bitrate' => 'b', default => $name };
                array_push($options, '-'.$option.':'.$type, (string) $value);
            }

            if ($filters !== []) {
                array_push($options, '-filter:'.$type, implode(',', $filters));
            }
        }

        foreach ($this->metadata as $key => $value) {
            array_push($options, '-metadata', $key.'='.$value);
        }

        if (! $this->omitVideo && ($this->video['codec'] ?? null) !== 'copy'
            && ! array_any($this->outputOptions, fn ($token) => preg_match('/^-(?:r|fps_mode)(?::|$)/', $token))) {
            array_push($options, '-fps_mode:v', 'passthrough');
        }

        $threads = config('laravel-ffmpeg.ffmpeg.threads');

        if ($threads !== false && $threads !== null && ! in_array('-threads', $this->outputOptions, true)) {
            array_push($options, '-threads', (string) $threads);
        }

        return CommandCompiler::compile(
            config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'),
            [['path' => $input, 'options' => $inputOptions]],
            [['path' => $output, 'options' => [...$options, ...$this->outputOptions]]],
            $this->globalOptions,
        );
    }

    public function save(string $path): ExportResult
    {
        if ($path === '' || preg_match('/^(?:[\\\\\/]|[a-z][a-z0-9+.-]*:)/i', $path)) {
            throw new InvalidArgumentException('The destination must be a disk-relative key.');
        }

        $path = (new WhitespacePathNormalizer)->normalizePath($path);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($path === '' || ($extension !== '' && ! preg_match('/^[a-zA-Z0-9]{1,16}$/D', $extension))) {
            throw new InvalidArgumentException('Invalid media destination.');
        }

        $this->inspect();
        $operationId = bin2hex(random_bytes(16));
        $destination = ['disk' => is_string($this->disk) ? $this->disk : null, 'path' => $path];
        $published = [];
        $phase = 'staging';

        try {
            Event::dispatch(new Events\ExportStarting($operationId, [$destination]));
            $this->notify(new Progress($phase));

            $result = $this->media->withInput(function (string $input, Workspace $workspace) use ($path, $extension, &$phase, &$published, $destination) {
                $runner = new ProcessRunner;
                $phase = 'probing';
                $this->notify(new Progress($phase));
                $ffmpeg = $runner->version(config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'), 'ffmpeg', $workspace->path);
                $ffprobe = $runner->version(config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'), 'ffprobe', $workspace->path);

                if ($ffmpeg !== $ffprobe) {
                    throw new MediaException('FFmpeg and FFprobe must have matching release versions.', 'probing', 'version');
                }

                $metadata = $this->media->probePath($input, $workspace);
                $this->validateMaps($input, $workspace, $metadata);
                $output = $workspace->path.'/output'.($extension === '' ? '' : '.'.$extension);
                $duration = $metadata->duration();

                if ($this->clip !== null) {
                    $duration = $duration === null ? null : max(0, min($this->clip['duration'], $duration - $this->clip['start']));
                }

                $phase = 'encoding';
                $this->notify(new Progress($phase));
                $process = $runner->run(
                    $this->command($input, $output), $workspace->path,
                    [$input => '[input:0]', $output => '[output:0]'],
                    phase: $phase, progress: new ProgressParser($duration), onProgress: $this->progress,
                );

                if ($process->exitCode !== 0) {
                    throw new MediaException('FFmpeg failed (exit '.$process->exitCode.').', $phase, 'exit', $process->diagnostics);
                }

                $phase = 'validation';
                $this->notify(new Progress($phase));
                clearstatcache(true, $output);

                if (! is_file($output) || ($size = filesize($output)) === false || $size === 0) {
                    throw new MediaException('FFmpeg did not produce a nonempty media file.', $phase, 'artifact');
                }

                $phase = 'publication';
                $this->notify(new Progress($phase));
                $stream = null;

                try {
                    $disk = is_string($this->disk) ? Storage::disk($this->disk) : $this->disk;
                    $stream = fopen($output, 'rb');

                    if ($stream === false || ! $disk->put($path, $stream, $this->writeOptions)) {
                        throw new \RuntimeException('Publication failed.');
                    }

                    $published[] = $destination;

                    if ($disk->size($path) !== $size) {
                        throw new \RuntimeException('Published size mismatch.');
                    }
                } catch (Throwable) {
                    throw new MediaException('Cannot publish the media output.', $phase, 'transfer', published: $published, destination: $destination);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                return new ExportResult([new GeneratedFile($disk, $destination['disk'], $path, $size)], [$process->summary()]);
            });
        } catch (Throwable $exception) {
            $event = $exception instanceof ExportCancelled ? Events\ExportCancelled::class : Events\ExportFailed::class;

            try {
                Event::dispatch(new $event($operationId, $exception instanceof MediaException ? $exception->phase : $phase, [$destination], $published));
            } catch (Throwable) {
                // A listener must not replace the primary process or storage failure.
            }

            throw $exception;
        }

        Event::dispatch(new Events\ExportCompleted($operationId, $result->files()->map->toArray()->all()));

        return $result;
    }

    private function validateMaps(string $input, Workspace $workspace, Metadata $metadata): void
    {
        if ($this->maps === []) {
            return;
        }

        $selected = $cache = [];

        foreach ($this->maps as $map) {
            $exclude = str_starts_with($map, '-');
            $optional = str_ends_with($map, '?');
            $selector = substr(ltrim(rtrim($map, '?'), '-'), 1);
            $selector = str_starts_with($selector, ':') ? substr($selector, 1) : $selector;
            $streams = $selector === '' ? $metadata->streams() : ($cache[$selector] ??= $this->media->probePath($input, $workspace, $selector)->streams());

            if ($streams->isEmpty() && ! $exclude && ! $optional) {
                throw new MediaException('A required stream map matched no streams.', 'probing', 'mapping');
            }

            foreach ($streams as $stream) {
                $index = $stream->get('index');

                if ($exclude) {
                    unset($selected[$index]);
                } else {
                    $selected[$index] = true;
                }
            }
        }

        // FFmpeg can fall back to automatic selection when every optional map
        // misses. An explicit mapping request must never add hidden tracks.
        if ($selected === []) {
            throw new MediaException('Explicit stream maps selected no output streams.', 'probing', 'mapping');
        }
    }

    private function notify(Progress $progress): void
    {
        if ($this->progress !== null) {
            ($this->progress)($progress);
        }
    }
}

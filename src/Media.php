<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use JsonException;
use League\Flysystem\WhitespacePathNormalizer;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\FilterGraph;
use ProtoneMedia\LaravelFFMpeg\Execution\InputSource;
use ProtoneMedia\LaravelFFMpeg\Execution\ProcessRunner;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use Throwable;

readonly class Media
{
    /** @internal Manifest formats whose dependencies cannot be staged as a single file. */
    public const MANIFEST_EXTENSIONS = ['m3u8', 'm3u', 'mpd', 'ffconcat'];

    private string|File|UploadedFile|InputSource $source;

    private array $options;

    public function __construct(private string|Filesystem $disk, string|File|UploadedFile|InputSource $source, array $inputOptions = [])
    {
        if (is_string($source)) {
            if ($source === '' || preg_match('/^(?:[\\\\\/]|[a-z][a-z0-9+.-]*:)/i', $source)) {
                throw new InvalidArgumentException('open() requires a disk-relative key. Use a File for an absolute local path.');
            }

            $source = (new WhitespacePathNormalizer)->normalizePath($source);

            if ($source === '') {
                throw new InvalidArgumentException('The media key cannot be empty.');
            }
        }

        $this->source = $source;
        $this->options = CommandCompiler::options($inputOptions, 'input');
    }

    public function export(): PendingExport
    {
        return new PendingExport($this, $this->defaultOutputDisk());
    }

    public function hls(float $segmentDuration = 6): PendingHlsExport
    {
        return new PendingHlsExport($this, $this->defaultOutputDisk(), $segmentDuration);
    }

    public function dash(float $segmentDuration = 6): PendingDashExport
    {
        return new PendingDashExport($this, $this->defaultOutputDisk(), $segmentDuration);
    }

    public function liveHls(float $segmentDuration = 6, int $window = 6): PendingLiveHls
    {
        return new PendingLiveHls($this, $this->defaultOutputDisk(), $segmentDuration, $window);
    }

    public function probeQuery(): PendingProbe
    {
        return new PendingProbe($this);
    }

    public function speed(float $factor): PendingSpeed
    {
        return new PendingSpeed($this, $this->defaultOutputDisk(), $factor);
    }

    public function gif(int $width = 480, int|float $frameRate = 12, int $loop = 0): PendingGif
    {
        return new PendingGif($this, $this->defaultOutputDisk(), $width, $frameRate, $loop);
    }

    public function still(float $duration, int $frameRate = 25): PendingStill
    {
        return new PendingStill($this, $this->defaultOutputDisk(), $duration, $frameRate);
    }

    public function mixAudio(Media $audio, float $volume = 1, float $originalVolume = 1, bool $normalize = false): PendingAudioMix
    {
        return new PendingAudioMix($this, $audio, $this->defaultOutputDisk(), $volume, $originalVolume, $normalize);
    }

    public function backgroundMusic(Media $music, float $volume = 0.2, bool $loop = true): PendingAudioMix
    {
        return $this->mixAudio($music, $volume)->loopAudio($loop);
    }

    public function concat(Media ...$following): self
    {
        return new self($this->defaultOutputDisk(), new ConcatInput([$this, ...$following]));
    }

    public function concatTranscoded(Media ...$following): PendingConcat
    {
        return new PendingConcat([$this, ...$following], $this->defaultOutputDisk());
    }

    public function prepareImage(callable $prepare): self
    {
        return new self($this->defaultOutputDisk(), new PreparedImageInput($this, Closure::fromCallable($prepare)));
    }

    public function frame(float|string $at = 0): PendingFrame
    {
        return new PendingFrame($this, $this->defaultOutputDisk(), $at);
    }

    public function frames(?float $every = null, ?int $count = null): PendingFrames
    {
        return new PendingFrames($this, $this->defaultOutputDisk(), $every, $count);
    }

    public function storyboard(float $every, int $columns = 5, int $rows = 5, int $margin = 0, int $padding = 0): PendingStoryboard
    {
        return new PendingStoryboard($this, $this->defaultOutputDisk(), $every, $columns, $rows, $margin, $padding);
    }

    public function waveform(int $width = 640, int $height = 120, string|array $colors = ['blue']): PendingWaveform
    {
        return new PendingWaveform($this, $this->defaultOutputDisk(), $width, $height, $colors);
    }

    public function waveformPeaks(int $samplesPerBucket = 256): PendingWaveformPeaks
    {
        return new PendingWaveformPeaks($this, $this->defaultOutputDisk(), $samplesPerBucket);
    }

    public function normalizeLoudness(
        float $integrated = -23,
        float $truePeak = -2,
        float $range = 7,
        bool $linear = true,
        int $sampleRate = 48000,
        int $audio = 0,
    ): PendingLoudness {
        return new PendingLoudness($this, $this->defaultOutputDisk(), $integrated, $truePeak, $range, $linear, $sampleRate, $audio);
    }

    /** @internal */
    public function defaultOutputDisk(): string|Filesystem
    {
        // A concat descriptor is created on its first input's output disk.
        return is_string($this->source) || $this->source instanceof SequenceInput || $this->source instanceof PreparedImageInput
            || $this->source instanceof ConcatInput ? $this->disk : config('filesystems.default');
    }

    /** @internal */
    public function identity(): string
    {
        return hash('sha256', serialize([
            is_string($this->disk) ? ['name', $this->disk] : ['object', spl_object_id($this->disk)],
            $this->source instanceof InputSource ? ['source', $this->source->identity()]
                : (is_string($this->source) ? ['key', $this->source] : ['file', $this->source->getPathname()]),
            $this->options,
        ]));
    }

    /** @internal */
    public function extension(): string
    {
        $extension = $this->source instanceof InputSource ? $this->source->extension()
            : pathinfo(is_string($this->source) ? $this->source : $this->source->getPathname(), PATHINFO_EXTENSION);

        return preg_match('/\A[a-z0-9]{1,32}\z/i', $extension) ? strtolower($extension) : '';
    }

    /** @internal */
    public function inputOptions(): array
    {
        return $this->options;
    }

    /** @internal */
    public function protocolWhitelist(): string
    {
        return $this->source instanceof InputSource ? $this->source->protocols() : 'file';
    }

    /** @internal */
    public function transportOptions(): array
    {
        return $this->source instanceof InputSource ? $this->source->options() : [];
    }

    /** @internal */
    public function redactions(): array
    {
        return $this->source instanceof InputSource ? $this->source->redactions() : [];
    }

    public function probe(): Metadata
    {
        return $this->withInput(fn(string $path, Workspace $workspace): \ProtoneMedia\LaravelFFMpeg\Metadata => $workspace->probe($this));
    }

    /** The caller's key must change whenever the source bytes or object version change. */
    public function probeCached(Cache $cache, string $versionedKey, DateTimeInterface|DateInterval|int $ttl = 3600): Metadata
    {
        if ($versionedKey === '' || (is_int($ttl) && $ttl < 1)) {
            throw new InvalidArgumentException('Cached probing requires a source-versioned key and positive TTL.');
        }

        $key = 'laravel-ffmpeg:probe:v1:'.hash('sha256', serialize([
            $this->identity(), $versionedKey, config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'),
        ]));
        $data = $cache->get($key);
        if (! $this->isProbeData($data)) {
            $data = $this->probe()->toArray();
            $cache->put($key, $data, $ttl);
        }

        return new Metadata($data, $this->identity());
    }

    public function analyze(string $audioFilter = 'volumedetect', int $audio = 0): ProcessResult
    {
        FilterGraph::validateExpression($audioFilter);
        if ($audio < 0) {
            throw new InvalidArgumentException('Audio analysis requires a nonnegative audio track index.');
        }

        return $this->withInput(function (string $path, Workspace $workspace) use ($audioFilter, $audio): \ProtoneMedia\LaravelFFMpeg\ProcessResult {
            $runner = $workspace->runner;
            $binary = config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg');
            $runner->version($binary, 'ffmpeg', $workspace->path, Progress::ANALYSIS);
            $workspace->requireProtocols($binary, $this->protocolWhitelist(), Progress::ANALYSIS);

            return $runner->run([
                $binary, '-hide_banner', '-nostdin', '-nostats', '-xerror', '-loglevel', 'info',
                '-protocol_whitelist', $this->protocolWhitelist(), ...$this->transportOptions(), ...$this->inputOptions(), '-i', $path,
                '-map', '0:a:'.$audio, '-vn', '-sn', '-dn', '-af', $audioFilter, '-f', 'null', '-',
            ], $workspace->path, [$path => '[input]'] + $this->redactions(), phase: Progress::ANALYSIS, redactOutput: true);
        });
    }

    /** @internal */
    public function withInput(callable $operation, ?ProcessRunner $runner = null): mixed
    {
        $workspace = new Workspace($runner ?? new ProcessRunner);
        $failure = null;

        try {
            $path = $workspace->stage($this);

            return $operation($path, $workspace);
        } catch (Throwable $throwable) {
            $failure = $throwable;

            throw $throwable;
        } finally {
            try {
                $workspace->delete();
            } catch (Throwable $exception) {
                if (!$failure instanceof \Throwable) {
                    throw $exception;
                }
            }
        }
    }

    /** @internal FFprobe arguments for this input: its transport options and the input options that affect probing. */
    public function probeOptions(): array
    {
        // A still image's loop and frame rate describe the generated video, not the image.
        $options = $this->source instanceof StillInput ? [] : $this->transportOptions();

        for ($i = 0; $i < count($this->options); $i += 2) {
            if (in_array($this->options[$i], ['-probesize', '-analyzeduration'], true)) {
                $options[] = $this->options[$i];
                $options[] = $this->options[$i + 1];
            }
        }

        return $options;
    }

    /** @internal Probe this input at the path the workspace staged it to. */
    public function probeInput(string $path, Workspace $workspace, ?string $selector = null): Metadata
    {
        $protocols = $this->protocolWhitelist();
        $workspace->requireProtocols(config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'), $protocols);
        $data = $this->runProbe($workspace, $path, '[input]', $protocols,
            [...$this->probeOptions(), ...($selector === null ? [] : ['-select_streams', $selector])]);

        // Do not return temporary paths or credential-bearing URLs.
        $data['format']['filename'] = $this->source instanceof InputSource ? $this->source->label()
            : (is_string($this->source) ? $this->source : $this->source->getPathname());

        return new Metadata($data, $this->identity());
    }

    /**
     * @internal Probe a file generated from this input. It is always a local file read
     * without the input's transport options, and its streams carry no source identity.
     */
    public function probeArtifact(string $path, Workspace $workspace): Metadata
    {
        $data = $this->runProbe($workspace, $path, '[output]', 'file', []);
        $data['format']['filename'] = basename($path);

        return new Metadata($data);
    }

    /** @internal Apply process redactions to every decoded probe string, after JSON decoding so escaped values are covered. */
    public static function redactProbeData(array $data, array $redactions): array
    {
        array_walk_recursive($data, static function (&$value) use ($redactions): void {
            if (is_string($value)) {
                $value = strtr($value, $redactions);
            }
        });

        return $data;
    }

    private function runProbe(Workspace $workspace, string $path, string $label, string $protocols, array $options): array
    {
        $redactions = [$path => $label] + $this->redactions();
        $result = $workspace->runner->run([
            config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'), '-v', 'error', '-print_format', 'json',
            '-show_format', '-show_streams', '-show_chapters', '-protocol_whitelist', $protocols, ...$options, $path,
        ], $workspace->path, $redactions);

        if ($result->exitCode !== 0) {
            throw new MediaException('FFprobe failed (exit '.$result->exitCode.').', 'probing', 'exit', $result->diagnostics);
        }

        try {
            $data = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MediaException('FFprobe returned invalid JSON.', 'probing', 'metadata');
        }

        if (! $this->isProbeData($data)) {
            throw new MediaException('FFprobe returned invalid metadata.', 'probing', 'metadata');
        }

        return self::redactProbeData($data, $redactions);
    }

    private function isProbeData(mixed $data): bool
    {
        return is_array($data) && isset($data['format'], $data['streams']) && is_array($data['format'])
            && is_array($data['streams']) && array_is_list($data['streams'])
            && ! array_any($data['streams'], fn ($stream): bool => ! is_array($stream));
    }

    /** @internal */
    public function stage(Workspace $workspace, string $name = 'input', bool $resource = false): string
    {
        if (! preg_match('/\A[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9]+)?\z/i', $name)) {
            throw new InvalidArgumentException('The staging name must be a safe generated filename.');
        }

        $input = null;
        $output = null;

        try {
            if ($this->source instanceof InputSource) {
                return $this->source->stage($workspace, $name, $resource);
            }

            if (! is_string($this->source)) {
                return $this->absoluteLocalPath($this->source->getPathname());
            }

            $disk = is_string($this->disk) ? Storage::disk($this->disk) : $this->disk;

            if ($disk instanceof LocalFilesystemAdapter) {
                return $this->absoluteLocalPath($disk->path($this->source));
            }

            if (! $resource && in_array($this->extension(), self::MANIFEST_EXTENSIONS, true)) {
                throw new MediaException('Remote manifest dependencies cannot be staged as a single file.', 'staging', 'manifest');
            }

            $path = $workspace->path.DIRECTORY_SEPARATOR.$name;
            $output = @fopen($path, 'xb');

            if ($output === false || ! chmod($path, 0600)) {
                throw new MediaException('Cannot create the staged media file.', 'staging', 'filesystem');
            }

            $expectedBytes = $disk->size($this->source);
            $input = $disk->readStream($this->source);

            if (! is_resource($input)) {
                throw new MediaException('Cannot read the media input.', 'staging', 'transfer');
            }

            $bytes = stream_copy_to_stream($input, $output);

            if ($bytes === false || $bytes !== $expectedBytes || ! feof($input) || ! fflush($output) || fstat($output)['size'] !== $bytes) {
                throw new MediaException('The media input transfer did not complete.', 'staging', 'transfer');
            }

            if (! $resource) {
                self::rejectManifestContent($path);
            }

            return $path;
        } catch (Throwable $throwable) {
            if ($throwable instanceof MediaException) {
                throw $throwable;
            }

            throw new MediaException('Cannot stage the media input.', 'staging', 'transfer');
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }

            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    /** @internal Reject a staged file whose content is a manifest, whatever its extension. */
    public static function rejectManifestContent(string $path): void
    {
        $prefix = file_get_contents($path, false, null, 0, 4096);

        if (preg_match('/^(?:\xEF\xBB\xBF)?\s*(?:#EXTM3U|ffconcat\s)|<(?:\w+:)?MPD(?:\s|>)/i', $prefix)) {
            throw new MediaException('Remote manifest dependencies cannot be staged as a single file.', 'staging', 'manifest');
        }
    }

    private function absoluteLocalPath(string $path): string
    {
        $absolute = realpath($path);

        if ($absolute === false || ! is_file($absolute) || ! is_readable($absolute)) {
            throw new MediaException('Cannot read the local media input.', 'staging', 'filesystem');
        }

        return $absolute;
    }
}

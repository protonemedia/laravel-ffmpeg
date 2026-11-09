<?php

namespace ProtoneMedia\LaravelFFMpeg;

use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Image\Transformation;
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

/**
 * A media input on a disk, in a local file, or behind another input source.
 */
class Media
{
    /**
     * The manifest formats whose dependencies cannot be staged as a single file.
     *
     * @internal
     */
    public const MANIFEST_EXTENSIONS = ['m3u8', 'm3u', 'mpd', 'ffconcat'];

    /**
     * The maximum size in bytes of a playlist or manifest we read or write.
     *
     * @internal
     */
    public const int MAX_MANIFEST_BYTES = 16777216;

    /**
     * The disk key, local file, or input source the media reads from.
     */
    protected readonly string|File|UploadedFile|InputSource $source;

    /**
     * The validated FFmpeg input options.
     */
    protected readonly array $options;

    /**
     * Create a new media instance.
     */
    public function __construct(
        protected readonly string|Filesystem $disk,
        string|File|UploadedFile|InputSource $source,
        array $inputOptions = [],
    ) {
        if (is_string($source)) {
            if ($source === '') {
                throw new InvalidArgumentException('The media key must not be empty.');
            }

            if (preg_match('/^(?:[\\\\\/]|[a-z][a-z0-9+.-]*:)/i', $source)) {
                throw new InvalidArgumentException(
                    'The media key must be disk-relative; use a File for an absolute local path.'
                );
            }

            $source = (new WhitespacePathNormalizer)->normalizePath($source);

            if ($source === '') {
                throw new InvalidArgumentException('The media key must not be empty.');
            }
        }

        $this->source = $source;
        $this->options = CommandCompiler::options($inputOptions, 'input');
    }

    /**
     * Begin exporting the media.
     */
    public function export(): PendingExport
    {
        return new PendingExport($this, $this->defaultOutputDisk());
    }

    /**
     * Begin exporting the media as HLS.
     */
    public function hls(float $segmentDuration = 6): PendingHlsExport
    {
        return new PendingHlsExport($this, $this->defaultOutputDisk(), $segmentDuration);
    }

    /**
     * Begin exporting the media as DASH.
     */
    public function dash(float $segmentDuration = 6): PendingDashExport
    {
        return new PendingDashExport($this, $this->defaultOutputDisk(), $segmentDuration);
    }

    /**
     * Begin publishing the media as live HLS.
     */
    public function liveHls(float $segmentDuration = 6, int $window = 6): PendingLiveHls
    {
        return new PendingLiveHls($this, $this->defaultOutputDisk(), $segmentDuration, $window);
    }

    /**
     * Begin a probe query for selected streams and fields.
     */
    public function probeQuery(): PendingProbe
    {
        return new PendingProbe($this);
    }

    /**
     * Begin exporting the media at the given playback speed.
     */
    public function speed(float $factor): PendingSpeed
    {
        return new PendingSpeed($this, $this->defaultOutputDisk(), $factor);
    }

    /**
     * Begin exporting the media as an animated GIF.
     *
     * The GIF loops forever by default. Pass loop: false to play once, or loops: n to repeat
     * n times after the first play.
     */
    public function gif(int $width = 480, int|float $frameRate = 12, bool $loop = true, ?int $loops = null): PendingGif
    {
        return new PendingGif($this, $this->defaultOutputDisk(), $width, $frameRate, $loop, $loops);
    }

    /**
     * Begin exporting this image as a still video of the given duration in seconds.
     */
    public function still(float $duration, int $frameRate = 25): PendingStill
    {
        return new PendingStill($this, $this->defaultOutputDisk(), $duration, $frameRate);
    }

    /**
     * Begin mixing the given audio into the media.
     */
    public function mixAudio(
        Media $audio,
        float $volume = 1,
        float $originalVolume = 1,
        bool $normalize = false,
    ): PendingAudioMix {
        return new PendingAudioMix($this, $audio, $this->defaultOutputDisk(), $volume, $originalVolume, $normalize);
    }

    /**
     * Begin mixing the given music under the media's audio.
     */
    public function backgroundMusic(Media $music, float $volume = 0.2, bool $loop = true): PendingAudioMix
    {
        return $this->mixAudio($music, $volume)->loop($loop);
    }

    /**
     * Join the given media after this one without re-encoding.
     */
    public function concat(Media ...$following): self
    {
        return new self($this->defaultOutputDisk(), new ConcatInput([$this, ...$following]));
    }

    /**
     * Begin joining the given media after this one by re-encoding.
     */
    public function concatTranscoded(Media ...$following): PendingConcat
    {
        return new PendingConcat([$this, ...$following], $this->defaultOutputDisk());
    }

    /**
     * Get the image a watermark reads, edited on a private copy when it has edits.
     *
     * @param  list<Transformation>  $edits
     *
     * @internal
     */
    public function forWatermark(array $edits): self
    {
        return $edits === [] ? $this : new self($this->defaultOutputDisk(), new PreparedImageInput($this, $edits));
    }

    /**
     * Begin exporting a single frame at the given time.
     */
    public function frame(float|string $at = 0): PendingFrame
    {
        return new PendingFrame($this, $this->defaultOutputDisk(), $at);
    }

    /**
     * Begin exporting frames at an interval or as an evenly spaced count.
     */
    public function frames(?float $every = null, ?int $count = null): PendingFrames
    {
        return new PendingFrames($this, $this->defaultOutputDisk(), $every, $count);
    }

    /**
     * Begin exporting storyboard sprite sheets of frames taken at the given interval.
     */
    public function storyboard(
        float $every,
        int $columns = 5,
        int $rows = 5,
        int $margin = 0,
        int $padding = 0,
    ): PendingStoryboard {
        return new PendingStoryboard($this, $this->defaultOutputDisk(), $every, $columns, $rows, $margin, $padding);
    }

    /**
     * Begin exporting a waveform image of the audio.
     */
    public function waveform(int $width = 640, int $height = 120, string|array $colors = ['blue']): PendingWaveform
    {
        return new PendingWaveform($this, $this->defaultOutputDisk(), $width, $height, $colors);
    }

    /**
     * Begin exporting the waveform peaks of the audio as data.
     */
    public function waveformPeaks(int $samplesPerBucket = 256): PendingWaveformPeaks
    {
        return new PendingWaveformPeaks($this, $this->defaultOutputDisk(), $samplesPerBucket);
    }

    /**
     * Begin exporting the media with EBU R128 loudness normalization.
     */
    public function normalizeLoudness(
        float $integrated = -23,
        float $truePeak = -2,
        float $range = 7,
        bool $linear = true,
        int $sampleRate = 48000,
        int $audio = 0,
    ): PendingLoudness {
        return new PendingLoudness(
            $this,
            $this->defaultOutputDisk(),
            $integrated,
            $truePeak,
            $range,
            $linear,
            $sampleRate,
            $audio,
        );
    }

    /**
     * Get the disk that outputs of this media are written to by default.
     *
     * @internal
     */
    public function defaultOutputDisk(): string|Filesystem
    {
        // A concat descriptor is created on its first input's output disk, so its own
        // disk is already the right default for its outputs.
        return is_string($this->source)
            || $this->source instanceof SequenceInput
            || $this->source instanceof PreparedImageInput
            || $this->source instanceof ConcatInput ? $this->disk : config('filesystems.default');
    }

    /**
     * Get a stable hash that identifies the disk, source and input options.
     *
     * @internal
     */
    public function identity(): string
    {
        return hash('sha256', serialize([
            is_string($this->disk) ? ['name', $this->disk] : ['object', spl_object_id($this->disk)],
            $this->source instanceof InputSource ? ['source', $this->source->identity()]
                : (is_string($this->source) ? ['key', $this->source] : ['file', $this->source->getPathname()]),
            $this->options,
        ]));
    }

    /**
     * Get the normalized file extension of the source, or an empty string.
     *
     * @internal
     */
    public function extension(): string
    {
        $extension = $this->source instanceof InputSource ? $this->source->extension()
            : pathinfo(is_string($this->source) ? $this->source : $this->source->getPathname(), PATHINFO_EXTENSION);

        return preg_match('/\A[a-z0-9]{1,32}\z/i', $extension) ? strtolower($extension) : '';
    }

    /**
     * Get the validated FFmpeg input options.
     *
     * @internal
     */
    public function inputOptions(): array
    {
        return $this->options;
    }

    /**
     * Get the FFmpeg protocol whitelist for the staged source.
     *
     * @internal
     */
    public function protocolWhitelist(): string
    {
        return $this->source instanceof InputSource ? $this->source->protocols() : 'file';
    }

    /**
     * Get the FFmpeg transport options of the source.
     *
     * @internal
     */
    public function transportOptions(): array
    {
        return $this->source instanceof InputSource ? $this->source->options() : [];
    }

    /**
     * Get the secret values to replace in commands and diagnostics.
     *
     * @internal
     */
    public function redactions(): array
    {
        return $this->source instanceof InputSource ? $this->source->redactions() : [];
    }

    /**
     * Probe the media and get its metadata.
     */
    public function probe(): Metadata
    {
        return $this->withInput(fn (string $path, Workspace $workspace): Metadata => $workspace->probe($this));
    }

    /**
     * Probe the media and cache its metadata under the given versioned key.
     *
     * The key must change whenever the source bytes or object version change.
     */
    public function probeCached(
        Cache $cache,
        string $versionedKey,
        DateTimeInterface|DateInterval|int $ttl = 3600,
    ): Metadata {
        if ($versionedKey === '') {
            throw new InvalidArgumentException('The probe cache key must not be empty.');
        }

        if (is_int($ttl) && $ttl < 1) {
            throw new InvalidArgumentException("The probe cache TTL [{$ttl}] must be positive.");
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

    /**
     * Run the given audio filter over an audio track and get the process result.
     */
    public function analyze(string $audioFilter = 'volumedetect', int $audio = 0): ProcessResult
    {
        FilterGraph::validateExpression($audioFilter);
        if ($audio < 0) {
            throw new InvalidArgumentException("The audio track index [{$audio}] must not be negative.");
        }

        return $this->withInput(function (string $path, Workspace $workspace) use (
            $audioFilter,
            $audio,
        ): ProcessResult {
            $runner = $workspace->runner;
            $binary = config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg');
            $runner->version($binary, 'ffmpeg', $workspace->path, Progress::ANALYSIS);
            $workspace->requireProtocols($binary, $this->protocolWhitelist(), Progress::ANALYSIS);

            return $runner->run(
                [
                    $binary, '-hide_banner', '-nostdin', '-nostats', '-xerror', '-loglevel', 'info',
                    '-protocol_whitelist', $this->protocolWhitelist(), ...$this->transportOptions(),
                    ...$this->inputOptions(), '-i', $path,
                    '-map', "0:a:{$audio}", '-vn', '-sn', '-dn', '-af', $audioFilter, '-f', 'null', '-',
                ],
                $workspace->path,
                [$path => '[input]'] + $this->redactions(),
                phase: Progress::ANALYSIS,
                redactOutput: true,
            );
        });
    }

    /**
     * Stage the media in a new workspace, run the operation, and delete the workspace.
     *
     * @internal
     */
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
                if (! $failure instanceof Throwable) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Get the FFprobe arguments for this input.
     *
     * These are its transport options and the input options that affect probing.
     *
     * @internal
     */
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

    /**
     * Probe this input at the path the workspace staged it to.
     *
     * @internal
     */
    public function probeInput(string $path, Workspace $workspace, ?string $selector = null): Metadata
    {
        $protocols = $this->protocolWhitelist();
        $workspace->requireProtocols(config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'), $protocols);
        $data = $this->runProbe($workspace, $path, '[input]', $protocols,
            [...$this->probeOptions(), ...($selector === null ? [] : ['-select_streams', $selector])]);

        // We replace the probed filename so the metadata never exposes temporary paths
        // or credential-bearing URLs.
        $data['format']['filename'] = $this->source instanceof InputSource ? $this->source->label()
            : (is_string($this->source) ? $this->source : $this->source->getPathname());

        return new Metadata($data, $this->identity());
    }

    /**
     * Probe a file generated from this input.
     *
     * It is always a local file read without the input's transport options, and its streams
     * carry no source identity.
     *
     * @internal
     */
    public function probeArtifact(string $path, Workspace $workspace): Metadata
    {
        $data = $this->runProbe($workspace, $path, '[output]', 'file', []);
        $data['format']['filename'] = basename($path);

        return new Metadata($data);
    }

    /**
     * Apply the process redactions to every decoded probe string.
     *
     * This runs after JSON decoding so escaped values are covered too.
     *
     * @internal
     */
    public static function redactProbeData(array $data, array $redactions): array
    {
        array_walk_recursive($data, static function (&$value) use ($redactions): void {
            if (is_string($value)) {
                $value = strtr($value, $redactions);
            }
        });

        return $data;
    }

    /**
     * Run FFprobe on the given path and get its validated, redacted output.
     */
    protected function runProbe(
        Workspace $workspace,
        string $path,
        string $label,
        string $protocols,
        array $options,
    ): array {
        $redactions = [$path => $label] + $this->redactions();
        $result = $workspace->runner->run([
            config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'), '-v', 'error', '-print_format', 'json',
            '-show_format', '-show_streams', '-show_chapters', '-protocol_whitelist', $protocols, ...$options, $path,
        ], $workspace->path, $redactions);

        if ($result->exitCode !== 0) {
            throw MediaException::probing("FFprobe failed (exit {$result->exitCode}).", 'exit', $result->diagnostics);
        }

        try {
            $data = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw MediaException::probing('FFprobe returned invalid JSON.', 'metadata');
        }

        if (! $this->isProbeData($data)) {
            throw MediaException::probing('FFprobe returned invalid metadata.', 'metadata');
        }

        return self::redactProbeData($data, $redactions);
    }

    /**
     * Determine if the given value has the shape of FFprobe output.
     */
    protected function isProbeData(mixed $data): bool
    {
        return is_array($data) && isset($data['format'], $data['streams']) && is_array($data['format'])
            && is_array($data['streams']) && array_is_list($data['streams'])
            && ! array_any($data['streams'], fn ($stream): bool => ! is_array($stream));
    }

    /**
     * Stage the media in the given workspace and get the path FFmpeg reads.
     *
     * @internal
     */
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
                throw MediaException::staging(
                    'Remote manifest dependencies cannot be staged as a single file.',
                    'manifest',
                );
            }

            $path = $workspace->path.DIRECTORY_SEPARATOR.$name;
            $output = @fopen($path, 'xb');

            if ($output === false || ! chmod($path, 0600)) {
                throw MediaException::staging('Cannot create the staged media file.', 'filesystem');
            }

            $expectedBytes = $disk->size($this->source);
            $input = $disk->readStream($this->source);

            if (! is_resource($input)) {
                throw MediaException::staging('Cannot read the media input.', 'transfer');
            }

            $bytes = stream_copy_to_stream($input, $output);

            if ($bytes === false
                || $bytes !== $expectedBytes
                || ! feof($input)
                || ! fflush($output)
                || fstat($output)['size'] !== $bytes) {
                throw MediaException::staging('The media input transfer did not complete.', 'transfer');
            }

            if (! $resource) {
                self::rejectManifestContent($path);
            }

            return $path;
        } catch (Throwable $throwable) {
            if ($throwable instanceof MediaException) {
                throw $throwable;
            }

            throw MediaException::staging('Cannot stage the media input.', 'transfer');
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }

            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    /**
     * Ensure a staged file's content is not a manifest, whatever its extension.
     *
     * @internal
     */
    public static function rejectManifestContent(string $path): void
    {
        $prefix = file_get_contents($path, false, null, 0, 4096);

        if (preg_match('/^(?:\xEF\xBB\xBF)?\s*(?:#EXTM3U|ffconcat\s)|<(?:\w+:)?MPD(?:\s|>)/i', $prefix)) {
            throw MediaException::staging(
                'Remote manifest dependencies cannot be staged as a single file.',
                'manifest',
            );
        }
    }

    /**
     * Get the real path of a readable local file.
     */
    protected function absoluteLocalPath(string $path): string
    {
        $absolute = realpath($path);

        if ($absolute === false || ! is_file($absolute) || ! is_readable($absolute)) {
            throw MediaException::staging('Cannot read the local media input.', 'filesystem');
        }

        return $absolute;
    }
}

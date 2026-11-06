<?php

namespace ProtoneMedia\LaravelFFMpeg;

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
use ProtoneMedia\LaravelFFMpeg\Execution\ProcessRunner;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use Throwable;

readonly class Media
{
    private string|File|UploadedFile $source;

    private array $options;

    public function __construct(private string|Filesystem $disk, string|File|UploadedFile $source, array $inputOptions = [])
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

    /** @internal */
    public function defaultOutputDisk(): string|Filesystem
    {
        return is_string($this->source) ? $this->disk : config('filesystems.default');
    }

    /** @internal */
    public function identity(): string
    {
        return hash('sha256', serialize([
            is_string($this->disk) ? ['name', $this->disk] : ['object', spl_object_id($this->disk)],
            is_string($this->source) ? ['key', $this->source] : ['file', $this->source->getPathname()],
            $this->options,
        ]));
    }

    /** @internal */
    public function extension(): string
    {
        $extension = pathinfo(is_string($this->source) ? $this->source : $this->source->getPathname(), PATHINFO_EXTENSION);

        return preg_match('/\A[a-z0-9]{1,32}\z/i', $extension) ? strtolower($extension) : '';
    }

    /** @internal */
    public function inputOptions(): array
    {
        return $this->options;
    }

    public function probe(): Metadata
    {
        return $this->withInput(function (string $path, Workspace $workspace) {
            return $workspace->probe($this);
        });
    }

    /** @internal */
    public function withInput(callable $operation): mixed
    {
        $workspace = new Workspace;
        $failure = null;

        try {
            $path = $workspace->stage($this);

            return $operation($path, $workspace);
        } catch (Throwable $exception) {
            $failure = $exception;

            throw $exception;
        } finally {
            try {
                $workspace->delete();
            } catch (Throwable $exception) {
                if ($failure === null) {
                    throw $exception;
                }
            }
        }
    }

    /** @internal */
    public function probePath(string $path, Workspace $workspace, ?string $selector = null): Metadata
    {
        $options = [];

        for ($i = 0; $i < count($this->options); $i += 2) {
            if ($this->options[$i] !== '-thread_queue_size') {
                array_push($options, $this->options[$i], $this->options[$i + 1]);
            }
        }

        if ($selector !== null) {
            array_push($options, '-select_streams', $selector);
        }

        $result = (new ProcessRunner)->run([
            config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'), '-v', 'error', '-print_format', 'json',
            '-show_format', '-show_streams', '-show_chapters', '-protocol_whitelist', 'file', ...$options, $path,
        ], $workspace->path, [$path => '[input]']);

        if ($result->exitCode !== 0) {
            throw new MediaException('FFprobe failed (exit '.$result->exitCode.').', 'probing', 'exit', $result->diagnostics);
        }

        try {
            $data = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MediaException('FFprobe returned invalid JSON.', 'probing', 'metadata');
        }

        if (! is_array($data) || ! isset($data['format'], $data['streams'])
            || ! is_array($data['format']) || ! is_array($data['streams']) || ! array_is_list($data['streams'])
            || array_any($data['streams'], fn ($stream) => ! is_array($stream))) {
            throw new MediaException('FFprobe returned invalid metadata.', 'probing', 'metadata');
        }

        // Do not return a pathname that disappears when staging is released.
        $data['format']['filename'] = is_string($this->source) ? $this->source : $this->source->getPathname();

        return new Metadata($data, $this->identity());
    }

    /** @internal */
    public function stage(Workspace $workspace, string $name = 'input', bool $resource = false): string
    {
        if (! preg_match('/\A[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9]+)?\z/i', $name)) {
            throw new InvalidArgumentException('The staging name must be a safe generated filename.');
        }

        $input = $output = null;

        try {
            if (! is_string($this->source)) {
                return $this->absoluteLocalPath($this->source->getPathname());
            }

            $disk = is_string($this->disk) ? Storage::disk($this->disk) : $this->disk;

            if ($disk instanceof LocalFilesystemAdapter) {
                return $this->absoluteLocalPath($disk->path($this->source));
            }

            if (! $resource && in_array($this->extension(), ['m3u8', 'm3u', 'mpd', 'ffconcat'])) {
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

            $prefix = file_get_contents($path, false, null, 0, 4096);

            if (! $resource && preg_match('/^(?:\xEF\xBB\xBF)?\s*(?:#EXTM3U|ffconcat\s)|<(?:\w+:)?MPD(?:\s|>)/i', $prefix)) {
                throw new MediaException('Remote manifest dependencies cannot be staged as a single file.', 'staging', 'manifest');
            }

            return $path;
        } catch (Throwable $exception) {
            if ($exception instanceof MediaException) {
                throw $exception;
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

    private function absoluteLocalPath(string $path): string
    {
        $absolute = realpath($path);

        if ($absolute === false || ! is_file($absolute) || ! is_readable($absolute)) {
            throw new MediaException('Cannot read the local media input.', 'staging', 'filesystem');
        }

        return $absolute;
    }
}

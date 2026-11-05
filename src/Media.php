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
use ProtoneMedia\LaravelFFMpeg\Execution\ProcessRunner;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use Throwable;

readonly class Media
{
    private string|File|UploadedFile $source;

    public function __construct(private string|Filesystem $disk, string|File|UploadedFile $source)
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
    }

    public function probe(): Metadata
    {
        $workspace = new Workspace;
        $failure = null;

        try {
            $path = $this->localPath($workspace);
            $runner = new ProcessRunner;
            $binary = config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe');
            $version = $runner->run([$binary, '-version'], $workspace->path, outputLimit: 65536);

            if ($version->exitCode !== 0 || ! preg_match('/^ffprobe version (?:n)?9\.0\.\d+(?:\s|-|$)/', $version->output)) {
                throw new MediaException('FFprobe 9.0.x is required.', 'probing', 'version');
            }

            $result = $runner->run([
                $binary, '-v', 'error', '-print_format', 'json',
                '-show_format', '-show_streams', '-show_chapters', $path,
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

            return new Metadata($data);
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

    private function localPath(Workspace $workspace): string
    {
        if (! is_string($this->source)) {
            return $this->source->getPathname();
        }

        $input = $output = null;

        try {
            $disk = is_string($this->disk) ? Storage::disk($this->disk) : $this->disk;

            if ($disk instanceof LocalFilesystemAdapter) {
                return $disk->path($this->source);
            }

            if (in_array(strtolower(pathinfo($this->source, PATHINFO_EXTENSION)), ['m3u8', 'm3u', 'mpd', 'ffconcat'])) {
                throw new MediaException('Remote manifest dependencies cannot be staged as a single file.', 'staging', 'manifest');
            }

            $path = $workspace->path.DIRECTORY_SEPARATOR.'input';
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

            if (preg_match('/^(?:\xEF\xBB\xBF)?\s*(?:#EXTM3U|ffconcat\s)|<(?:\w+:)?MPD(?:\s|>)/i', $prefix)) {
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
}

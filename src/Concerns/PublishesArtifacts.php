<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Events;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\ProcessRunner;
use ProtoneMedia\LaravelFFMpeg\GeneratedFile;
use ProtoneMedia\LaravelFFMpeg\Progress;
use RuntimeException;
use Throwable;

/**
 * Publishes the artifacts of an export and reports its progress and failures.
 */
trait PublishesArtifacts
{
    /**
     * Publish every artifact to its destination, tracking each published destination.
     *
     * @return list<GeneratedFile>
     */
    protected function publishArtifacts(ProcessRunner $runner, array $artifacts, array $outputs, array $sizes): array
    {
        $files = [];

        foreach ($artifacts as $index => $artifact) {
            $runner->check(Progress::PUBLICATION);

            $output = $artifact['target'] ?? $outputs[$artifact['output']];
            $destination = $artifact['destination'];
            $disk = $output->filesystem();
            $stream = null;

            try {
                $disk = is_string($disk) ? Storage::disk($disk) : $disk;
                $stream = fopen($artifact['path'], 'rb');

                if ($stream === false || ! $disk->put($destination['path'], $stream, $output->storageOptions())) {
                    throw new RuntimeException('Publication failed.');
                }

                $this->published[] = $destination;

                if ($disk->size($destination['path']) !== $sizes[$index]) {
                    throw new RuntimeException('Published size mismatch.');
                }
            } catch (Throwable) {
                throw new MediaException(
                    'Cannot publish the media output.',
                    $this->phase,
                    $this->isUnwritableLocalPath($disk, $destination['path']) ? 'permission' : 'transfer',
                    published: $this->published,
                    destination: $destination,
                );
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $files[] = new GeneratedFile(
                $disk,
                $destination['disk'],
                $destination['path'],
                $sizes[$index],
                role: $artifact['role'] ?? $this->outputRole,
            );
        }

        return $files;
    }

    /**
     * Ensure every local destination of the given outputs is writable before encoding starts.
     *
     * Remote disks are left to publication, since checking them would mean writing an object.
     */
    protected function ensureLocalDestinationsAreWritable(array $outputs): void
    {
        foreach ($outputs as $output) {
            $disk = $output->filesystem();
            $destination = $output->destination();

            if ($this->isUnwritableLocalPath(is_string($disk) ? Storage::disk($disk) : $disk, $destination['path'])) {
                throw MediaException::staging(
                    'The local destination is not writable.',
                    'permission',
                    destination: $destination,
                );
            }
        }
    }

    /**
     * Determine if the given path of a local disk cannot be written, judged by its nearest existing path.
     */
    protected function isUnwritableLocalPath(string|Filesystem $disk, string $path): bool
    {
        if (! $disk instanceof LocalFilesystemAdapter) {
            return false;
        }

        $target = $disk->path($path);

        // The disk creates missing directories while it writes, so the nearest path that
        // already exists, the file itself or one of its ancestors, decides if it may.
        while (! file_exists($target) && dirname($target) !== $target) {
            $target = dirname($target);
        }

        return ! is_writable($target);
    }

    /**
     * Dispatch the failed or cancelled event and return the exception to rethrow.
     */
    protected function reportFailure(Throwable $throwable, string $operationId, array $destinations): Throwable
    {
        if ($throwable instanceof MediaException
            && in_array($throwable->category, ['timeout', 'interrupted'], true)
            && $this->published !== []) {
            $throwable = new MediaException($throwable->getMessage(), $throwable->phase, $throwable->category,
                $throwable->diagnostics, published: $this->published);
        }

        $event = $throwable instanceof ExportCancelled ? Events\ExportCancelled::class : Events\ExportFailed::class;

        try {
            if (config('laravel-ffmpeg.enable_events', true)) {
                Event::dispatch(new $event(
                    $operationId,
                    $throwable instanceof MediaException ? $throwable->phase : $this->phase,
                    $destinations,
                    $this->published,
                ));
            }
        } catch (Throwable) {
            // A failing listener must not hide the original process or storage failure.
        }

        return $throwable;
    }

    /**
     * Cancel the execution when requested, then report the progress to the callback and listeners.
     */
    protected function notify(Progress $progress, string $operationId, array $outputs): void
    {
        if ($this->cancellation instanceof Closure && ($this->cancellation)()) {
            throw new ExportCancelled;
        }

        if ($this->progress instanceof Closure) {
            ($this->progress)($progress);
        }

        if (config('laravel-ffmpeg.enable_events', true)) {
            Event::dispatch(new Events\ExportProgress($operationId, $progress, $outputs));
        }
    }
}

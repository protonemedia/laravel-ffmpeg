<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use Closure;
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
            $stream = null;

            try {
                $disk = $output->filesystem();
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
                    'transfer',
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
     * Dispatch the failed or cancelled event and return the exception to rethrow.
     */
    protected function reportFailure(Throwable $throwable, string $operationId, array $destinations): Throwable
    {
        if ($throwable instanceof MediaException && $throwable->category === 'timeout' && $this->published !== []) {
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

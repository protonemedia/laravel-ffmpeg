<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use Illuminate\Contracts\Filesystem\Filesystem;
use ProtoneMedia\LaravelFFMpeg\PendingQueuedExport;

/**
 * Forward running, inspecting, timing out, queueing and publishing to the pending export the builder holds in $export.
 *
 * @internal
 */
trait RunsExport
{
    /**
     * Create a pending queued export that runs the builder on a queue worker.
     */
    public function queued(
        ?string $connection = null,
        ?string $queue = null,
        \DateTimeInterface|\DateInterval|int|null $delay = null,
        bool $parallel = false,
    ): PendingQueuedExport {
        return $this->export->queued($connection, $queue, $delay, $parallel);
    }

    /**
     * Set the total time budget of the execution in seconds.
     *
     * Passing null restores the configured process limits.
     */
    public function timeout(?float $seconds): self
    {
        $this->export->timeout($seconds);

        return $this;
    }

    /**
     * Set how many seconds a process may run without output.
     */
    public function idleTimeout(?float $seconds): self
    {
        $this->export->idleTimeout($seconds);

        return $this;
    }

    /**
     * Cancel the execution when the given callback returns true.
     *
     * Queue workers use this for batch cancellation, independent of the user's progress callback.
     *
     * @internal
     */
    public function cancelWhen(callable $callback): self
    {
        $this->export->cancelWhen($callback);

        return $this;
    }

    /**
     * Register a callback that receives the progress of the execution.
     */
    public function onProgress(callable $callback): self
    {
        $this->export->onProgress($callback);

        return $this;
    }

    /**
     * Get the redacted command without creating files or running processes.
     */
    public function inspect(): array
    {
        return $this->export->inspect();
    }

    /**
     * Set the disk the output is published to.
     */
    public function toDisk(string|Filesystem $disk, array $options = []): self
    {
        $this->export->toDisk($disk, $options);

        return $this;
    }

    /**
     * Set the visibility of the published output.
     */
    public function visibility(string $visibility): self
    {
        $this->export->visibility($visibility);

        return $this;
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use Illuminate\Contracts\Filesystem\Filesystem;
use ProtoneMedia\LaravelFFMpeg\PendingQueuedExport;

/** @internal Run, inspect, time out, queue and publish the export. Forwards to the PendingExport the builder holds in $export. */
trait RunsExport
{
    public function queued(
        ?string $connection = null,
        ?string $queue = null,
        \DateTimeInterface|\DateInterval|int|null $delay = null,
        bool $parallel = false,
    ): PendingQueuedExport {
        return $this->export->queued($connection, $queue, $delay, $parallel);
    }

    /** A cumulative budget for this execution; null restores the configured process limits. */
    public function timeout(?float $seconds): self
    {
        $this->export->timeout($seconds);

        return $this;
    }

    public function idleTimeout(?float $seconds): self
    {
        $this->export->idleTimeout($seconds);

        return $this;
    }

    /** @internal Worker batch cancellation is independent of user progress callbacks. */
    public function cancelWhen(callable $callback): self
    {
        $this->export->cancelWhen($callback);

        return $this;
    }

    public function onProgress(callable $callback): self
    {
        $this->export->onProgress($callback);

        return $this;
    }

    /** Pure, redacted inspection. No files or processes are created. */
    public function inspect(): array
    {
        return $this->export->inspect();
    }

    public function toDisk(string|Filesystem $disk, array $options = []): self
    {
        $this->export->toDisk($disk, $options);

        return $this;
    }

    public function visibility(string $visibility): self
    {
        $this->export->visibility($visibility);

        return $this;
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

use ProtoneMedia\LaravelFFMpeg\Result;

/**
 * A builder the queue can capture, restore in a worker, and run.
 *
 * @internal
 */
interface Exportable
{
    /**
     * Run the export and publish its outputs.
     *
     * @return Result
     */
    public function save(?string $path = null);

    /**
     * Set the callback a queue worker uses to cancel the export.
     *
     * This is separate from the progress callbacks the application registers.
     */
    public function cancelWhen(callable $callback): static;

    /**
     * Interrupt the running export with the signal a queue worker received.
     *
     * Terminating also stops the running process and deletes the workspace before returning.
     */
    public function interrupt(int $signal, bool $terminate = false): void;
}

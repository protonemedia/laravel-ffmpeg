<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/** @internal A builder the queue can capture, restore in a worker, and run. */
interface Exportable
{
    /** @return \ProtoneMedia\LaravelFFMpeg\Result */
    public function save(?string $path = null);

    /** Worker batch cancellation, independent of user progress callbacks. */
    public function cancelWhen(callable $callback): self;
}

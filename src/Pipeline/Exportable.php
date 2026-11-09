<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

use ProtoneMedia\LaravelFFMpeg\Result;

/** @internal A builder the queue can capture, restore in a worker, and run. */
interface Exportable
{
    /** @return Result */
    public function save(?string $path = null);

    /** Worker batch cancellation, independent of user progress callbacks. */
    public function cancelWhen(callable $callback): self;
}

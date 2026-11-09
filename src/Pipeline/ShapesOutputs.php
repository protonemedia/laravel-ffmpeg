<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/** @internal Adjusts the outputs PendingExport derives from root settings or output() callbacks. */
interface ShapesOutputs
{
    public function outputs(array $default, ?string $path): array;
}

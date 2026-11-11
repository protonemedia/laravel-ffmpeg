<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/**
 * Adjusts the outputs PendingExport derives from root settings or output() callbacks.
 *
 * @internal
 */
interface ShapesOutputs
{
    /**
     * Get the outputs to compile in place of the derived defaults.
     */
    public function outputs(array $default, ?string $path): array;
}

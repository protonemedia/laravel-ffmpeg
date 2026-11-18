<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/**
 * Transforms the compiled FFmpeg command, for inspection and execution alike.
 *
 * @internal
 */
interface ShapesCommand
{
    /**
     * Get the command to run in place of the compiled default.
     */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array;
}

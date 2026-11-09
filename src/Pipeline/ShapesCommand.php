<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/** @internal Transforms the compiled FFmpeg command, for inspection and execution alike. */
interface ShapesCommand
{
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array;
}

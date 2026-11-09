<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/**
 * Chooses private encoder output paths and turns them into the artifacts to validate and publish.
 *
 * @internal
 */
interface PackagesArtifacts
{
    /**
     * Get the private paths the encoder writes each output to.
     */
    public function outputPaths(array $default, array $outputs, Workspace $workspace): array;

    /**
     * Get the artifacts to validate and publish from the encoded paths.
     */
    public function artifacts(array $default, array $outputs, array $paths): array;
}

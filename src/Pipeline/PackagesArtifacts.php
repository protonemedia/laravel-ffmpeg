<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/** @internal Chooses private encoder output paths and turns them into the artifacts to validate and publish. */
interface PackagesArtifacts
{
    public function outputPaths(array $default, array $outputs, Workspace $workspace): array;

    public function artifacts(array $default, array $outputs, array $paths): array;
}

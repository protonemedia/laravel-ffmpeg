<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/**
 * Adds checks after PendingExport has verified that an artifact is a nonempty file.
 *
 * @internal
 */
interface ValidatesArtifacts
{
    /**
     * Ensure the given artifact is valid for its role.
     */
    public function artifact(string $path, string $role, int $size): void;
}

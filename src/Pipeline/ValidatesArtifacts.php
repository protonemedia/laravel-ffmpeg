<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/** @internal Adds checks after PendingExport has verified that an artifact is a nonempty file. */
interface ValidatesArtifacts
{
    public function artifact(string $path, string $role, int $size): void;
}

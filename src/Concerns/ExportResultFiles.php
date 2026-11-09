<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use Illuminate\Support\Collection;
use ProtoneMedia\LaravelFFMpeg\GeneratedFile;

/**
 * Forward the generated files and process summaries to the export result the result holds in $export.
 *
 * @internal
 */
trait ExportResultFiles
{
    /**
     * Get the generated files.
     *
     * @return Collection<int, GeneratedFile>
     */
    public function files(): Collection
    {
        return $this->export->files();
    }

    /**
     * Get the one generated file that matches the given path and disk.
     */
    public function file(?string $path = null, ?string $disk = null): GeneratedFile
    {
        return $this->export->file($path, $disk);
    }

    /**
     * Get the summaries of the FFmpeg processes the export ran.
     */
    public function processes(): Collection
    {
        return $this->export->processes();
    }
}

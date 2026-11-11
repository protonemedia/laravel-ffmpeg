<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Collection;

/**
 * The published files and process summaries of a completed export.
 */
interface Result
{
    /**
     * Get the published files.
     *
     * @return Collection<int, GeneratedFile>
     */
    public function files(): Collection;

    /**
     * Get the file with the given path and disk, or the first file when neither is given.
     */
    public function file(?string $path = null, ?string $disk = null): GeneratedFile;

    /**
     * Get the summaries of the processes the export ran.
     */
    public function processes(): Collection;
}

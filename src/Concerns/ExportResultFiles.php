<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use Illuminate\Support\Collection;
use ProtoneMedia\LaravelFFMpeg\GeneratedFile;

/** @internal Result files and process summaries. Forwards to the ExportResult the result holds in $export. */
trait ExportResultFiles
{
    /** @return Collection<int, GeneratedFile> */
    public function files(): Collection
    {
        return $this->export->files();
    }

    public function file(?string $path = null, ?string $disk = null): GeneratedFile
    {
        return $this->export->file($path, $disk);
    }

    public function processes(): Collection
    {
        return $this->export->processes();
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Collection;

readonly class ExportResult
{
    public function __construct(private array $generatedFiles, private array $summaries) {}

    /** @return Collection<int, GeneratedFile> */
    public function files(): Collection
    {
        return collect($this->generatedFiles);
    }

    public function file(): GeneratedFile
    {
        return $this->generatedFiles[0];
    }

    public function processes(): Collection
    {
        return collect($this->summaries);
    }
}

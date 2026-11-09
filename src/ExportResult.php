<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Collection;

readonly class ExportResult implements Result
{
    public function __construct(protected array $generatedFiles, protected array $summaries) {}

    /** @return Collection<int, GeneratedFile> */
    public function files(): Collection
    {
        return collect($this->generatedFiles);
    }

    public function file(?string $path = null, ?string $disk = null): GeneratedFile
    {
        if ($path === null && $disk === null) {
            return $this->generatedFiles[0];
        }

        return $this->files()->sole(fn (GeneratedFile $file): bool => ($path === null || $file->path === $path) && ($disk === null || $file->disk === $disk));
    }

    public function processes(): Collection
    {
        return collect($this->summaries);
    }
}

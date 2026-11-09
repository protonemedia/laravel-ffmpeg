<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Collection;

/**
 * The result of a completed export.
 */
class ExportResult implements Result
{
    /**
     * Create a new export result instance.
     */
    public function __construct(protected readonly array $generatedFiles, protected readonly array $summaries) {}

    /**
     * Get the published files.
     *
     * @return Collection<int, GeneratedFile>
     */
    public function files(): Collection
    {
        return collect($this->generatedFiles);
    }

    /**
     * Get the file with the given path and disk, or the first file when neither is given.
     */
    public function file(?string $path = null, ?string $disk = null): GeneratedFile
    {
        if ($path === null && $disk === null) {
            return $this->generatedFiles[0];
        }

        return $this->files()->sole(
            fn (GeneratedFile $file): bool => ($path === null || $file->path === $path)
                && ($disk === null || $file->disk === $disk),
        );
    }

    /**
     * Get the summaries of the processes the export ran.
     */
    public function processes(): Collection
    {
        return collect($this->summaries);
    }
}

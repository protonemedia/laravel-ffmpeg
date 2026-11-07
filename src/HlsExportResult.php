<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\ItemNotFoundException;

readonly class HlsExportResult extends ExportResult
{
    public function __construct(array $files, array $summaries, private array $variants = [])
    {
        parent::__construct($files, $summaries);
    }

    public function playlist(): GeneratedFile
    {
        return $this->files()->firstWhere('role', 'hls-master')
            ?? $this->files()->sole(fn ($file) => $file->role === 'hls-playlist');
    }

    public function variant(string $name): self
    {
        if (! isset($this->variants[$name])) {
            throw new ItemNotFoundException('Unknown HLS variant.');
        }

        $directory = dirname($this->variants[$name]).'/';

        return new self($this->files()->filter(fn ($file) => str_starts_with($file->path, $directory))->values()->all(), $this->processes()->all());
    }
}

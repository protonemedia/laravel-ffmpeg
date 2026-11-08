<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Collection;
use Illuminate\Support\ItemNotFoundException;

readonly class DashExportResult extends ExportResult
{
    public function __construct(array $files, array $summaries, private array $representations = [], private array $sets = [])
    {
        parent::__construct($files, $summaries);
    }

    public function manifest(): GeneratedFile
    {
        return $this->files()->sole(fn ($file) => $file->role === 'dash-manifest');
    }

    public function hlsPlaylist(): GeneratedFile
    {
        return $this->files()->sole(fn ($file) => $file->role === 'hls-master');
    }

    /** Exact owned initialization, media, and optional shared HLS playlist files. */
    public function representation(string $name): ExportResult
    {
        if (! isset($this->representations[$name])) {
            throw new ItemNotFoundException('Unknown DASH representation.');
        }

        return new ExportResult($this->files()->filter(fn ($file) => isset($this->representations[$name][$file->path]))->values()->all(), $this->processes()->all());
    }

    /** @return Collection<string, array{type: string, representations: array}> */
    public function adaptationSets(): Collection
    {
        return collect($this->sets);
    }
}

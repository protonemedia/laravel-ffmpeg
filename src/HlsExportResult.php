<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\ItemNotFoundException;

readonly class HlsExportResult extends ExportResult
{
    public function __construct(array $files, array $summaries, private array $variants = [], private array $renditions = [])
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

        return new self($this->files()->filter(fn ($file) => isset($this->variants[$name][$file->path]))->values()->all(), $this->processes()->all());
    }

    public function audioRendition(string $group, string $name): self
    {
        return $this->rendition('AUDIO', $group, $name);
    }

    public function subtitleRendition(string $group, string $name): self
    {
        return $this->rendition('SUBTITLES', $group, $name);
    }

    private function rendition(string $type, string $group, string $name): self
    {
        $id = $this->renditions[$type][$group][$name] ?? null;
        if ($id === null) {
            throw new ItemNotFoundException('Unknown HLS rendition.');
        }

        return $this->variant($id);
    }
}

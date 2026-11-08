<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\ItemNotFoundException;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;

readonly class HlsExportResult extends ExportResult
{
    public function __construct(array $files, array $summaries, private array $variants = [], private array $renditions = [], private array $keyReferences = [], private array $playbackKeys = [])
    {
        parent::__construct($files, $summaries);
    }

    public function playlist(): GeneratedFile
    {
        return $this->files()->firstWhere('role', 'hls-master')
            ?? $this->files()->sole(fn ($file) => $file->role === 'hls-playlist');
    }

    public function playback(): HlsPresentation
    {
        $playlist = $this->playlist();

        return new HlsPresentation(
            [...$this->files()->map(fn ($file) => $file->toArray())->all(), ...$this->playbackKeys],
            HlsPresentation::id($playlist->disk ?? '', $playlist->path), $this->keyReferences,
        );
    }

    public function variant(string $name): self
    {
        if (! isset($this->variants[$name])) {
            throw new ItemNotFoundException('Unknown HLS variant.');
        }

        $files = $this->files()->filter(fn ($file) => isset($this->variants[$name][$file->path]))->values();
        $references = array_intersect_key($this->keyReferences, array_flip($files->pluck('path')->all()));
        $needed = [];
        foreach ($references as $urls) {
            foreach ($urls as $key) {
                $needed[HlsPresentation::id($key['disk'] ?? '', $key['path'])] = true;
            }
        }
        $keys = $this->files()->filter(fn ($file) => $file->role === 'hls-key'
            && isset($needed[HlsPresentation::id($file->disk ?? '', $file->path)]))->map(fn ($file) => $file->toArray())->values()->all();

        return new self($files->all(), $this->processes()->all(), keyReferences: $references, playbackKeys: $keys);
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

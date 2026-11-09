<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\ItemNotFoundException;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;

readonly class HlsExportResult implements Result
{
    use Concerns\ExportResultFiles;

    protected ExportResult $export;

    public function __construct(array $files, array $summaries, protected array $variants = [], protected array $renditions = [], protected array $keyReferences = [], protected array $playbackKeys = [], protected array $variantGroups = [], protected array $provenance = [], protected ?HlsPresentation $presentation = null)
    {
        $this->export = new ExportResult($files, $summaries);
    }

    public function playlist(): GeneratedFile
    {
        return $this->files()->firstWhere('role', 'hls-master')
            ?? $this->files()->sole(fn ($file): bool => $file->role === 'hls-playlist');
    }

    public function playback(): HlsPresentation
    {
        if ($this->presentation instanceof HlsPresentation) {
            return $this->presentation;
        }

        $playlist = $this->playlist();
        $variants = [];
        foreach ($this->variants as $name => $paths) {
            if (array_any($this->renditions, fn ($groups): bool => array_any($groups, fn ($members): bool => in_array($name, $members, true)))) {
                continue;
            }

            foreach ($this->variantGroups[$name] ?? [] as $type => $group) {
                foreach ($this->renditions[$type][$group] ?? [] as $member) {
                    $paths += $this->variants[$member];
                }
            }

            $variants[$name] = $this->files()->filter(fn ($file): bool => isset($paths[$file->path]))
                ->map(fn ($file): string => HlsPresentation::id($file->disk ?? '', $file->path))->values()->all();
        }

        return new HlsPresentation(
            [...$this->files()->map(fn ($file) => $file->toArray())->all(), ...$this->playbackKeys],
            HlsPresentation::id($playlist->disk ?? '', $playlist->path), $this->keyReferences, $variants, provenance: $this->provenance,
        );
    }

    public function playbackForVariants(array $names): HlsPresentation
    {
        return $this->playback()->playbackForVariants($names);
    }

    public function variant(string $name): self
    {
        if (! isset($this->variants[$name])) {
            throw new ItemNotFoundException('Unknown HLS variant.');
        }

        $files = $this->files()->filter(fn ($file): bool => isset($this->variants[$name][$file->path]))->values();
        $references = array_intersect_key($this->keyReferences, array_flip($files->pluck('path')->all()));
        $needed = [];
        foreach ($references as $urls) {
            foreach ($urls as $key) {
                $needed[HlsPresentation::id($key['disk'] ?? '', $key['path'])] = true;
            }
        }

        $keys = $this->files()->filter(fn ($file): bool => $file->role === 'hls-key'
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

    protected function rendition(string $type, string $group, string $name): self
    {
        $id = $this->renditions[$type][$group][$name] ?? null;
        if ($id === null) {
            throw new ItemNotFoundException('Unknown HLS rendition.');
        }

        return $this->variant($id);
    }
}

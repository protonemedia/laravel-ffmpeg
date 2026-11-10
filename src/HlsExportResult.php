<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\ItemNotFoundException;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;

/**
 * The files and processes of a completed HLS export.
 */
class HlsExportResult implements Result
{
    use Concerns\ExportResultFiles;

    /**
     * The underlying export result.
     */
    protected readonly ExportResult $export;

    /**
     * Create a new HLS export result instance.
     */
    public function __construct(
        array $files,
        array $summaries,
        protected readonly array $variants = [],
        protected readonly array $renditions = [],
        protected readonly array $keyReferences = [],
        protected readonly array $playbackKeys = [],
        protected readonly array $variantGroups = [],
        protected readonly array $provenance = [],
        protected readonly ?HlsPresentation $presentation = null,
    ) {
        $this->export = new ExportResult($files, $summaries);
    }

    /**
     * Get the master playlist, or the single media playlist when there is no master.
     */
    public function playlist(): GeneratedFile
    {
        return $this->files()->firstWhere('role', 'hls-master')
            ?? $this->files()->sole(fn ($file): bool => $file->role === 'hls-playlist');
    }

    /**
     * Get the protected playback inventory of the export.
     */
    public function playback(): HlsPresentation
    {
        if ($this->presentation instanceof HlsPresentation) {
            return $this->presentation;
        }

        $playlist = $this->playlist();
        $variants = [];
        foreach ($this->variants as $name => $paths) {
            // Rendition members are not master entries of their own. Instead, we will add their files
            // to every variant that references their group so each variant owns everything it plays.
            if (array_any(
                $this->renditions,
                fn ($groups): bool => array_any($groups, fn ($members): bool => in_array($name, $members, true)),
            )) {
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
            HlsPresentation::id($playlist->disk ?? '', $playlist->path),
            $this->keyReferences,
            $variants,
            provenance: $this->provenance,
        );
    }

    /**
     * Get the protected playback inventory limited to the given variants.
     */
    public function playbackForVariants(array $names): HlsPresentation
    {
        return $this->playback()->playbackForVariants($names);
    }

    /**
     * Get the result limited to the files and keys of the given variant.
     */
    public function variant(string $name): self
    {
        if (! isset($this->variants[$name])) {
            throw new ItemNotFoundException('Unknown HLS variant.');
        }

        $files = $this->files()->filter(fn ($file): bool => isset($this->variants[$name][$file->path]))->values();
        $references = collect($this->keyReferences)->only($files->pluck('path'))->all();
        $needed = [];
        foreach ($references as $urls) {
            foreach ($urls as $key) {
                $needed[HlsPresentation::id($key['disk'] ?? '', $key['path'])] = true;
            }
        }

        $keys = $this->files()
            ->filter(fn ($file): bool => $file->role === 'hls-key'
                && isset($needed[HlsPresentation::id($file->disk ?? '', $file->path)]))
            ->map(fn ($file) => $file->toArray())
            ->values()
            ->all();

        return new self($files->all(), $this->processes()->all(), keyReferences: $references, playbackKeys: $keys);
    }

    /**
     * Get the result limited to the given audio rendition.
     */
    public function audioRendition(string $group, string $name): self
    {
        return $this->rendition('AUDIO', $group, $name);
    }

    /**
     * Get the result limited to the given subtitle rendition.
     */
    public function subtitleRendition(string $group, string $name): self
    {
        return $this->rendition('SUBTITLES', $group, $name);
    }

    /**
     * Get the result limited to the given rendition of the given type.
     */
    protected function rendition(string $type, string $group, string $name): self
    {
        $id = $this->renditions[$type][$group][$name] ?? null;
        if ($id === null) {
            throw new ItemNotFoundException('Unknown HLS rendition.');
        }

        return $this->variant($id);
    }
}

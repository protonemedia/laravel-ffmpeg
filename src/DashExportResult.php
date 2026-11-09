<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Collection;
use Illuminate\Support\ItemNotFoundException;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;

/**
 * The files and processes of a completed DASH export.
 */
readonly class DashExportResult implements Result
{
    use Concerns\ExportResultFiles;

    /**
     * The underlying export result.
     */
    protected ExportResult $export;

    /**
     * Create a new DASH export result instance.
     */
    public function __construct(
        array $files,
        array $summaries,
        protected array $representations = [],
        protected array $sets = [],
    ) {
        $this->export = new ExportResult($files, $summaries);
    }

    /**
     * Get the DASH manifest.
     */
    public function manifest(): GeneratedFile
    {
        return $this->files()->sole(fn ($file): bool => $file->role === 'dash-manifest');
    }

    /**
     * Get the shared HLS master playlist.
     */
    public function hlsPlaylist(): GeneratedFile
    {
        return $this->files()->sole(fn ($file): bool => $file->role === 'hls-master');
    }

    /**
     * Get the protected playback inventory of the sharedHls() output, without the DASH manifest.
     *
     * Each master entry is a variant named after its representation, and video variants include every audio rendition.
     */
    public function playback(): HlsPresentation
    {
        $master = $this->hlsPlaylist();
        $types = [];
        foreach ($this->sets as $set) {
            $types += array_fill_keys($set['representations'], $set['type']);
        }

        $audio = array_keys($types, 'audio', true);
        $variants = [];
        foreach ($types as $name => $type) {
            // Audio is a rendition group of the video entries, so it only becomes a master entry
            // of its own when the export has no video at all.
            if ($type === 'audio' && count($audio) !== count($types)) {
                continue;
            }

            $paths = $this->representations[$name];
            foreach ($type === 'video' ? $audio : [] as $rendition) {
                $paths += $this->representations[$rendition];
            }

            $variants[$name] = $this->files()->filter(fn ($file): bool => isset($paths[$file->path]))
                ->map(fn ($file): string => HlsPresentation::id($file->disk ?? '', $file->path))->values()->all();
        }

        return new HlsPresentation(
            $this->files()
                ->reject(fn ($file): bool => $file->role === 'dash-manifest')
                ->map->toArray()
                ->values()
                ->all(),
            HlsPresentation::id($master->disk ?? '', $master->path),
            variants: $variants,
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
     * Get the exact initialization, media and optional shared HLS playlist files of a representation.
     */
    public function representation(string $name): ExportResult
    {
        if (! isset($this->representations[$name])) {
            throw new ItemNotFoundException('Unknown DASH representation.');
        }

        return new ExportResult(
            $this->files()
                ->filter(fn ($file): bool => isset($this->representations[$name][$file->path]))
                ->values()
                ->all(),
            $this->processes()->all(),
        );
    }

    /**
     * Get the adaptation sets of the manifest.
     *
     * @return Collection<string, array{type: string, representations: array}>
     */
    public function adaptationSets(): Collection
    {
        return collect($this->sets);
    }
}

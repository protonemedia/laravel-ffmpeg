<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Collection;
use Illuminate\Support\ItemNotFoundException;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;

readonly class DashExportResult implements Result
{
    use Concerns\ExportResultFiles;

    protected ExportResult $export;

    public function __construct(array $files, array $summaries, protected array $representations = [], protected array $sets = [])
    {
        $this->export = new ExportResult($files, $summaries);
    }

    public function manifest(): GeneratedFile
    {
        return $this->files()->sole(fn ($file): bool => $file->role === 'dash-manifest');
    }

    public function hlsPlaylist(): GeneratedFile
    {
        return $this->files()->sole(fn ($file): bool => $file->role === 'hls-master');
    }

    /**
     * Protected playback inventory for sharedHls() output, without the DASH manifest. Each master
     * entry is a variant named after its representation; video variants include every audio rendition.
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
            // Audio is a rendition group of the video entries; it is a master entry only without video.
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

        return new HlsPresentation($this->files()->reject(fn ($file): bool => $file->role === 'dash-manifest')->map->toArray()->values()->all(),
            HlsPresentation::id($master->disk ?? '', $master->path), variants: $variants);
    }

    public function playbackForVariants(array $names): HlsPresentation
    {
        return $this->playback()->playbackForVariants($names);
    }

    /** Exact owned initialization, media, and optional shared HLS playlist files. */
    public function representation(string $name): ExportResult
    {
        if (! isset($this->representations[$name])) {
            throw new ItemNotFoundException('Unknown DASH representation.');
        }

        return new ExportResult($this->files()->filter(fn ($file): bool => isset($this->representations[$name][$file->path]))->values()->all(), $this->processes()->all());
    }

    /** @return Collection<string, array{type: string, representations: array}> */
    public function adaptationSets(): Collection
    {
        return collect($this->sets);
    }
}

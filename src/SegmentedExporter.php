<?php

namespace Pbmedia\LaravelFFMpeg;

class SegmentedExporter extends MediaExporter
{
    protected $filter;

    protected $playlistPath;

    protected $segmentLength = 10;

    protected $saveMethod = 'saveStream';

    public function setPlaylistPath(string $playlistPath): MediaExporter
    {
        $this->playlistPath = $playlistPath;

        return $this;
    }

    public function setSegmentLength(int $segmentLength): MediaExporter
    {
        $this->segmentLength = $segmentLength;

        return $this;
    }

    public function getFilter(): SegmentedFilter
    {
        if ($this->filter) {
            return $this->filter;
        }

        return $this->filter = new SegmentedFilter(
            $this->getPlaylistFullPath(),
            $this->segmentLength
        );
    }

    public function saveStream(string $playlistPath): MediaExporter
    {
        $this->setPlaylistPath($playlistPath);

        $this->media->addFilter(
            $this->getFilter()
        );

        $this->media->save(
            $this->getFormat(),
            $this->getSegmentFullPath()
        );

        return $this;
    }

    public function getPlaylistFullPath(): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            pathinfo($this->playlistPath, PATHINFO_DIRNAME),
            $this->getPlaylistFilename(),
        ]);
    }

    public function getSegmentFullPath(): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            pathinfo($this->playlistPath, PATHINFO_DIRNAME),
            $this->getSegmentFilename(),
        ]);
    }

    public function getPlaylistPath(): string
    {
        return $this->playlistPath;
    }

    public function getPlaylistName(): string
    {
        return pathinfo($this->getPlaylistPath(), PATHINFO_FILENAME);
    }

    public function getPlaylistFilename(): string
    {
        return $this->getFormattedFilename('.m3u8');
    }

    public function getSegmentFilename(): string
    {
        return $this->getFormattedFilename('_%05d.ts');
    }

    protected function getFormattedFilename(string $suffix = ''): string
    {
        return implode([
            $this->getPlaylistName(),
            '_',
            $this->getFormat()->getKiloBitrate(),
        ]) . $suffix;
    }
}

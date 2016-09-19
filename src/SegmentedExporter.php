<?php

namespace Pbmedia\LaravelFFMpeg;

class SegmentedExporter extends MediaExporter
{
    protected $filter;

    protected $playlistPath;

    protected $saveMethod = 'saveStream';

    protected $segmentLength = 10;

    public function setPlaylistPath(string $playlistPath)
    {
        $this->playlistPath = $playlistPath;

        return $this;
    }

    public function getFilter(): SegmentedFilter
    {
        if ($this->filter) {
            return $this->filter;
        }

        return $this->filter = new SegmentedFilter(
            $this->playlistPath,
            $this->segmentLength
        );
    }

    public function setSegmentLength(int $segmentLength)
    {
        $this->segmentLength = $segmentLength;

        return $this;
    }

    public function saveStream(string $playlistPath): MediaExporter
    {
        $this->setPlaylistPath($playlistPath);

        $this->media->addFilter(
            $this->getFilter()
        );

        $this->media->save(
            $this->getFormat(),
            $this->getFullPath()
        );

        return $this;
    }

    protected function getFullPath(): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            pathinfo($this->playlistPath, PATHINFO_DIRNAME),
            $this->getFilename(),
        ]);
    }

    protected function getFilename(): string
    {
        return implode('_', [
            pathinfo($this->playlistPath, PATHINFO_FILENAME),
            $this->format->getKiloBitrate() . 'k',
            '%05d.ts',
        ]);
    }
}

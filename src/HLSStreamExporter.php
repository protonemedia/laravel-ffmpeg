<?php

namespace Pbmedia\LaravelFFMpeg;

class HLSStreamExporter extends MediaExporter
{
    protected $filter;

    protected $saveMethod = 'saveStream';

    protected $segmentLength = 10;

    public function getFilter(string $playlistPath): HLSStreamFilter
    {
        if ($this->filter) {
            return $this->filter;
        }

        return $this->filter = new HLSStreamFilter($playlistPath, $this->segmentLength);
    }

    public function setSegmentLength(int $segmentLength)
    {
        $this->segmentLength = $segmentLength;

        return $this;
    }

    public function saveStream(string $playlistPath): MediaExporter
    {
        $this->media->addFilter(
            $this->getFilter($playlistPath)
        );

        $this->media->save(
            $this->getFormat(),
            $this->getFullPath($playlistPath)
        );

        return $this;
    }

    protected function getFullPath(string $playlistPath): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            pathinfo($playlistPath, PATHINFO_DIRNAME),
            $this->getFilename($playlistPath),
        ]);
    }

    protected function getFilename(string $playlistPath): string
    {
        return implode('_', [
            pathinfo($playlistPath, PATHINFO_FILENAME),
            $this->format->getKiloBitrate() . 'k',
            '%05d.ts',
        ]);
    }
}

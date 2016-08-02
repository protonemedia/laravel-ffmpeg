<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Format\FormatInterface;

class MediaExporter
{
    protected $media;

    protected $disk;

    protected $format;

    public function __construct(Media $media)
    {
        $this->media = $media;
        $this->disk  = $media->getFile()->getDisk();
    }

    public function getFormat(): FormatInterface
    {
        return $this->format;
    }

    public function inFormat(FormatInterface $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function getDisk(): Disk
    {
        return $this->disk;
    }

    public function toDisk(Disk $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    public function save(string $path): Media
    {
        $file = $this->getDisk()->newFile($path);

        $this->media->save($this->getFormat(), $file->getFullPath());

        return $this->media;
    }
}

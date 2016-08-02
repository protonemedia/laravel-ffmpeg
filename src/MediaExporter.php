<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Format\FormatInterface;

class MediaExporter
{
    protected $media;

    protected $disk;

    protected $frameMustBeAccurate = false;

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

    public function toDisk(string $diskName): self
    {
        $this->disk = Disk::fromName($diskName);

        return $this;
    }

    public function accurate(): self
    {
        $this->frameMustBeAccurate = true;

        return $this;
    }

    public function unaccurate(): self
    {
        $this->frameMustBeAccurate = false;

        return $this;
    }

    public function getAccuracy(): bool
    {
        return $this->frameMustBeAccurate;
    }

    public function save(string $path): Media
    {
        $file = $this->getDisk()->newFile($path);

        if ($this->media->isFrame()) {
            $this->media->save($file->getFullPath(), $this->getAccuracy());
        } else {
            $this->media->save($this->getFormat(), $file->getFullPath());
        }

        return $this->media;
    }
}

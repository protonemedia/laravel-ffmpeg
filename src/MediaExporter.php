<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Format\FormatInterface;
use Pbmedia\LaravelFFMpeg\Drivers\DriverInterface;

class MediaExporter
{
    private DriverInterface $driver;

    private FormatInterface $format;

    protected ?string $visibility = null;

    private ?Disk $toDisk = null;

    public function __construct(DriverInterface $driver)
    {
        $this->driver = $driver;
    }

    private function getDisk(): Disk
    {
        if ($this->toDisk) {
            return $this->toDisk;
        }

        $media = $this->driver->getMediaCollection();

        if ($media->count() === 1) {
            return $media->first()->getDisk();
        }
    }

    public function inFormat(FormatInterface $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function toDisk($disk)
    {
        $this->toDisk = Disk::create($disk);

        return $this;
    }

    public function withVisibility(string $visibility)
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function save(string $path = null)
    {
        $disk = $this->getDisk();

        $this->driver->save(
            $this->format,
            $path ? $disk->getLocalPath($path) : null
        );

        if ($path && $disk && !$disk->isLocalDisk()) {
            $disk->copyAllFromTemporaryDirectory($this->visibility);
        }

        if ($this->visibility) {
            $disk->setVisibility($path, $this->visibility);
        }
    }
}

<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Format\FormatInterface;
use FFMpeg\Media\AdvancedMedia;
use Illuminate\Support\Collection;
use Pbmedia\LaravelFFMpeg\Drivers\DriverInterface;

class MediaExporter
{
    private DriverInterface $driver;

    private ?FormatInterface $format = null;

    private Collection $maps;

    protected ?string $visibility = null;

    private ?Disk $toDisk = null;

    public function __construct(DriverInterface $driver)
    {
        $this->driver = $driver;
        $this->maps   = new Collection;
    }

    private function getDisk(): Disk
    {
        if ($this->toDisk) {
            return $this->toDisk;
        }

        $media = $this->driver->getMediaCollection();

        return $media->first()->getDisk();
    }

    public function inFormat(FormatInterface $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function addFormatOutputMapping(FormatInterface $format, $outputPath, array $outs, $forceDisableAudio = false, $forceDisableVideo = false)
    {
        $this->maps->push([$outs, $format, $outputPath, $forceDisableAudio, $forceDisableVideo]);

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

    public function getAdvancedMedia(): AdvancedMedia
    {
        return $this->driver->get();
    }

    public function save(string $path = null)
    {
        $disk = $this->getDisk();

        $this->maps->each(function ($map) {
            return $this->getAdvancedMedia()->map(...$map);
        });

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

<?php

namespace Pbmedia\LaravelFFMpeg;

use Illuminate\Support\Arr;
use Pbmedia\LaravelFFMpeg\Drivers\DriverInterface;
use Pbmedia\LaravelFFMpeg\Filesystem\Disk;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;
use Pbmedia\LaravelFFMpeg\Filesystem\MediaCollection;

class MediaOpener
{
    private string $disk;
    private DriverInterface $driver;
    private MediaCollection $collection;

    public function __construct(string $disk = null, DriverInterface $driver = null)
    {
        $this->disk = $disk ?: config('filesystems.default');

        $this->driver = $driver ?: app(DriverInterface::class);

        $this->collection = new MediaCollection;
    }

    private function disk(): Disk
    {
        return new Disk($this->disk);
    }

    public function fromDisk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    public function open($path): self
    {
        $paths = Arr::wrap($path);

        foreach ($paths as $path) {
            $this->collection->add(Media::make($this->disk(), $path));
        }

        return $this;
    }

    public function get(): MediaCollection
    {
        return $this->collection;
    }

    public function getDriver(): DriverInterface
    {
        return $this->driver->open($this->collection);
    }

    public function getAdvancedDriver(): DriverInterface
    {
        return $this->driver->openAdvanced($this->collection);
    }

    public function addFilter(): self
    {
        $this->getDriver()->addFilter(...func_get_args());

        return $this;
    }

    public function getFilters(): array
    {
        return $this->getDriver()->getFilters();
    }

    public function getDurationInSeconds(): int
    {
        return round($this->getDurationInMiliseconds() / 1000);
    }

    public function getDurationInMiliseconds(): int
    {
        return $this->getDriver()->getDurationInMiliseconds();
    }

    public function export(): MediaExporter
    {
        return new MediaExporter($this->getDriver());
    }

    public function exportForHLS(): HLSExporter
    {
        return new HLSExporter($this->getAdvancedDriver());
    }
}

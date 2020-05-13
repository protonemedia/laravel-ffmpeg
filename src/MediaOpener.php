<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Media\AbstractMediaType;
use Illuminate\Support\Arr;
use Illuminate\Support\Traits\ForwardsCalls;
use Pbmedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use Pbmedia\LaravelFFMpeg\Filesystem\Disk;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;
use Pbmedia\LaravelFFMpeg\Filesystem\MediaCollection;

class MediaOpener
{
    use ForwardsCalls;

    private string $disk;
    private PHPFFMpeg $driver;
    private MediaCollection $collection;

    public function __construct(string $disk = null, PHPFFMpeg $driver = null)
    {
        $this->disk = $disk ?: config('filesystems.default');

        $this->driver = $driver ?: app(PHPFFMpeg::class);

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

    public function getDriver(): PHPFFMpeg
    {
        return $this->driver->open($this->collection);
    }

    public function getAdvancedDriver(): PHPFFMpeg
    {
        return $this->driver->openAdvanced($this->collection);
    }

    public function export(): MediaExporter
    {
        return new MediaExporter($this->getDriver());
    }

    public function exportForHLS(): HLSExporter
    {
        return new HLSExporter($this->getAdvancedDriver());
    }

    public function __invoke(): AbstractMediaType
    {
        return $this->getDriver()->get();
    }

    public function __call($method, $arguments)
    {
        $result = $this->forwardCallTo($driver = $this->getDriver(), $method, $arguments);

        return ($result === $driver) ? $this : $result;
    }
}

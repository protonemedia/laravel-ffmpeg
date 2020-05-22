<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Media\AbstractMediaType;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Traits\ForwardsCalls;
use Pbmedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use Pbmedia\LaravelFFMpeg\Exporters\HLSExporter;
use Pbmedia\LaravelFFMpeg\Exporters\MediaExporter;
use Pbmedia\LaravelFFMpeg\Filesystem\Disk;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;
use Pbmedia\LaravelFFMpeg\Filesystem\MediaCollection;
use Pbmedia\LaravelFFMpeg\Filesystem\TemporaryDirectories;

/**
 * @mixin \Pbmedia\LaravelFFMpeg\Drivers\PHPFFMpeg
 */
class MediaOpener
{
    use ForwardsCalls;

    /**
     * @var \Pbmedia\LaravelFFMpeg\Filesystem\Disk
     */
    private $disk;

    /**
     * @var \Pbmedia\LaravelFFMpeg\Drivers\PHPFFMpeg
     */
    private $driver;

    /**
     * @var \Pbmedia\LaravelFFMpeg\Filesystem\MediaCollection
     */
    private $collection;

    /**
     * @var \FFMpeg\Coordinate\TimeCode
     */
    private $timecode;

    /**
     * Uses the 'filesystems.default' disk from the config if none is given.
     * Gets the underlying PHPFFMpeg instance from the container if none is given.
     * Instantiates a fresh MediaCollection if none is given.
     */
    public function __construct($disk = null, PHPFFMpeg $driver = null, MediaCollection $mediaCollection = null)
    {
        $this->disk = Disk::make($disk ?: config('filesystems.default'));

        $this->driver = $driver ?: app(PHPFFMpeg::class);

        $this->collection = $mediaCollection ?: new MediaCollection;
    }

    /**
     * Set the disk to open files from.
     */
    public function fromDisk($disk): self
    {
        $this->disk = Disk::make($disk);

        return $this;
    }

    /**
     * Alias for 'fromDisk', mostly for backwards compatibility.
     */
    public function fromFilesystem(Filesystem $filesystem): self
    {
        return $this->fromDisk($filesystem);
    }

    /**
     * Instantiates a Media object for each given path.
     */
    public function open($path): self
    {
        $paths = Arr::wrap($path);

        foreach ($paths as $path) {
            $this->collection->push(Media::make($this->disk, $path));
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

    /**
     * Forces the driver to open the collection with the `openAdvanced` method.
     */
    public function getAdvancedDriver(): PHPFFMpeg
    {
        return $this->driver->openAdvanced($this->collection);
    }

    /**
     * Shortcut to set the timecode by string.
     */
    public function getFrameFromString(string $timecode): self
    {
        return $this->getFrameFromTimecode(TimeCode::fromString($timecode));
    }

    /**
     * Shortcut to set the timecode by seconds.
     */
    public function getFrameFromSeconds(float $quantity): self
    {
        return $this->getFrameFromTimecode(TimeCode::fromSeconds($quantity));
    }

    public function getFrameFromTimecode(TimeCode $timecode): self
    {
        $this->timecode = $timecode;

        return $this;
    }

    /**
     * Returns an instance of MediaExporter with the driver and timecode (if set).
     */
    public function export(): MediaExporter
    {
        return tap(new MediaExporter($this->getDriver()), function (MediaExporter $mediaExporter) {
            if ($this->timecode) {
                $mediaExporter->frame($this->timecode);
            }
        });
    }

    /**
     * Returns an instance of HLSExporter with the driver forced to AdvancedMedia.
     */
    public function exportForHLS(): HLSExporter
    {
        return new HLSExporter($this->getAdvancedDriver());
    }

    public function cleanupTemporaryFiles(): self
    {
        TemporaryDirectories::deleteAll();

        return $this;
    }

    /**
     * Returns the Media object from the driver.
     */
    public function __invoke(): AbstractMediaType
    {
        return $this->getDriver()->get();
    }

    /**
     * Forwards all calls to the underlying driver.
     * @return void
     */
    public function __call($method, $arguments)
    {
        $result = $this->forwardCallTo($driver = $this->getDriver(), $method, $arguments);

        return ($result === $driver) ? $this : $result;
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg;

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Media\AbstractMediaType;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\ForwardsCalls;
use ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSExporter;
use ProtoneMedia\LaravelFFMpeg\Exporters\MediaExporter;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\ImageFormat;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Filesystem\MediaCollection;
use ProtoneMedia\LaravelFFMpeg\Filesystem\MediaOnNetwork;
use ProtoneMedia\LaravelFFMpeg\Filesystem\TemporaryDirectories;
use ProtoneMedia\LaravelFFMpeg\Filters\TileFactory;

/**
 * @mixin \ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg
 */
class MediaOpener
{
    use ForwardsCalls;

    private Disk $disk;
    private PHPFFMpeg $driver;
    private MediaCollection $collection;
    private ?TimeCode $timecode = null;

    public function __construct($disk = null, ?PHPFFMpeg $driver = null, ?MediaCollection $mediaCollection = null)
    {
        $this->fromDisk($disk ?: config('filesystems.default'));
        $this->driver = ($driver ?: app(PHPFFMpeg::class))->fresh();
        $this->collection = $mediaCollection ?: new MediaCollection;
    }

    public function cloneOpener(): self
    {
        return new self($this->disk, $this->driver, $this->collection);
    }

    public function fromDisk($disk): self
    {
        $this->disk = Disk::make($disk);
        return $this;
    }

    public function fromFilesystem(Filesystem $filesystem): self
    {
        return $this->fromDisk($filesystem);
    }

    private static function makeLocalDiskFromPath(string $path): Disk
    {
        $adapter = (new FilesystemManager(app()))->createLocalDriver(['root' => $path]);
        return Disk::make($adapter);
    }

    public function open($paths): self
    {
        foreach (Arr::wrap($paths) as $path) {
            if ($path instanceof UploadedFile) {
                $disk = self::makeLocalDiskFromPath($path->getPath());
                $media = Media::make($disk, $path->getFilename());
            } else {
                $media = Media::make($this->disk, $path);
            }

            $this->collection->push($media);
        }

        return $this;
    }

    public function openWithInputOptions(string $path, array $options = []): self
    {
        $this->collection->push(
            Media::make($this->disk, $path)->setInputOptions($options)
        );

        return $this;
    }

    public function openUrl($paths, array $headers = []): self
    {
        foreach (Arr::wrap($paths) as $path) {
            $this->collection->push(MediaOnNetwork::make($path, $headers));
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

    public function getFrameFromString(string $timecode): self
    {
        return $this->getFrameFromTimecode(TimeCode::fromString($timecode));
    }

    public function getFrameFromSeconds(float $seconds): self
    {
        return $this->getFrameFromTimecode(TimeCode::fromSeconds($seconds));
    }

    public function getFrameFromTimecode(TimeCode $timecode): self
    {
        $this->timecode = $timecode;
        return $this;
    }

    public function export(): MediaExporter
    {
        return tap(new MediaExporter($this->getDriver()), function (MediaExporter $mediaExporter) {
            if ($this->timecode) {
                $mediaExporter->frame($this->timecode);
            }
        });
    }

    public function exportForHLS(): HLSExporter
    {
        return new HLSExporter($this->getAdvancedDriver());
    }

    public function exportTile(callable $withTileFactory): MediaExporter
    {
        return $this->export()
            ->addTileFilter($withTileFactory)
            ->inFormat(new ImageFormat);
    }

    public function exportFramesByAmount(int $amount, ?int $width = null, ?int $height = null, ?int $quality = null): MediaExporter
    {
        $interval = ($this->getDurationInSeconds() + 1) / $amount;
        return $this->exportFramesByInterval($interval, $width, $height, $quality);
    }

    public function exportFramesByInterval(float $interval, ?int $width = null, ?int $height = null, ?int $quality = null): MediaExporter
    {
        return $this->exportTile(fn (TileFactory $tileFactory) =>
            $tileFactory->interval($interval)
                        ->grid(1, 1)
                        ->scale($width, $height)
                        ->quality($quality)
        );
    }

    public function cleanupTemporaryFiles(): self
    {
        app(TemporaryDirectories::class)->deleteAll();
        return $this;
    }

    public function each($items, callable $callback): self
    {
        Collection::make($items)->each(function ($item, $key) use ($callback) {
            return $callback($this->cloneOpener(), $item, $key);
        });

        return $this;
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

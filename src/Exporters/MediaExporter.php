<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use FFMpeg\Format\FormatInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\ForwardsCalls;
use ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;

/**
 * @mixin \ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg
 */
class MediaExporter
{
    use ForwardsCalls,
        HandlesAdvancedMedia,
        HandlesConcatenation,
        HandlesFrames,
        HandlesTimelapse,
        HasProgressListener;

    /**
     * @var \ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg
     */
    protected $driver;

    /**
     * @var \FFMpeg\Format\FormatInterface
     */
    private $format;

    /**
     * @var string
     */
    protected $visibility;

    /**
     * @var \ProtoneMedia\LaravelFFMpeg\Filesystem\Disk
     */
    private $toDisk;

    public function __construct(PHPFFMpeg $driver)
    {
        $this->driver = $driver;

        $this->maps = new Collection;
    }

    protected function getDisk(): Disk
    {
        if ($this->toDisk) {
            return $this->toDisk;
        }

        $media = $this->driver->getMediaCollection();

        return $this->toDisk = $media->first()->getDisk();
    }

    public function inFormat(FormatInterface $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function toDisk($disk)
    {
        $this->toDisk = Disk::make($disk);

        return $this;
    }

    public function withVisibility(string $visibility)
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getCommand(string $path = null)
    {
        $this->driver->getPendingComplexFilters()->each->apply($this->driver, $this->maps);

        $this->maps->each->apply($this->driver->get());

        return $this->driver->getFinalCommand(
            $this->format,
            $path ? $this->getDisk()->makeMedia($path)->getLocalPath() : null
        );
    }

    public function save(string $path = null)
    {
        $outputMedia = $path ? $this->getDisk()->makeMedia($path) : null;

        if ($this->concatWithTranscoding && $outputMedia) {
            $this->addConcatFilterAndMapping($outputMedia);
        }

        if ($this->maps->isNotEmpty()) {
            return $this->saveWithMappings();
        }

        if ($this->format && $this->onProgressCallback) {
            $this->applyProgressListenerToFormat($this->format);
        }

        if ($this->timelapseFramerate > 0) {
            $this->addTimelapseParametersToFormat();
        }

        if ($this->driver->isConcat() && $outputMedia) {
            $this->driver->saveFromSameCodecs($outputMedia->getLocalPath());
        } elseif ($this->driver->isFrame()) {
            $data = $this->driver->save(
                optional($outputMedia)->getLocalPath(),
                $this->getAccuracy(),
                $this->returnFrameContents
            );

            if ($this->returnFrameContents) {
                return $data;
            }
        } else {
            $this->driver->save($this->format, $outputMedia->getLocalPath());
        }

        $outputMedia->copyAllFromTemporaryDirectory($this->visibility);
        $outputMedia->setVisibility($this->visibility);

        if ($this->onProgressCallback) {
            call_user_func($this->onProgressCallback, 100, 0, 0);
        }

        return $this->getMediaOpener();
    }

    private function saveWithMappings(): MediaOpener
    {
        $this->driver->getPendingComplexFilters()->each->apply($this->driver, $this->maps);

        $this->maps->map->apply($this->driver->get());

        if ($this->onProgressCallback) {
            $this->applyProgressListenerToFormat($this->maps->last()->getFormat());
        }

        $this->driver->save();

        if ($this->onProgressCallback) {
            call_user_func($this->onProgressCallback, 100);
        }

        $this->maps->map->getOutputMedia()->each->copyAllFromTemporaryDirectory($this->visibility);

        return $this->getMediaOpener();
    }

    protected function getMediaOpener(): MediaOpener
    {
        return new MediaOpener(
            $this->driver->getMediaCollection()->last()->getDisk(),
            $this->driver,
            $this->driver->getMediaCollection()
        );
    }

    /**
     * Forwards the call to the driver object and returns the result
     * if it's something different than the driver object itself.
     */
    public function __call($method, $arguments)
    {
        $result = $this->forwardCallTo($driver = $this->driver, $method, $arguments);

        return ($result === $driver) ? $this : $result;
    }
}

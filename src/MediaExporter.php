<?php

namespace Pbmedia\LaravelFFMpeg;

use Closure;
use Evenement\EventEmitterInterface;
use FFMpeg\Format\FormatInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\ForwardsCalls;
use Pbmedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use Pbmedia\LaravelFFMpeg\FFMpeg\AdvancedOutputMapping;
use Pbmedia\LaravelFFMpeg\Filesystem\Disk;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;

class MediaExporter
{
    use ForwardsCalls;

    protected PHPFFMpeg $driver;
    private ?FormatInterface $format = null;
    protected Collection $maps;
    protected ?string $visibility        = null;
    private ?Disk $toDisk                = null;
    private ?Closure $onProgressCallback = null;
    private ?float $lastPercentage       = null;

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

    public function onProgress(Closure $callback): self
    {
        $this->onProgressCallback = $callback;

        return $this;
    }

    public function addFormatOutputMapping(FormatInterface $format, Media $output, array $outs, $forceDisableAudio = false, $forceDisableVideo = false)
    {
        $this->maps->push(
            new AdvancedOutputMapping($outs, $format, $output, $forceDisableAudio, $forceDisableVideo)
        );

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

    public function getCommand(string $path = null): string
    {
        $this->driver->getPendingComplexFilters()->each->apply($this->driver, $this->maps);

        $this->maps->each->apply($this->driver->get());

        return $this->driver->getFinalCommand(
            $this->format,
            $path ? $this->getDisk()->makeMedia($path)->getLocalPath() : null
        );
    }

    private function applyProgressListenerToFormat(EventEmitterInterface $format)
    {
        $format->on('progress', function ($video, $format, $percentage) {
            if ($percentage !== $this->lastPercentage && $percentage < 100) {
                $this->lastPercentage = $percentage;
                call_user_func($this->onProgressCallback, $percentage);
            }
        });
    }

    public function save(string $path = null): MediaOpener
    {
        if ($this->maps->isNotEmpty()) {
            return $this->saveWithMappings();
        }

        $outputMedia = $this->getDisk()->makeMedia($path);

        if ($this->format && $this->onProgressCallback) {
            $this->applyProgressListenerToFormat($this->format);
        }

        $this->driver->save($this->format, $outputMedia->getLocalPath());

        $outputMedia->copyAllFromTemporaryDirectory($this->visibility);
        $outputMedia->setVisibility($this->visibility);

        if ($this->onProgressCallback) {
            call_user_func($this->onProgressCallback, 100);
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
            $this->driver->fresh(),
            $this->driver->getMediaCollection()
        );
    }

    protected function getEmptyMediaOpener($disk = null): MediaOpener
    {
        return new MediaOpener(
            $disk,
            $this->driver->fresh(),
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

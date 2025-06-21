<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use FFMpeg\Exception\RuntimeException;
use FFMpeg\Format\FormatInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\ForwardsCalls;
use ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\NullFormat;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\StdListener;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Filters\TileFactory;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;
use ProtoneMedia\LaravelFFMpeg\Support\ProcessOutput;

/**
 * @mixin \ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg
 */
class MediaExporter
{
    use ForwardsCalls;
    use HandlesAdvancedMedia;
    use HandlesConcatenation;
    use HandlesFrames;
    use HandlesTimelapse;
    use HasProgressListener;

    protected PHPFFMpeg $driver;
    private ?FormatInterface $format = null;
    protected ?string $visibility = null;
    private ?Disk $toDisk = null;

    /**
     * Callbacks to execute after saving.
     *
     * @var array<callable>
     */
    private array $afterSavingCallbacks = [];

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

        /** @var Disk $disk */
        $disk = $this->driver->getMediaCollection()->first()->getDisk();

        return $this->toDisk = $disk->cloneDisk(); // updated to match Disk.php changes
    }

    public function inFormat(FormatInterface $format): self
    {
        $this->format = $format;
        return $this;
    }

    public function toDisk($disk): self
    {
        $this->toDisk = Disk::make($disk);
        return $this;
    }

    public function withVisibility(string $visibility): self
    {
        $this->visibility = $visibility;
        return $this;
    }

    /**
     * Add tile filter and generate VTT thumbnails.
     */
    public function addTileFilter(callable $withTileFactory): self
    {
        $withTileFactory(
            $tileFactory = new TileFactory
        );

        $this->addFilter($filter = $tileFactory->get());

        if (!$tileFactory->vttOutputPath) {
            return $this;
        }

        return $this->afterSaving(function (MediaExporter $mediaExporter, Media $outputMedia) use ($filter, $tileFactory) {
            $generator = new VTTPreviewThumbnailsGenerator(
                $filter,
                $mediaExporter->driver->getDurationInSeconds(),
                $tileFactory->vttSequenceFilename ?: fn () => $outputMedia->getPath() // fixed typo
            );

            $this->toDisk->put($tileFactory->vttOutputPath, $generator->getContents());
        });
    }

    /**
     * Get the final FFMpeg command string.
     */
    public function getCommand(?string $path = null)
    {
        $media = $this->prepareSaving($path);

        return $this->driver->getFinalCommand(
            $this->format ?: new NullFormat,
            optional($media)->getLocalPath() ?: '/dev/null'
        );
    }

    public function dd(?string $path = null): void
    {
        dd($this->getCommand($path));
    }

    public function afterSaving(callable $callback): self
    {
        $this->afterSavingCallbacks[] = $callback;
        return $this;
    }

    private function prepareSaving(?string $path = null): ?Media
    {
        $outputMedia = $path ? $this->getDisk()->makeMedia($path) : null;

        if ($this->concatWithTranscoding && $outputMedia) {
            $this->addConcatFilterAndMapping($outputMedia);
        }

        if ($this->maps->isNotEmpty()) {
            $this->driver->getPendingComplexFilters()->each->apply($this->driver, $this->maps);
            $this->maps->map->apply($this->driver->get());
            return $outputMedia;
        }

        if ($this->format && $this->onProgressCallback) {
            $this->applyProgressListenerToFormat($this->format);
        }

        if ($this->timelapseFramerate > 0) {
            $this->addTimelapseParametersToFormat();
        }

        return $outputMedia;
    }

    protected function runAfterSavingCallbacks(?Media $outputMedia = null): void
    {
        foreach ($this->afterSavingCallbacks as $key => $callback) {
            call_user_func($callback, $this, $outputMedia);
            unset($this->afterSavingCallbacks[$key]);
        }
    }

    public function save(?string $path = null)
    {
        $outputMedia = $this->prepareSaving($path);

        $this->driver->applyBeforeSavingCallbacks();

        if ($this->maps->isNotEmpty()) {
            return $this->saveWithMappings();
        }

        try {
            if ($this->driver->isConcat() && $outputMedia) {
                $this->driver->saveFromSameCodecs($outputMedia->getLocalPath());
            } elseif ($this->driver->isFrame()) {
                $data = $this->driver->save(
                    optional($outputMedia)->getLocalPath(),
                    $this->getAccuracy(),
                    $this->returnFrameContents
                );

                if ($this->returnFrameContents) {
                    $this->runAfterSavingCallbacks($outputMedia);
                    return $data;
                }
            } else {
                $this->driver->save(
                    $this->format ?: new NullFormat,
                    optional($outputMedia)->getLocalPath() ?: '/dev/null'
                );
            }
        } catch (RuntimeException $exception) {
            throw EncodingException::decorate($exception);
        }

        if ($outputMedia) {
            $outputMedia->copyAllFromTemporaryDirectory($this->visibility);
            $outputMedia->setVisibility($path, $this->visibility);
        }

        if ($this->onProgressCallback) {
            call_user_func($this->onProgressCallback, 100, 0, 0);
        }

        $this->runAfterSavingCallbacks($outputMedia);

        return $this->getMediaOpener();
    }

    public function getProcessOutput(): ProcessOutput
    {
        return tap(new StdListener, function (StdListener $listener) {
            $this->addListener($listener)->save();
            $listener->removeAllListeners();
            $this->removeListener($listener);
        })->get();
    }

    private function saveWithMappings(): MediaOpener
    {
        if ($this->onProgressCallback) {
            $this->applyProgressListenerToFormat($this->maps->last()->getFormat());
        }

        try {
            $this->driver->save();
        } catch (RuntimeException $exception) {
            throw EncodingException::decorate($exception);
        }

        if ($this->onProgressCallback) {
            call_user_func($this->onProgressCallback, 100, 0, 0);
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

    public function __call($method, $arguments)
    {
        $result = $this->forwardCallTo($driver = $this->driver, $method, $arguments);
        return ($result === $driver) ? $this : $result;
    }
}

<?php

namespace Pbmedia\LaravelFFMpeg\Drivers;

use Closure;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe\DataMapping\Format;
use FFMpeg\Filters\Audio\SimpleFilter;
use FFMpeg\Filters\FilterInterface;
use FFMpeg\Media\AbstractMediaType;
use FFMpeg\Media\AdvancedMedia;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Pbmedia\LaravelFFMpeg\FFMpeg\BasicFilterMapping;
use Pbmedia\LaravelFFMpeg\Filesystem\MediaCollection;

class PHPFFMpeg implements DriverInterface
{
    private FFMpeg $ffmpeg;
    private bool $forceAdvanced = false;
    private MediaCollection $mediaCollection;
    private Collection $pendingBasicFilters;
    private ?AbstractMediaType $media = null;

    public function __construct(FFMpeg $ffmpeg)
    {
        $this->ffmpeg              = $ffmpeg;
        $this->pendingBasicFilters = new Collection;
    }

    public function fresh(): self
    {
        return new static($this->ffmpeg);
    }

    public function getMediaCollection(): MediaCollection
    {
        return $this->mediaCollection;
    }

    public function open(MediaCollection $mediaCollection): self
    {
        if ($this->media) {
            return $this;
        }

        $this->mediaCollection = $mediaCollection;

        $localPaths = $mediaCollection->getLocalPaths();

        if (count($localPaths) === 1 && !$this->forceAdvanced) {
            $this->media = $this->ffmpeg->open(Arr::first($localPaths));
        } else {
            $this->media = $this->ffmpeg->openAdvanced($localPaths);
        }

        return $this;
    }

    public function openAdvanced(MediaCollection $mediaCollection): self
    {
        $this->forceAdvanced = true;

        return $this->open($mediaCollection);
    }

    private function getStreams(): array
    {
        return iterator_to_array($this->media->getStreams());
    }

    private function getFormat(): Format
    {
        return $this->media->getFormat();
    }

    public function getWidth(): int
    {
        return Arr::first($this->getStreams())->get('width');
    }

    public function getHeight(): int
    {
        return Arr::first($this->getStreams())->get('height');
    }

    public function getDurationInMiliseconds(): int
    {
        $stream = Arr::first($this->getStreams());

        if ($stream->has('duration')) {
            return $stream->get('duration') * 1000;
        }

        $format = $this->getFormat();

        if ($format->has('duration')) {
            return $format->get('duration') * 1000;
        }
    }

    public function addFilter(): self
    {
        $arguments = func_get_args();

        if ($this->isAdvancedMedia() && count($arguments) === 3) {
            $this->media->filters()->custom(...$arguments);
        } elseif (isset($arguments[0]) && $arguments[0] instanceof Closure) {
            call_user_func_array($arguments[0], [$this->media->filters()]);
        } elseif (isset($arguments[0]) && $arguments[0] instanceof FilterInterface) {
            call_user_func_array([$this->media, 'addFilter'], $arguments);
        } elseif (isset($arguments[0]) && is_array($arguments[0])) {
            $this->media->addFilter(new SimpleFilter($arguments[0]));
        } else {
            $this->media->addFilter(new SimpleFilter($arguments));
        }

        return $this;
    }

    public function getBasicFilters(): Collection
    {
        return $this->pendingBasicFilters;
    }

    public function addBasicFilter($in, $out, ...$arguments): self
    {
        $this->pendingBasicFilters->push(new BasicFilterMapping(
            $in,
            $out,
            ...$arguments,
        ));

        return $this;
    }

    public function getCommand($format = null, $path = null): string
    {
        return $this->media->getFinalCommand($format, $path);
    }

    public function getFilters(): array
    {
        return iterator_to_array($this->media->getFiltersCollection());
    }

    public function get()
    {
        return $this->media;
    }

    private function isAdvancedMedia(): bool
    {
        return $this->get() instanceof AdvancedMedia;
    }

    public function save($format = null, $path = null)
    {
        $this->isAdvancedMedia()
         ? $this->media->save()
         : $this->media->save($format, $path);
    }
}

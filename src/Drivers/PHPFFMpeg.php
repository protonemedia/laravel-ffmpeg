<?php

namespace Pbmedia\LaravelFFMpeg\Drivers;

use Closure;
use FFMpeg\FFMpeg;
use FFMpeg\Filters\Audio\SimpleFilter;
use FFMpeg\Filters\FilterInterface;
use FFMpeg\Media\AbstractMediaType;
use FFMpeg\Media\AdvancedMedia;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\ForwardsCalls;
use Pbmedia\LaravelFFMpeg\FFMpeg\LegacyFilterMapping;
use Pbmedia\LaravelFFMpeg\Filesystem\MediaCollection;

class PHPFFMpeg
{
    use ForwardsCalls;

    private FFMpeg $ffmpeg;
    private bool $forceAdvanced = false;
    private MediaCollection $mediaCollection;
    private Collection $pendingComplexFilters;
    private ?AbstractMediaType $media = null;

    public function __construct(FFMpeg $ffmpeg)
    {
        $this->ffmpeg               = $ffmpeg;
        $this->pendingComplexFilters = new Collection;
    }

    /**
     * Returns a fresh instance of itself with only the underlying FFMpeg instance.
     */
    public function fresh(): self
    {
        return new static($this->ffmpeg);
    }

    public function get(): AbstractMediaType
    {
        return $this->media;
    }

    private function isAdvancedMedia(): bool
    {
        return $this->get() instanceof AdvancedMedia;
    }

    public function getMediaCollection(): MediaCollection
    {
        return $this->mediaCollection;
    }

    /**
     * Opens the MediaCollection if it's not been instanciated yet.
     */
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

    /**
     * Force 'openAdvanced' when opening the MediaCollection
     */
    public function openAdvanced(MediaCollection $mediaCollection): self
    {
        $this->forceAdvanced = true;

        return $this->open($mediaCollection);
    }

    public function getStreams(): array
    {
        return iterator_to_array($this->media->getStreams());
    }

    public function getFilters(): array
    {
        return iterator_to_array($this->media->getFiltersCollection());
    }

    //

    public function getDurationInSeconds(): int
    {
        return round($this->getDurationInMiliseconds() / 1000);
    }

    /**
     * Gets the duration of the media from the first stream or from the format.
     */
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

    //

    /**
     * Helper method to provide multiple ways to add a filter to the underlying
     * media object.
     *
     * @return self
     */
    public function addFilter(): self
    {
        $arguments = func_get_args();

        // to support '[in]filter[out]' complex filters
        if ($this->isAdvancedMedia() && count($arguments) === 3) {
            $this->media->filters()->custom(...$arguments);

            return $this;
        }

        // use a callback to add a filter
        if ($arguments[0] instanceof Closure) {
            call_user_func_array($arguments[0], [$this->media->filters()]);

            return $this;
        }

        // use an object to add a filter
        if ($arguments[0] instanceof FilterInterface) {
            call_user_func_array([$this->media, 'addFilter'], $arguments);

            return $this;
        }

        // use a single array with parameters to define a filter
        if (is_array($arguments[0])) {
            $this->media->addFilter(new SimpleFilter($arguments[0]));

            return $this;
        }

        // use all function arguments as a filter
        $this->media->addFilter(new SimpleFilter($arguments));

        return $this;
    }

    /**
     * Maps the arguments into a 'LegacyFilterMapping' instance and
     * pushed it to the 'pendingComplexFilters' collection. These
     * filters will be applied later on by the MediaExporter.
     */
    public function addFilterAsComplexFilter($in, $out, ...$arguments): self
    {
        $this->pendingComplexFilters->push(new LegacyFilterMapping(
            $in,
            $out,
            ...$arguments,
        ));

        return $this;
    }

    public function getPendingComplexFilters(): Collection
    {
        return $this->pendingComplexFilters;
    }

    public function save($format = null, $path = null)
    {
        $this->media->save($format, $path);
    }

    /**
     * Returns the underlying media object itself.
     */
    public function __invoke(): AbstractMediaType
    {
        return $this->get();
    }

    /**
     * Forwards the call to the underling media object and returns the result
     * if it's something different than the media object itself.
     */
    public function __call($method, $arguments)
    {
        $result = $this->forwardCallTo($media = $this->get(), $method, $arguments);

        return ($result === $media) ? $this : $result;
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Drivers;

use Closure;
use Exception;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe\DataMapping\Stream;
use FFMpeg\Filters\Audio\SimpleFilter;
use FFMpeg\Filters\FilterInterface;
use FFMpeg\Media\AbstractMediaType;
use FFMpeg\Media\AdvancedMedia as BaseAdvancedMedia;
use FFMpeg\Media\Concat;
use FFMpeg\Media\Frame;
use FFMpeg\Media\Video;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\ForwardsCalls;
use ProtoneMedia\LaravelFFMpeg\Exporters\MediaExporter;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\AdvancedMedia;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\AudioMedia;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\FFProbe;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\LegacyFilterMapping;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\VideoMedia;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Filesystem\MediaCollection;
use ProtoneMedia\LaravelFFMpeg\Filesystem\MediaOnNetwork;

/**
 * @mixin \FFMpeg\Media\AbstractMediaType
 */
class PHPFFMpeg
{
    use ForwardsCalls;

    /**
     * @var \FFMpeg\FFMpeg
     */
    private $ffmpeg;

    /**
     * @var \ProtoneMedia\LaravelFFMpeg\Filesystem\MediaCollection
     */
    private $mediaCollection;

    /**
     * @var boolean
     */
    private $forceAdvanced = false;

    /**
     * @var \Illuminate\Support\Collection
     */
    private $pendingComplexFilters;

    /**
     * @var \FFMpeg\Media\AbstractMediaType
     */
    private $media;

    public function __construct(FFMpeg $ffmpeg)
    {
        $this->ffmpeg                = $ffmpeg;
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
        return $this->get() instanceof BaseAdvancedMedia;
    }

    public function isFrame(): bool
    {
        return $this->get() instanceof Frame;
    }

    public function isConcat(): bool
    {
        return $this->get() instanceof Concat;
    }

    public function isVideo(): bool
    {
        return $this->get() instanceof Video;
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

        if ($mediaCollection->count() === 1 && !$this->forceAdvanced) {
            $packageMedia = Arr::first($mediaCollection->collection());

            $this->ffmpeg->setFFProbe(
                FFProbe::make($this->ffmpeg->getFFProbe())->setMedia($packageMedia)
            );

            $media = $this->ffmpeg->open($packageMedia->getLocalPath());

            $this->media = $media instanceof Video
                ? VideoMedia::make($media)
                : AudioMedia::make($media);

            if ($packageMedia instanceof MediaOnNetwork) {
                $this->media->setHeaders($packageMedia->getHeaders());
            }
        } else {
            $localPaths = $mediaCollection->getLocalPaths();

            $this->media = AdvancedMedia::make(
                $this->ffmpeg->openAdvanced($localPaths)
            );
        }

        return $this;
    }

    public function frame(TimeCode $timecode)
    {
        if (!$this->isVideo()) {
            throw new Exception('Opened media is not a video file.');
        }

        $this->media = $this->media->frame($timecode);

        return $this;
    }

    public function concatWithoutTranscoding()
    {
        $localPaths = $this->mediaCollection->getLocalPaths();

        $this->media = $this->ffmpeg->open(Arr::first($localPaths))
            ->concat($localPaths);

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
        if (!$this->isAdvancedMedia()) {
            return iterator_to_array($this->media->getStreams());
        }

        return $this->mediaCollection->map(function (Media $media) {
            return $this->fresh()->open(MediaCollection::make([$media]))->getStreams();
        })->collapse()->all();
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

    /**
     * Gets the first audio streams of the media.
     */
    public function getAudioStream(): ?Stream
    {
        return Arr::first($this->getStreams(), function (Stream $stream) {
            return $stream->isAudio();
        });
    }

    /**
     * Gets the first video streams of the media.
     */
    public function getVideoStream(): ?Stream
    {
        return Arr::first($this->getStreams(), function (Stream $stream) {
            return $stream->isVideo();
        });
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
            ...$arguments
        ));

        return $this;
    }

    public function getPendingComplexFilters(): Collection
    {
        return $this->pendingComplexFilters;
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

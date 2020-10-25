<?php

namespace ProtoneMedia\LaravelFFMpeg\Drivers;

use Exception;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\Media\AbstractMediaType;
use FFMpeg\Media\AdvancedMedia as BaseAdvancedMedia;
use FFMpeg\Media\Concat;
use FFMpeg\Media\Frame;
use FFMpeg\Media\Video;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\ForwardsCalls;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\AdvancedMedia;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\AudioMedia;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\FFProbe;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\VideoMedia;
use ProtoneMedia\LaravelFFMpeg\Filesystem\MediaCollection;

/**
 * @mixin \FFMpeg\Media\AbstractMediaType
 */
class PHPFFMpeg
{
    use ForwardsCalls;
    use InteractsWithFilters;
    use InteractsWithMediaStreams;

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
            $media = Arr::first($mediaCollection->collection());

            $this->ffmpeg->setFFProbe(
                FFProbe::make($this->ffmpeg->getFFProbe())->setMedia($media)
            );

            $ffmpegMedia = $this->ffmpeg->open($media->getLocalPath());

            $this->media = $ffmpegMedia instanceof Video
                ? VideoMedia::make($ffmpegMedia)
                : AudioMedia::make($ffmpegMedia);

            $this->media->setHeaders(Arr::first($mediaCollection->getHeaders()) ?: []);
        } else {
            $ffmpegMedia = $this->ffmpeg->openAdvanced($mediaCollection->getLocalPaths());

            $this->media = AdvancedMedia::make($ffmpegMedia)
                ->setHeaders($mediaCollection->getHeaders());
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

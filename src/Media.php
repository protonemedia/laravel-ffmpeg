<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Media\Frame;
use FFMpeg\Media\MediaTypeInterface;

class Media
{
    protected $file;

    protected $media;

    public function __construct(File $file, MediaTypeInterface $media)
    {
        $this->file  = $file;
        $this->media = $media;
    }

    public function isFrame(): bool
    {
        return $this->media instanceof Frame;
    }

    public function getFile(): File
    {
        return $this->file;
    }

    public function export(): MediaExporter
    {
        return new MediaExporter($this);
    }

    public function getFrameFromString(string $timecode): Media
    {
        return $this->getFrameFromTimecode(
            TimeCode::fromString($timecode)
        );
    }

    public function getFrameFromSeconds(float $quantity): Media
    {
        return $this->getFrameFromTimecode(
            TimeCode::fromSeconds($quantity)
        );
    }

    public function getFrameFromTimecode(TimeCode $timecode): Media
    {
        $frame = $this->media->frame($timecode);

        return new static($this->getFile(), $frame);
    }

    protected function selfOrArgument($argument)
    {
        return ($argument === $this->media) ? $this : $argument;
    }

    public function __call($method, $parameters)
    {
        return $this->selfOrArgument(
            call_user_func_array([$this->media, $method], $parameters)
        );
    }
}

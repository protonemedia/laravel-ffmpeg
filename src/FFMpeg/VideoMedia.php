<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Media\Video;

class VideoMedia extends Video
{
    use RebuildsCommands;

    /**
     * Create a new instance of this class with the instance of the underlying library.
     *
     * @param \FFMpeg\Media\Video $video
     * @return self
     */
    public static function make(Video $video): self
    {
        return new static($video->getPathfile(), $video->getFFMpegDriver(), $video->getFFProbe());
    }
}

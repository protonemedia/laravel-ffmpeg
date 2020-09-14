<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Media\Video;

class VideoMedia extends Video
{
    use BuildsCommandsWithHttpHeaders;

    public static function make(Video $video)
    {
        return new static($video->getPathfile(), $video->getFFMpegDriver(), $video->getFFProbe());
    }
}

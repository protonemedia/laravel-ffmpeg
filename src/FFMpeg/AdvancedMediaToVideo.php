<?php

namespace Pbmedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Media\AdvancedMedia;
use FFMpeg\Media\Video;

class AdvancedMediaToVideo
{
    public static function make(AdvancedMedia $media): Video
    {
        return new Video(
            $media->getPathfile(),
            $media->getFFMpegDriver(),
            $media->getFFProbe()
        );
    }
}

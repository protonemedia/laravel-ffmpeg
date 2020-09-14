<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Media\Audio;

class AudioMedia extends Audio
{
    use InteractsWithHttpHeaders;

    public static function make(Audio $audio)
    {
        return new static($audio->getPathfile(), $audio->getFFMpegDriver(), FFProbe::make($audio->getFFProbe()));
    }
}

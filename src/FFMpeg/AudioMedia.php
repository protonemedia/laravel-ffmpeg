<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Media\Audio;

class AudioMedia extends Audio
{
    use RebuildsCommands;

    /**
     * Create a new instance of this class with the instance of the underlying library.
     *
     * @param \FFMpeg\Media\Audio $audio
     * @return self
     */
    public static function make(Audio $audio): self
    {
        return new static($audio->getPathfile(), $audio->getFFMpegDriver(), FFProbe::make($audio->getFFProbe()));
    }
}

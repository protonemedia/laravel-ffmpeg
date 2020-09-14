<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Media\AdvancedMedia as MediaAdvancedMedia;

class AdvancedMedia extends MediaAdvancedMedia
{
    public static function make(MediaAdvancedMedia $media)
    {
        return new static($media->getInputs(), $media->getFFMpegDriver(), FFProbe::make($media->getFFProbe()));
    }
}

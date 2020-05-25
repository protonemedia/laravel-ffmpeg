<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Format\VideoInterface;

class VideoProgressListenerDecorator extends ProgressListenerDecorator implements VideoInterface
{
    public function getKiloBitrate()
    {
        return $this->format->getKiloBitrate(...func_get_args());
    }

    public function getModulus()
    {
        return $this->format->getModulus(...func_get_args());
    }

    public function getVideoCodec()
    {
        return $this->format->getVideoCodec(...func_get_args());
    }

    public function supportBFrames()
    {
        return $this->format->supportBFrames(...func_get_args());
    }

    public function getAvailableVideoCodecs()
    {
        return $this->format->getAvailableVideoCodecs(...func_get_args());
    }

    public function getAdditionalParameters()
    {
        return $this->format->getAdditionalParameters(...func_get_args());
    }

    public function getInitialParameters()
    {
        return $this->format->getInitialParameters(...func_get_args());
    }

    public function getAvailableAudioCodecs()
    {
        return $this->format->getAvailableAudioCodecs(...func_get_args());
    }
}

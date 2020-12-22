<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Format\Audio\DefaultAudio;

class NullFormat extends DefaultAudio
{
    public function __construct()
    {
        $this->audioKiloBitrate = null;
    }

    /**
     * {@inheritdoc}
     */
    public function getExtraParams()
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function getAvailableAudioCodecs()
    {
        return [];
    }
}

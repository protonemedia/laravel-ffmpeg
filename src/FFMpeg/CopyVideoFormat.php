<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Format\Video\DefaultVideo;

class CopyVideoFormat extends DefaultVideo
{
    public function __construct()
    {
        $this->audioCodec = 'copy';
        $this->videoCodec = 'copy';

        $this->kiloBitrate      = 0;
        $this->audioKiloBitrate = null;
    }

    /**
     * {@inheritDoc}
     */
    public function getAvailableAudioCodecs()
    {
        return ['copy'];
    }

    /**
     * {@inheritDoc}
     */
    public function getAvailableVideoCodecs()
    {
        return ['copy'];
    }

    /**
     * {@inheritDoc}
     */
    public function supportBFrames()
    {
        return false;
    }
}

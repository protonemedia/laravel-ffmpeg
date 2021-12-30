<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Format\Video\DefaultVideo;

class ImageFormat extends DefaultVideo
{
    public function __construct()
    {
        $this->kiloBitrate      = 0;
        $this->audioKiloBitrate = null;
    }

    /**
    * Gets the kiloBitrate value.
    *
    * @return int
    */
    public function getKiloBitrate()
    {
        return $this->kiloBitrate;
    }

    /**
     * Returns the modulus used by the Resizable video.
     *
     * This used to calculate the target dimensions while maintaining the best
     * aspect ratio.
     *
     * @see http://www.undeadborn.net/tools/rescalculator.php
     *
     * @return int
     */
    public function getModulus()
    {
        return 0;
    }

    /**
     * Returns the video codec.
     *
     * @return string
     */
    public function getVideoCodec()
    {
        return null;
    }

    /**
     * Returns true if the current format supports B-Frames.
     *
     * @see https://wikipedia.org/wiki/Video_compression_picture_types
     *
     * @return bool
     */
    public function supportBFrames()
    {
        return false;
    }

    /**
     * Returns the list of available video codecs for this format.
     *
     * @return array
     */
    public function getAvailableVideoCodecs()
    {
        return [];
    }

    /**
     * Returns the list of additional parameters for this format.
     *
     * @return array
     */
    public function getAdditionalParameters()
    {
        return ['-f', 'image2'];
    }

    /**
     * Returns the list of initial parameters for this format.
     *
     * @return array
     */
    public function getInitialParameters()
    {
        return [];
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

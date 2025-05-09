<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Format\Video\DefaultVideo;

class ImageFormat extends DefaultVideo
{
    protected $duration = null;

    public function __construct()
    {
        $this->kiloBitrate = 0;
        $this->audioKiloBitrate = null;
    }

    /**
     * Set the duration for image-to-video conversion.
     *
     * @param float $duration
     * @return $this
     */
    public function setDuration(float $duration): self
    {
        if ($duration <= 0) {
            throw new \InvalidArgumentException('Duration must be greater than 0.');
        }
        $this->duration = $duration;
        return $this;
    }

    /**
     * Gets the kiloBitrate value.
     *
     * @return int
     */
    public function getKiloBitrate(): int
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
    public function getModulus(): int
    {
        return 0;
    }

    /**
     * Returns the video codec.
     *
     * @return string|null
     */
    public function getVideoCodec(): ?string
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
    public function supportBFrames(): bool
    {
        return false;
    }

    /**
     * Returns the list of available video codecs for this format.
     *
     * @return array
     */
    public function getAvailableVideoCodecs(): array
    {
        return [];
    }

    /**
     * Returns the list of additional parameters for this format.
     *
     * @return array
     */
    public function getAdditionalParameters(): array
    {
        $parameters = ['-f', 'image2'];

        if ($this->duration !== null) {
            $parameters[] = '-loop';
            $parameters[] = '1';
            $parameters[] = '-t';
            $parameters[] = $this->duration;
        }

        return $parameters;
    }

    /**
     * Returns the list of initial parameters for this format.
     *
     * @return array
     */
    public function getInitialParameters(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getExtraParams(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function getAvailableAudioCodecs(): array
    {
        return [];
    }
}
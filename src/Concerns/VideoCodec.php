<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/**
 * Forward the video encoder settings to the pending export the builder holds in $export.
 *
 * @internal
 */
trait VideoCodec
{
    /**
     * Set the video encoder, bitrate, CRF and preset.
     */
    public function video(
        ?string $codec = null,
        mixed $bitrate = null,
        ?float $crf = null,
        ?string $preset = null,
    ): self {
        $this->export->video($codec, $bitrate, $crf, $preset);

        return $this;
    }

    /**
     * Set allowed encoder options for every output video track.
     */
    public function videoOptions(array $tokens): self
    {
        $this->export->videoOptions($tokens);

        return $this;
    }

    /**
     * Override the encoding of one output video track.
     *
     * Tracks count in mapped output order, not input stream order.
     */
    public function videoStream(int $track, callable $configure): self
    {
        $this->export->videoStream($track, $configure);

        return $this;
    }

    /**
     * Signal the color characteristics of the output video.
     *
     * This does not transform pixel values.
     */
    public function color(string $primaries, string $transfer, string $space, string $range = 'tv'): self
    {
        $this->export->color($primaries, $transfer, $space, $range);

        return $this;
    }
}

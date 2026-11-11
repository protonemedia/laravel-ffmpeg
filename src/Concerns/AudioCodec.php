<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/**
 * Forward the audio encoder settings to the pending export the builder holds in $export.
 *
 * @internal
 */
trait AudioCodec
{
    /**
     * Set the audio encoder and bitrate.
     */
    public function audio(?string $codec = null, mixed $bitrate = null): static
    {
        $this->export->audio($codec, $bitrate);

        return $this;
    }

    /**
     * Set allowed encoder options for every output audio track.
     */
    public function audioOptions(array $tokens): static
    {
        $this->export->audioOptions($tokens);

        return $this;
    }

    /**
     * Override the encoding of one output audio track.
     *
     * Tracks count in mapped output order, not input stream order.
     */
    public function audioStream(int $track, callable $configure): static
    {
        $this->export->audioStream($track, $configure);

        return $this;
    }
}

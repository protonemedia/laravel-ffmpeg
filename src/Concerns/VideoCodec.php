<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/** @internal Video encoder settings. Forwards to the PendingExport the builder holds in $export. */
trait VideoCodec
{
    public function video(?string $codec = null, mixed $bitrate = null, ?float $crf = null, ?string $preset = null): self
    {
        $this->export->video($codec, $bitrate, $crf, $preset);

        return $this;
    }

    public function videoOptions(array $tokens): self
    {
        $this->export->videoOptions($tokens);

        return $this;
    }

    /** Override encoding in mapped output video order, not input stream order. */
    public function videoStream(int $track, callable $configure): self
    {
        $this->export->videoStream($track, $configure);

        return $this;
    }

    /** Signal color characteristics; this does not transform pixel values. */
    public function color(string $primaries, string $transfer, string $space, string $range = 'tv'): self
    {
        $this->export->color($primaries, $transfer, $space, $range);

        return $this;
    }
}

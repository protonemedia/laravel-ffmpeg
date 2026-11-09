<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/** @internal Video and audio fades. Forwards to the PendingExport the builder holds in $export. */
trait Fades
{
    /** Fade both video and audio by default, starting at zero seconds. */
    public function fadeIn(float $duration, float $start = 0, bool $video = true, bool $audio = true): self
    {
        $this->export->fadeIn($duration, $start, $video, $audio);

        return $this;
    }

    /** Fade both video and audio by default; start is an explicit time in seconds. */
    public function fadeOut(float $duration, float $start, bool $video = true, bool $audio = true): self
    {
        $this->export->fadeOut($duration, $start, $video, $audio);

        return $this;
    }
}

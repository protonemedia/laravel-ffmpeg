<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/**
 * Forward the video and audio fades to the pending export the builder holds in $export.
 *
 * @internal
 */
trait Fades
{
    /**
     * Fade the video and audio in, starting at zero seconds by default.
     */
    public function fadeIn(float $duration, float $start = 0, bool $video = true, bool $audio = true): self
    {
        $this->export->fadeIn($duration, $start, $video, $audio);

        return $this;
    }

    /**
     * Fade the video and audio out from the given start time in seconds.
     */
    public function fadeOut(float $duration, float $start, bool $video = true, bool $audio = true): self
    {
        $this->export->fadeOut($duration, $start, $video, $audio);

        return $this;
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/**
 * Forward the output clipping to the pending export the builder holds in $export.
 *
 * @internal
 */
trait Clips
{
    /**
     * Clip the output to the given start and a duration or an end, or to the end of the media.
     */
    public function clip(
        float|string $start,
        float|string|null $duration = null,
        float|string|null $end = null,
        string $seek = 'accurate',
    ): static {
        $this->export->clip($start, $duration, $end, $seek);

        return $this;
    }
}

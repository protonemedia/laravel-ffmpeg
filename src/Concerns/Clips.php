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
     * Clip the output to the given start and either a duration or an end.
     */
    public function clip(
        float|string $start,
        float|string|null $duration = null,
        float|string|null $end = null,
        string $seek = 'accurate',
    ): self {
        $this->export->clip($start, $duration, $end, $seek);

        return $this;
    }
}

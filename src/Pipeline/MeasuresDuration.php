<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/**
 * Adjusts the presentation duration used for progress reporting.
 *
 * @internal
 */
interface MeasuresDuration
{
    /**
     * Get the duration in seconds, given the one PendingExport derived.
     */
    public function duration(array $metadata, ?float $default): ?float;
}

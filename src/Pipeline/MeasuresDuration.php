<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/** @internal Adjusts the presentation duration used for progress, after PendingExport derives its own. */
interface MeasuresDuration
{
    public function duration(array $metadata, ?float $default): ?float;
}

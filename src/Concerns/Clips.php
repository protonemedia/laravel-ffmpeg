<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/** @internal Output clipping. Forwards to the PendingExport the builder holds in $export. */
trait Clips
{
    public function clip(float|string $start, float|string|null $duration = null, float|string|null $end = null, string $seek = 'accurate'): self
    {
        $this->export->clip($start, $duration, $end, $seek);

        return $this;
    }
}

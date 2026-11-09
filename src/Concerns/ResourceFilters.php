<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use ProtoneMedia\LaravelFFMpeg\Media;

/** @internal Video filters that stage their own resources. Forwards to the PendingExport the builder holds in $export. */
trait ResourceFilters
{
    public function drawText(string $text, Media $font, int $size = 24, int $x = 0, int $y = 0, string $color = 'white'): self
    {
        $this->export->drawText($text, $font, $size, $x, $y, $color);

        return $this;
    }

    public function burnSubtitles(Media $subtitles): self
    {
        $this->export->burnSubtitles($subtitles);

        return $this;
    }

    public function lut3d(Media $look): self
    {
        $this->export->lut3d($look);

        return $this;
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use ProtoneMedia\LaravelFFMpeg\Media;

/**
 * Forward the video filters that stage their own resources to the pending export the builder holds in $export.
 *
 * @internal
 */
trait ResourceFilters
{
    /**
     * Draw the given text on the video with the given font.
     */
    public function drawText(
        string $text,
        Media $font,
        int $size = 24,
        int $x = 0,
        int $y = 0,
        string $color = 'white',
    ): static {
        $this->export->drawText($text, $font, $size, $x, $y, $color);

        return $this;
    }

    /**
     * Burn the given subtitles into the video.
     */
    public function burnSubtitles(Media $subtitles): static
    {
        $this->export->burnSubtitles($subtitles);

        return $this;
    }

    /**
     * Apply the given 3D LUT file to the video.
     */
    public function lut3d(Media $look): static
    {
        $this->export->lut3d($look);

        return $this;
    }
}

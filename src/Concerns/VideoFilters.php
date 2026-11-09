<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/** @internal Output video filters and geometry helpers. Forwards to the PendingExport the builder holds in $export. */
trait VideoFilters
{
    public function videoFilter(string $expression): self
    {
        $this->export->videoFilter($expression);

        return $this;
    }

    /** One dimension preserves display aspect ratio; two dimensions stretch. */
    public function resize(?int $width = null, ?int $height = null): self
    {
        $this->export->resize($width, $height);

        return $this;
    }

    /** Fit display geometry into an exact square-pixel canvas. */
    public function fit(int $width, int $height, string $mode = 'contain'): self
    {
        $this->export->fit($width, $height, $mode);

        return $this;
    }

    /** Clockwise quarter turns, applied in filter order after FFmpeg's input autorotation. */
    public function rotate(int $degrees): self
    {
        $this->export->rotate($degrees);

        return $this;
    }

    public function crop(int $width, int $height, ?int $x = null, ?int $y = null): self
    {
        $this->export->crop($width, $height, $x, $y);

        return $this;
    }

    public function pad(int $width, int $height, ?int $x = null, ?int $y = null, string $color = 'black'): self
    {
        $this->export->pad($width, $height, $x, $y, $color);

        return $this;
    }

    /** Resample video frames in filter order, without changing playback speed. */
    public function frameRate(int|float|string $rate): self
    {
        $this->export->frameRate($rate);

        return $this;
    }

    public function flipHorizontally(): self
    {
        $this->export->flipHorizontally();

        return $this;
    }

    public function flipVertically(): self
    {
        $this->export->flipVertically();

        return $this;
    }

    public function grayscale(): self
    {
        $this->export->grayscale();

        return $this;
    }

    public function deinterlace(string $mode = 'frame', string $parity = 'auto', bool $onlyInterlaced = true): self
    {
        $this->export->deinterlace($mode, $parity, $onlyInterlaced);

        return $this;
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/**
 * Forward the output video filters and geometry helpers to the pending export the builder holds in $export.
 *
 * @internal
 */
trait VideoFilters
{
    /**
     * Add a filter to the output video chain.
     */
    public function videoFilter(string $expression): self
    {
        $this->export->videoFilter($expression);

        return $this;
    }

    /**
     * Resize the video to the given width, height, or both.
     *
     * One dimension preserves the display aspect ratio, while two dimensions stretch the video.
     */
    public function resize(?int $width = null, ?int $height = null): self
    {
        $this->export->resize($width, $height);

        return $this;
    }

    /**
     * Scale the video to fill an exact square-pixel canvas, cropping the centered overflow.
     */
    public function cover(int $width, int $height): self
    {
        $this->export->cover($width, $height);

        return $this;
    }

    /**
     * Scale the video to fit inside an exact square-pixel canvas, padding the centered remainder.
     */
    public function contain(int $width, int $height, string $background = 'black'): self
    {
        $this->export->contain($width, $height, $background);

        return $this;
    }

    /**
     * Rotate the video clockwise by the given multiple of 90 degrees.
     *
     * The rotation runs in filter order, after FFmpeg's input autorotation.
     */
    public function rotate(int $angle): self
    {
        $this->export->rotate($angle);

        return $this;
    }

    /**
     * Crop the video to the given size, centered unless an offset is given.
     */
    public function crop(int $width, int $height, ?int $x = null, ?int $y = null): self
    {
        $this->export->crop($width, $height, $x, $y);

        return $this;
    }

    /**
     * Pad the video to the given size, centered unless an offset is given.
     */
    public function pad(int $width, int $height, ?int $x = null, ?int $y = null, string $background = 'black'): self
    {
        $this->export->pad($width, $height, $x, $y, $background);

        return $this;
    }

    /**
     * Resample the video to the given frame rate.
     *
     * Frames are resampled in filter order without changing the playback speed.
     */
    public function frameRate(int|float|string $rate): self
    {
        $this->export->frameRate($rate);

        return $this;
    }

    /**
     * Flip the video horizontally.
     */
    public function flipHorizontally(): self
    {
        $this->export->flipHorizontally();

        return $this;
    }

    /**
     * Flip the video vertically.
     */
    public function flipVertically(): self
    {
        $this->export->flipVertically();

        return $this;
    }

    /**
     * Convert the video to grayscale.
     */
    public function grayscale(): self
    {
        $this->export->grayscale();

        return $this;
    }

    /**
     * Deinterlace the video, which must happen before any geometry helpers.
     *
     * Frame mode keeps the frame count, while FFmpeg's default field mode outputs a frame for each field.
     */
    public function deinterlace(string $mode = 'frame', string $parity = 'auto', bool $onlyInterlaced = true): self
    {
        $this->export->deinterlace($mode, $parity, $onlyInterlaced);

        return $this;
    }
}

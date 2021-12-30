<?php

namespace ProtoneMedia\LaravelFFMpeg\Filters;

use Closure;

class TileFactory
{
    public float $interval = 0;

    public int $width  = -1;
    public int $height = -1;
    public int $columns;
    public int $rows;
    public int $padding = 0;
    public int $margin  = 0;

    public ?int $quality = null;

    public ?string $vttOutputPath       = null;
    public ?Closure $vttSequnceFilename = null;

    public static function make(): TileFactory
    {
        return new static;
    }

    /**
     * Setter for the output path of the VTT file and
     * the resolver for the tile sequence.
     *
     * @param string $outputPath
     * @param Closure|string $sequnceFilename
     * @return self
     */
    public function generateVTT(string $outputPath, $sequnceFilename = null): self
    {
        $this->vttOutputPath = $outputPath;

        $this->vttSequnceFilename = is_string($sequnceFilename)
            ? fn () => $sequnceFilename
            : $sequnceFilename;

        return $this;
    }

    public function interval(float $interval): self
    {
        $this->interval = $interval;

        return $this;
    }

    public function width(int $width): self
    {
        $this->width = $width;

        return $this;
    }

    public function height(int $height): self
    {
        $this->height = $height;

        return $this;
    }

    public function scale(int $width = null, int $height = null): self
    {
        return $this->width($width ?: -1)->height($height ?: -1);
    }

    public function grid(int $columns, int $rows): self
    {
        $this->columns = $columns;
        $this->rows    = $rows;

        return $this;
    }

    public function padding(int $padding): self
    {
        $this->padding = $padding;

        return $this;
    }

    public function margin(int $margin): self
    {
        $this->margin = $margin;

        return $this;
    }

    public function quality(int $quality = null): self
    {
        $this->quality = $quality;

        return $this;
    }

    /**
     * Returns a new instance of the TileFilter.
     *
     * @return \ProtoneMedia\LaravelFFMpeg\Filters\TileFilter
     */
    public function get(): TileFilter
    {
        return new TileFilter(
            $this->interval,
            $this->width,
            $this->height,
            $this->columns,
            $this->rows,
            $this->padding,
            $this->margin,
            $this->quality
        );
    }
}

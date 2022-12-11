<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Closure;
use Illuminate\Support\Collection;
use ProtoneMedia\LaravelFFMpeg\Filters\TileFilter;

class VTTPreviewThumbnailsGenerator
{
    private TileFilter $tileFilter;
    private int $durationInSeconds;
    private Closure $sequenceFilenameResolver;

    public function __construct(TileFilter $tileFilter, int $durationInSeconds, Closure $sequenceFilenameResolver)
    {
        $this->tileFilter               = $tileFilter;
        $this->durationInSeconds        = $durationInSeconds;
        $this->sequenceFilenameResolver = $sequenceFilenameResolver;
    }

    /**
     * Returns the x,y,w,h position of the given thumb key.
     *
     * @param integer $thumbKey
     * @return string
     */
    private function getPositionOnTile(int $thumbKey): string
    {
        $row = (int) floor($thumbKey / $this->tileFilter->columns);

        $column = ($thumbKey - ($row * $this->tileFilter->columns)) % $this->tileFilter->columns;

        $dimension = $this->tileFilter->getCalculatedDimension();

        $width  = $dimension->getWidth();
        $height = $dimension->getHeight();

        // base position
        $x = $column * $width;
        $y = $row    * $height;

        // add margin
        $x += $this->tileFilter->margin;
        $y += $this->tileFilter->margin;

        // add padding
        $x += $this->tileFilter->padding * $column;
        $y += $this->tileFilter->padding * $row;

        return implode(',', [$x, $y, $width, $height]);
    }

    /**
     * Returns the formatted timestamp of the given thumb key.
     *
     * @param integer $thumbKey
     * @return string
     */
    private function getTimestamp(int $thumbKey): string
    {
        return sprintf(
            '%02d:%02d:%02d.000',
            ($thumbKey * $this->tileFilter->interval) / 3600,
            ($thumbKey * $this->tileFilter->interval) / 60 % 60,
            ($thumbKey * $this->tileFilter->interval)      % 60
        );
    }

    /**
     * Generates the WebVTT contents.
     *
     * @return string
     */
    public function getContents(): string
    {
        $thumbsPerTile = $this->tileFilter->rows * $this->tileFilter->columns;

        $totalFiles = ceil(
            ($this->durationInSeconds / $this->tileFilter->interval) / $thumbsPerTile
        );

        return Collection::range(1, $totalFiles * $thumbsPerTile)
            ->map(function ($thumb) use ($thumbsPerTile) {
                $start = $this->getTimestamp($thumb - 1, $this->tileFilter->interval);
                $end   = $this->getTimestamp($thumb, $this->tileFilter->interval);

                $fileKey = ceil($thumb / $thumbsPerTile);

                $filename = sprintf(
                    call_user_func($this->sequenceFilenameResolver, $fileKey),
                    $fileKey
                );

                $positionOnTile = ($thumb - 1) % $thumbsPerTile;
                $position       = $this->getPositionOnTile($positionOnTile);

                return implode(PHP_EOL, [
                    "{$start} --> {$end}",
                    "{$filename}#xywh={$position}",
                ]);
            })
            ->prepend("WEBVTT")
            ->implode(PHP_EOL . PHP_EOL);
    }
}

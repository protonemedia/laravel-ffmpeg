<?php

namespace ProtoneMedia\LaravelFFMpeg\Filters;

use FFMpeg\Coordinate\Dimension;
use FFMpeg\FFProbe\DataMapping\Stream;
use FFMpeg\Filters\Video\VideoFilterInterface;
use FFMpeg\Format\VideoInterface;
use FFMpeg\Media\Video;
use ProtoneMedia\LaravelFFMpeg\Support\StreamParser;

/**
 * Inspired by: https://github.com/protonemedia/laravel-ffmpeg/issues/335
 */
class TileFilter implements VideoFilterInterface
{
    public float $interval;
    public int $width;
    public int $height;
    public int $columns;
    public int $rows;
    public int $padding  = 0;
    public int $margin   = 0;
    public ?int $quality = null;
    public int $priority = 0;

    public ?Dimension $calculatedDimension = null;

    public function __construct(
        float $interval,
        int $width,
        int $height,
        int $columns,
        int $rows,
        int $padding = 0,
        int $margin = 0,
        ?int $quality = null,
        int $priority = 0
    ) {
        $this->interval = $interval;
        $this->width    = $width;
        $this->height   = $height;
        $this->columns  = $columns;
        $this->rows     = $rows;
        $this->padding  = $padding;
        $this->margin   = $margin;
        $this->quality  = $quality;
        $this->priority = $priority;
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority()
    {
        return $this->priority;
    }

    /**
     * Get name of the filter.
     *
     * @return string
     */
    public function getName()
    {
        return 'thumbnail_sprite';
    }

    /**
     * Get minimal version of ffmpeg starting with which this filter is supported.
     *
     * @return string
     */
    public function getMinimalFFMpegVersion()
    {
        return '4.3';
    }

    /**
     * {@inheritdoc}
     */
    public function apply(Video $video, VideoInterface $format)
    {
        return $this->getCommands(
            $video->getStreams()->videos()->first()
        );
    }

    public function getCalculatedDimension(): Dimension
    {
        return $this->calculatedDimension ?: new Dimension($this->width, $this->height);
    }

    private function calculateDimension(Dimension $streamDimension): Dimension
    {
        $width  = $this->width;
        $height = $this->height;

        if ($width > 0 && $height < 1) {
            $height = $streamDimension->getRatio()->calculateHeight($width);
        } elseif ($height > 0 && $width < 1) {
            $width = $streamDimension->getRatio()->calculateWidth($height);
        } elseif ($width < 1 && $height < 1) {
            $width  = $streamDimension->getWidth();
            $height = $streamDimension->getHeight();
        }

        return $this->calculatedDimension = new Dimension($width, $height);
    }

    /**
     * @return array
     */
    protected function getCommands(Stream $stream)
    {
        $frameRateInterval = null;

        if ($frameRate = StreamParser::new($stream)->getFrameRate()) {
            $frameRateInterval = round($frameRate * $this->interval);
        }

        $dimension = $this->calculateDimension($stream->getDimensions());

        $select = $frameRateInterval
            ? "select=not(mod(n\,{$frameRateInterval}))"
            : "select=not(mod(t\,{$this->interval}))";

        $commands = [
            '-vsync',
            '0',
        ];

        if (!is_null($this->quality)) {
            $commands = array_merge($commands, [
                '-qscale:v',
                $this->quality,
            ]);
        }

        $commands = array_merge($commands, [
            '-vf',
            "{$select},scale={$dimension->getWidth()}:{$dimension->getHeight()},tile={$this->columns}x{$this->rows}:margin={$this->margin}:padding={$this->padding}",
        ]);

        return $commands;
    }
}

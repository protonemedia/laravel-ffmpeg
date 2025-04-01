<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use FFMpeg\Coordinate\Dimension;
use FFMpeg\Filters\AdvancedMedia\ComplexFilters;
use FFMpeg\Filters\Video\ResizeFilter;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFactory;

class HLSVideoFilters
{
    public const MAPPING_GLUE = "_hls_";

    /**
     * @var \ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg
     */
    private $driver;

    /**
     * Key of the video in the HLS export.
     *
     * @var int
     */
    private $formatKey;

    /**
     * Number of filters added to this video.
     *
     * @var integer
     */
    private $filterCount = 0;

    public function __construct(PHPFFMpeg $driver, int $formatKey)
    {
        $this->driver    = $driver;
        $this->formatKey = $formatKey;
    }

    public function count(): int
    {
        return $this->filterCount;
    }

    private function increaseFilterCount(): self
    {
        $this->filterCount++;

        return $this;
    }

    /**
     * Generates an input mapping for a new filter.
     *
     * @return string
     */
    private function input(): string
    {
        return $this->filterCount ? static::glue($this->formatKey, $this->filterCount) : '[0]';
    }

    /**
     * Generates an output mapping for a new filter.
     *
     * @return string
     */
    private function output(): string
    {
        return static::glue($this->formatKey, $this->filterCount + 1);
    }

    /**
     * Adds a filter as a complex filter.
     */
    public function addLegacyFilter(...$arguments): self
    {
        $this->driver->addFilterAsComplexFilter($this->input(), $this->output(), ...$arguments);

        return $this->increaseFilterCount();
    }

    /**
     * Shortcut for the ResizeFilter.
     *
     * @param int $width
     * @param int $height
     * @param string $mode
     * @param boolean $forceStandards
     * @return self
     */
    public function resize($width, $height, $mode = ResizeFilter::RESIZEMODE_FIT, $forceStandards = true): self
    {
        $dimension = new Dimension($width, $height);

        $filter = new ResizeFilter($dimension, $mode, $forceStandards);

        return $this->addLegacyFilter($filter);
    }

    /**
     * Shortcut for the WatermarkFactory.
     *
     * @param callable $withWatermarkFactory
     * @return self
     */
    public function addWatermark(callable $withWatermarkFactory): self
    {
        $withWatermarkFactory($watermarkFactory = new WatermarkFactory());

        return $this->addLegacyFilter($watermarkFactory->get());
    }

    /**
     * Adds a scale filter to the video, will be replaced in favor of resize().
     *
     * @param int $width
     * @param int $height
     * @deprecated 7.4.0
     * @return self
     */
    public function scale($width, $height): self
    {
        return $this->addFilter("scale={$width}:{$height}");
    }

    /**
     * Adds a filter object or a callable to the driver and automatically
     * chooses the right input and output mapping.
     */
    public function addFilter(...$arguments): self
    {
        if (count($arguments) === 1 && !is_callable($arguments[0])) {
            $this->driver->addFilter($this->input(), $arguments[0], $this->output());
        } else {
            $this->driver->addFilter(function (ComplexFilters $filters) use ($arguments) {
                $arguments[0]($filters, $this->input(), $this->output());
            });
        }

        return $this->increaseFilterCount();
    }

    public static function glue($format, $filter): string
    {
        return "[v{$format}" . static::MAPPING_GLUE . "{$filter}]";
    }

    public static function beforeGlue($input): string
    {
        return Str::before($input, static::MAPPING_GLUE);
    }
};

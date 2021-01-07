<?php

namespace ProtoneMedia\LaravelFFMpeg\Drivers;

use Closure;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Filters\Audio\SimpleFilter;
use FFMpeg\Filters\FilterInterface;
use FFMpeg\Filters\Video\ResizeFilter;
use Illuminate\Support\Collection;
use ProtoneMedia\LaravelFFMpeg\Exporters\MediaExporter;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\LegacyFilterMapping;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFactory;

trait InteractsWithFilters
{
    /**
     * @var \Illuminate\Support\Collection
     */
    protected $pendingComplexFilters;

    /**
     * Returns an array with the filters applied to the underlying media object.
     *
     * @return array
     */
    public function getFilters(): array
    {
        return iterator_to_array($this->media->getFiltersCollection());
    }

    /**
     * Helper method to provide multiple ways to add a filter to the underlying
     * media object.
     *
     * @return self
     */
    public function addFilter(): self
    {
        $arguments = func_get_args();

        // to support '[in]filter[out]' complex filters
        if ($this->isAdvancedMedia() && count($arguments) === 3) {
            $this->media->filters()->custom(...$arguments);

            return $this;
        }

        // use a callback to add a filter
        if ($arguments[0] instanceof Closure) {
            call_user_func_array($arguments[0], [$this->media->filters()]);

            return $this;
        }

        // use an object to add a filter
        if ($arguments[0] instanceof FilterInterface) {
            call_user_func_array([$this->media, 'addFilter'], $arguments);

            return $this;
        }

        // use a single array with parameters to define a filter
        if (is_array($arguments[0])) {
            $this->media->addFilter(new SimpleFilter($arguments[0]));

            return $this;
        }

        // use all function arguments as a filter
        $this->media->addFilter(new SimpleFilter($arguments));

        return $this;
    }

    /**
     * Calls the callable with a WatermarkFactory instance and
     * adds the freshly generated WatermarkFilter.
     *
     * @param callable $withWatermarkFactory
     * @return self
     */
    public function addWatermark(callable $withWatermarkFactory): self
    {
        $withWatermarkFactory(
            $watermarkFactory = new WatermarkFactory
        );

        return $this->addFilter($watermarkFactory->get());
    }

    /**
     * Shortcut for adding a Resize filter.
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

        return $this->addFilter($filter);
    }

    /**
     * Maps the arguments into a 'LegacyFilterMapping' instance and
     * pushed it to the 'pendingComplexFilters' collection. These
     * filters will be applied later on by the MediaExporter.
     */
    public function addFilterAsComplexFilter($in, $out, ...$arguments): self
    {
        $this->pendingComplexFilters->push(new LegacyFilterMapping(
            $in,
            $out,
            ...$arguments
        ));

        return $this;
    }

    /**
     * Getter for the pending complex filters.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getPendingComplexFilters(): Collection
    {
        return $this->pendingComplexFilters;
    }
}

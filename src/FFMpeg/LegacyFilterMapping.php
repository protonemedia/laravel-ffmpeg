<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSVideoFilters;
use ProtoneMedia\LaravelFFMpeg\Filesystem\MediaCollection;

class LegacyFilterMapping
{
    private $in;
    private $out;

    /**
     * @var array
     */
    private $arguments;

    public function __construct($in, $out, ...$arguments)
    {
        $this->in        = $in;
        $this->out       = $out;
        $this->arguments = $arguments;
    }

    /**
     * Removes all non-numeric characters from the 'in' attribute.
     */
    public function normalizeIn(): int
    {
        return preg_replace("/[^0-9]/", "", HLSVideoFilters::beforeGlue($this->in));
    }

    /**
     * Filters the given MediaCollection down to one item by
     * guessing the key by the 'in' attribute.
     */
    private function singleMediaCollection(MediaCollection $mediaCollection): MediaCollection
    {
        $media = $mediaCollection->get($this->normalizeIn()) ?: $mediaCollection->first();

        return MediaCollection::make([$media]);
    }

    /**
     * Applies the filter to the FFMpeg driver.
     */
    public function apply(PHPFFMpeg $driver, Collection $maps): void
    {
        $freshDriver = $driver->fresh()
            ->open($this->singleMediaCollection($driver->getMediaCollection()))
            ->addFilter(...$this->arguments);

        $format = $maps->filter->hasOut($this->out)->first()->getFormat();

        Collection::make($freshDriver->getFilters())->map(function ($filter) use ($freshDriver, $format) {
            $parameters = $filter->apply($freshDriver->get(), $format);

            $parameters = Arr::where($parameters, function ($parameter) {
                return !in_array($parameter, ['-vf', '-filter:v', '-filter_complex']);
            });

            return implode(' ', $parameters);
        })->each(function ($customCompiledFilter) use ($driver) {
            $driver->addFilter($this->in, $customCompiledFilter, $this->out);
        });
    }
}

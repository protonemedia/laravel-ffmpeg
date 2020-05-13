<?php

namespace Pbmedia\LaravelFFMpeg\FFMpeg;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Pbmedia\LaravelFFMpeg\Drivers\DriverInterface;
use Pbmedia\LaravelFFMpeg\Filesystem\MediaCollection;

class BasicFilterMapping
{
    private $in;
    private $out;
    private array $arguments;

    public function __construct($in, $out, ...$arguments)
    {
        $this->in        = $in;
        $this->out       = $out;
        $this->arguments = $arguments;
    }

    public function normalizeIn(): int
    {
        return preg_replace("/[^0-9]/", "", $this->in);
    }

    private function singleMediaCollection(MediaCollection $mediaCollection): MediaCollection
    {
        $media = $mediaCollection->get($this->normalizeIn()) ?: $mediaCollection->first();

        return MediaCollection::make([$media]);
    }

    public function apply(DriverInterface $driver, Collection $maps)
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

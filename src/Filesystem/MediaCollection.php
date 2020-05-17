<?php

namespace Pbmedia\LaravelFFMpeg\Filesystem;

use Illuminate\Support\Collection;
use Illuminate\Support\Traits\ForwardsCalls;

/**
 * Mostly a wrapper around the Collection class.
 */
class MediaCollection
{
    use ForwardsCalls;

    private Collection $items;

    public function __construct(array $items = [])
    {
        $this->items = new Collection($items);
    }

    public static function make(array $items = []): self
    {
        return new static($items);
    }

    public function add(Media $media): self
    {
        $this->items->push($media);

        return $this;
    }

    public function getLocalPaths(): array
    {
        return $this->items->map->getLocalPath()->all();
    }

    public function collection(): Collection
    {
        return $this->items;
    }

    public function __call($method, $parameters)
    {
        return $this->forwardCallTo($this->collection(), $method, $parameters);
    }
}

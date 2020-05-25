<?php

namespace ProtoneMedia\LaravelFFMpeg\Filesystem;

use Illuminate\Support\Collection;
use Illuminate\Support\Traits\ForwardsCalls;

/**
 * @mixin \Illuminate\Support\Collection
 */
class MediaCollection
{
    use ForwardsCalls;

    /**
     * @var \Illuminate\Support\Collection
     */
    private $items;

    public function __construct(array $items = [])
    {
        $this->items = new Collection($items);
    }

    public static function make(array $items = []): self
    {
        return new static($items);
    }

    /**
     * Returns an array with all locals paths of the Media items.
     */
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

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

    /**
     * Returns an array with all headers of the Media items.
     */
    public function getHeaders(): array
    {
        return $this->items->map(function ($media) {
            return $media instanceof MediaOnNetwork ? $media->getHeaders() : [];
        })->all();
    }

    public function collection(): Collection
    {
        return $this->items;
    }

    /**
     * Count the number of items in the collection.
     *
     * @return int
     */
    public function count(): int
    {
        return $this->items->count();
    }

    public function __call($method, $parameters)
    {
        return $this->forwardCallTo($this->collection(), $method, $parameters);
    }
}

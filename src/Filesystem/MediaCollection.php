<?php

namespace Pbmedia\LaravelFFMpeg\Filesystem;

use Illuminate\Support\Collection;

class MediaCollection
{
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

    public function count(): int
    {
        return $this->items->count();
    }

    public function get($key): Media
    {
        return $this->items->get($key);
    }

    public function first(): Media
    {
        return $this->items->first();
    }

    public function last(): Media
    {
        return $this->items->last();
    }
}

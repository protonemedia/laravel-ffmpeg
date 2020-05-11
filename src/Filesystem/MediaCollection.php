<?php

namespace Pbmedia\LaravelFFMpeg\Filesystem;

use Illuminate\Support\Collection;

class MediaCollection
{
    private Collection $items;

    public function __construct()
    {
        $this->items = new Collection;
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

    public function first(): Media
    {
        return $this->items->first();
    }

    public function last(): Media
    {
        return $this->items->last();
    }
}

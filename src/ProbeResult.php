<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use JsonSerializable;

/** Raw FFprobe sections. Counts and timestamps describe the bounded interval, not the whole asset. */
readonly class ProbeResult implements Arrayable, JsonSerializable
{
    public function __construct(protected array $data, protected array $bounds) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function bounds(): array
    {
        return $this->bounds;
    }

    /** @return Collection<int, array> */
    public function streams(): Collection
    {
        return collect($this->data['streams'] ?? []);
    }

    /** @return Collection<int, array> */
    public function packets(): Collection
    {
        return collect($this->data['packets'] ?? []);
    }

    /** @return Collection<int, array> */
    public function frames(): Collection
    {
        return collect($this->data['frames'] ?? []);
    }
}

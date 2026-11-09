<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use JsonSerializable;

/**
 * The raw sections returned by a bounded FFprobe query.
 * Counts and timestamps describe the bounded interval, not the whole asset.
 */
readonly class ProbeResult implements Arrayable, JsonSerializable
{
    /**
     * Create a new probe result instance.
     */
    public function __construct(protected array $data, protected array $bounds) {}

    /**
     * Get a value from the raw sections using "dot" notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Convert the object into something JSON serializable.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get the limits the query ran with.
     */
    public function bounds(): array
    {
        return $this->bounds;
    }

    /**
     * Get the stream rows.
     *
     * @return Collection<int, array>
     */
    public function streams(): Collection
    {
        return collect($this->data['streams'] ?? []);
    }

    /**
     * Get the packet rows.
     *
     * @return Collection<int, array>
     */
    public function packets(): Collection
    {
        return collect($this->data['packets'] ?? []);
    }

    /**
     * Get the frame rows.
     *
     * @return Collection<int, array>
     */
    public function frames(): Collection
    {
        return collect($this->data['frames'] ?? []);
    }
}

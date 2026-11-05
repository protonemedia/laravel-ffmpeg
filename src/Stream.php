<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Arr;

readonly class Stream
{
    public function __construct(private array $data) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function width(): ?int
    {
        $width = $this->get('width');

        return is_numeric($width) && is_finite((float) $width) && $width > 0 ? (int) $width : null;
    }

    public function height(): ?int
    {
        $height = $this->get('height');

        return is_numeric($height) && is_finite((float) $height) && $height > 0 ? (int) $height : null;
    }

    public function frameRate(): ?float
    {
        $rate = explode('/', (string) $this->get('avg_frame_rate', ''));

        if (count($rate) > 2 || ! is_numeric($rate[0]) || ! is_numeric($rate[1] ?? 1)
            || ($rate[1] ?? 1) <= 0) {
            return null;
        }

        $value = (float) $rate[0] / (float) ($rate[1] ?? 1);

        return is_finite($value) && $value > 0 ? $value : null;
    }
}

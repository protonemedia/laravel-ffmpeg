<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

readonly class Metadata
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

    public function duration(): ?float
    {
        $duration = $this->get('format.duration');

        return is_numeric($duration) && is_finite((float) $duration) && $duration >= 0 ? (float) $duration : null;
    }

    /** @return Collection<int, Stream> */
    public function streams(): Collection
    {
        return collect($this->data['streams'])->map(fn (array $stream) => new Stream($stream));
    }

    /** @return Collection<int, Stream> */
    public function videoStreams(): Collection
    {
        return $this->streams()->filter(fn (Stream $stream) => $stream->get('codec_type') === 'video'
            && ! $stream->get('disposition.attached_pic', false))->values();
    }

    /** @return Collection<int, Stream> */
    public function audioStreams(): Collection
    {
        return $this->streams()->filter(fn (Stream $stream) => $stream->get('codec_type') === 'audio')->values();
    }

    public function video(): ?Stream
    {
        return $this->videoStreams()->first();
    }

    public function audio(): ?Stream
    {
        return $this->audioStreams()->first();
    }
}

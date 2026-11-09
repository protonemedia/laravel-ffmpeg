<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

readonly class Metadata
{
    public function __construct(private array $data, private ?string $sourceIdentity = null) {}

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

    public function tag(string $key): ?string
    {
        $tags = $this->get('format.tags');
        $value = is_array($tags) ? ($tags[$key] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    /** @return Collection<int, array> */
    public function chapters(): Collection
    {
        return collect($this->get('chapters', []));
    }

    /** @return Collection<int, Stream> */
    public function streams(): Collection
    {
        return collect($this->data['streams'])->map(fn (array $stream): \ProtoneMedia\LaravelFFMpeg\Stream => new Stream($stream, $this->sourceIdentity));
    }

    /** @return Collection<int, Stream> */
    public function videoStreams(): Collection
    {
        return $this->streams()->filter(fn (Stream $stream): bool => $stream->type() === 'video'
            && ! $stream->isCoverArt())->values();
    }

    /** @return Collection<int, Stream> */
    public function audioStreams(): Collection
    {
        return $this->streams()->filter(fn (Stream $stream): bool => $stream->type() === 'audio')->values();
    }

    /** @return Collection<int, Stream> */
    public function subtitleStreams(): Collection
    {
        return $this->streams()->filter(fn (Stream $stream): bool => $stream->type() === 'subtitle')->values();
    }

    /** @return Collection<int, Stream> */
    public function coverArt(): Collection
    {
        return $this->streams()->filter(fn (Stream $stream): bool => $stream->type() === 'video'
            && $stream->isCoverArt())->values();
    }

    public function hasVideo(): bool
    {
        return $this->video() instanceof \ProtoneMedia\LaravelFFMpeg\Stream;
    }

    public function hasAudio(): bool
    {
        return $this->audio() instanceof \ProtoneMedia\LaravelFFMpeg\Stream;
    }

    public function video(): ?Stream
    {
        return $this->videoStreams()->first();
    }

    public function audio(): ?Stream
    {
        return $this->audioStreams()->first();
    }

    public function subtitle(): ?Stream
    {
        return $this->subtitleStreams()->first();
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * The metadata of a media source as reported by FFprobe.
 */
class Metadata
{
    /**
     * Create a new metadata instance.
     */
    public function __construct(protected readonly array $data, protected readonly ?string $sourceIdentity = null) {}

    /**
     * Get a value from the raw probe data using "dot" notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    /**
     * Get the raw probe data.
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Get the duration of the media in seconds.
     */
    public function duration(): ?float
    {
        $duration = $this->get('format.duration');

        return is_numeric($duration) && is_finite((float) $duration) && $duration >= 0 ? (float) $duration : null;
    }

    /**
     * Get the value of the given container tag.
     */
    public function tag(string $key): ?string
    {
        $tags = $this->get('format.tags');
        $value = is_array($tags) ? ($tags[$key] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    /**
     * Get the chapters of the media.
     *
     * @return Collection<int, array>
     */
    public function chapters(): Collection
    {
        return collect($this->get('chapters', []));
    }

    /**
     * Get every stream of the media.
     *
     * @return Collection<int, Stream>
     */
    public function streams(): Collection
    {
        return collect($this->data['streams'])
            ->map(fn (array $stream): Stream => new Stream($stream, $this->sourceIdentity));
    }

    /**
     * Get the video streams, excluding cover art.
     *
     * @return Collection<int, Stream>
     */
    public function videoStreams(): Collection
    {
        return $this->streams()
            ->filter(fn (Stream $stream): bool => $stream->type() === 'video' && ! $stream->isCoverArt())
            ->values();
    }

    /**
     * Get the audio streams.
     *
     * @return Collection<int, Stream>
     */
    public function audioStreams(): Collection
    {
        return $this->streams()->filter(fn (Stream $stream): bool => $stream->type() === 'audio')->values();
    }

    /**
     * Get the subtitle streams.
     *
     * @return Collection<int, Stream>
     */
    public function subtitleStreams(): Collection
    {
        return $this->streams()->filter(fn (Stream $stream): bool => $stream->type() === 'subtitle')->values();
    }

    /**
     * Get the attached cover art streams.
     *
     * @return Collection<int, Stream>
     */
    public function coverArt(): Collection
    {
        return $this->streams()
            ->filter(fn (Stream $stream): bool => $stream->type() === 'video' && $stream->isCoverArt())
            ->values();
    }

    /**
     * Determine if the media has a video stream other than cover art.
     */
    public function hasVideo(): bool
    {
        return $this->video() instanceof Stream;
    }

    /**
     * Determine if the media has an audio stream.
     */
    public function hasAudio(): bool
    {
        return $this->audio() instanceof Stream;
    }

    /**
     * Get the first video stream.
     */
    public function video(): ?Stream
    {
        return $this->videoStreams()->first();
    }

    /**
     * Get the first audio stream.
     */
    public function audio(): ?Stream
    {
        return $this->audioStreams()->first();
    }

    /**
     * Get the first subtitle stream.
     */
    public function subtitle(): ?Stream
    {
        return $this->subtitleStreams()->first();
    }
}

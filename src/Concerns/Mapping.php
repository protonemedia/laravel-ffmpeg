<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use ProtoneMedia\LaravelFFMpeg\Stream;

/**
 * Forward the stream selection to the pending export the builder holds in $export.
 *
 * @internal
 */
trait Mapping
{
    /**
     * Map the given input stream specifier or graph label to the output.
     */
    public function map(string $specifier): self
    {
        $this->export->map($specifier);

        return $this;
    }

    /**
     * Map a video stream from the given input to the output.
     */
    public function mapVideo(
        Stream|int|null $stream = null,
        ?int $input = null,
        ?int $track = null,
        bool $optional = false,
    ): self {
        $this->export->mapVideo($stream, $input, $track, $optional);

        return $this;
    }

    /**
     * Map an audio stream from the given input to the output.
     */
    public function mapAudio(
        Stream|int|null $stream = null,
        ?int $input = null,
        ?int $track = null,
        ?string $language = null,
        bool $optional = false,
    ): self {
        $this->export->mapAudio($stream, $input, $track, $language, $optional);

        return $this;
    }

    /**
     * Omit the audio from the output.
     */
    public function withoutAudio(): self
    {
        $this->export->withoutAudio();

        return $this;
    }

    /**
     * Omit the video from the output.
     */
    public function withoutVideo(): self
    {
        $this->export->withoutVideo();

        return $this;
    }
}

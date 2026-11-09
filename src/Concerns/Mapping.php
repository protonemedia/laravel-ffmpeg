<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use ProtoneMedia\LaravelFFMpeg\Stream;

/** @internal Stream selection. Forwards to the PendingExport the builder holds in $export. */
trait Mapping
{
    public function map(string $specifier): self
    {
        $this->export->map($specifier);

        return $this;
    }

    public function mapVideo(Stream|int|null $stream = null, ?int $input = null, ?int $track = null, bool $optional = false): self
    {
        $this->export->mapVideo($stream, $input, $track, $optional);

        return $this;
    }

    public function mapAudio(Stream|int|null $stream = null, ?int $input = null, ?int $track = null, ?string $language = null, bool $optional = false): self
    {
        $this->export->mapAudio($stream, $input, $track, $language, $optional);

        return $this;
    }

    public function withoutAudio(): self
    {
        $this->export->withoutAudio();

        return $this;
    }

    public function withoutVideo(): self
    {
        $this->export->withoutVideo();

        return $this;
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/** @internal Container metadata, chapters and dispositions. Forwards to the PendingExport the builder holds in $export. */
trait Metadata
{
    public function withoutMetadata(bool $chapters = false): self
    {
        $this->export->withoutMetadata($chapters);

        return $this;
    }

    public function metadataFrom(?int $input): self
    {
        $this->export->metadataFrom($input);

        return $this;
    }

    public function metadata(string $key, string $value): self
    {
        $this->export->metadata($key, $value);

        return $this;
    }

    public function streamMetadata(string|int $stream, string $key, string $value): self
    {
        $this->export->streamMetadata($stream, $key, $value);

        return $this;
    }

    public function disposition(string|int $stream, string|array $flags): self
    {
        $this->export->disposition($stream, $flags);

        return $this;
    }

    public function chaptersFrom(?int $input): self
    {
        $this->export->chaptersFrom($input);

        return $this;
    }
}

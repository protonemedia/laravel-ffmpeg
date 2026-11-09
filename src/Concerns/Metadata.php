<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/**
 * Forward the container metadata, chapters and dispositions to the pending export the builder holds in $export.
 *
 * @internal
 */
trait Metadata
{
    /**
     * Omit the container metadata, and optionally the chapters, from the output.
     */
    public function withoutMetadata(bool $chapters = false): self
    {
        $this->export->withoutMetadata($chapters);

        return $this;
    }

    /**
     * Copy the container metadata from the given input, or omit it when the input is null.
     */
    public function metadataFrom(?int $input): self
    {
        $this->export->metadataFrom($input);

        return $this;
    }

    /**
     * Set a container metadata tag on the output.
     */
    public function metadata(string $key, string $value): self
    {
        $this->export->metadata($key, $value);

        return $this;
    }

    /**
     * Set a metadata tag on the given output stream.
     */
    public function streamMetadata(string|int $stream, string $key, string $value): self
    {
        $this->export->streamMetadata($stream, $key, $value);

        return $this;
    }

    /**
     * Set the disposition flags of the given output stream.
     *
     * An empty list clears the flags.
     */
    public function disposition(string|int $stream, string|array $flags): self
    {
        $this->export->disposition($stream, $flags);

        return $this;
    }

    /**
     * Copy the chapters from the given input, or omit them when the input is null.
     */
    public function chaptersFrom(?int $input): self
    {
        $this->export->chaptersFrom($input);

        return $this;
    }
}

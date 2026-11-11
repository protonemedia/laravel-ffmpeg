<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use ProtoneMedia\LaravelFFMpeg\Media;

/**
 * Forward the files and text that video filters reference by name to the pending export the builder holds in $export.
 *
 * @internal
 */
trait Resources
{
    /**
     * Declare a file that video filters reference by the given name.
     */
    public function resource(string $name, Media $media): static
    {
        $this->export->resource($name, $media);

        return $this;
    }

    /**
     * Declare a UTF-8 text file that video filters reference by the given name.
     */
    public function textResource(string $name, string $text): static
    {
        $this->export->textResource($name, $text);

        return $this;
    }

    /**
     * Declare a subtitle file that video filters reference by the given name.
     */
    public function subtitleResource(string $name, Media $media): static
    {
        $this->export->subtitleResource($name, $media);

        return $this;
    }
}

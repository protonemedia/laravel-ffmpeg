<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use ProtoneMedia\LaravelFFMpeg\Media;

/** @internal Declare files and text that video filters reference by name. Forwards to the PendingExport the builder holds in $export. */
trait Resources
{
    public function resource(string $name, Media $media): self
    {
        $this->export->resource($name, $media);

        return $this;
    }

    public function textResource(string $name, string $text): self
    {
        $this->export->textResource($name, $text);

        return $this;
    }

    public function subtitleResource(string $name, Media $media): self
    {
        $this->export->subtitleResource($name, $media);

        return $this;
    }
}

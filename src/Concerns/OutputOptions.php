<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/** @internal Allowed raw output options. Forwards to the PendingExport the builder holds in $export. */
trait OutputOptions
{
    public function outputOptions(array $tokens): self
    {
        $this->export->outputOptions($tokens);

        return $this;
    }
}

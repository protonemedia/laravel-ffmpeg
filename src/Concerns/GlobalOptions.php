<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/** @internal Pass allowed global FFmpeg options. Forwards to the PendingExport the builder holds in $export. */
trait GlobalOptions
{
    public function globalOptions(array $tokens): self
    {
        $this->export->globalOptions($tokens);

        return $this;
    }
}

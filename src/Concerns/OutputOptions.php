<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/**
 * Forward the allowed raw output options to the pending export the builder holds in $export.
 *
 * @internal
 */
trait OutputOptions
{
    /**
     * Add allowed raw FFmpeg options to the output.
     */
    public function outputOptions(array $tokens): static
    {
        $this->export->outputOptions($tokens);

        return $this;
    }
}

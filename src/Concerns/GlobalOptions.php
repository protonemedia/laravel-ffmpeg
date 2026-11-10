<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/**
 * Forward the allowed global FFmpeg options to the pending export the builder holds in $export.
 *
 * @internal
 */
trait GlobalOptions
{
    /**
     * Add allowed global FFmpeg options to the command.
     */
    public function globalOptions(array $tokens): static
    {
        $this->export->globalOptions($tokens);

        return $this;
    }
}

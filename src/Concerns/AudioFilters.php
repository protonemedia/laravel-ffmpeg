<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/** @internal Output audio filters. Forwards to the PendingExport the builder holds in $export. */
trait AudioFilters
{
    public function audioFilter(string $expression): self
    {
        $this->export->audioFilter($expression);

        return $this;
    }

    /** Linear amplitude multiplier; zero mutes the audio. */
    public function volume(float $multiplier): self
    {
        $this->export->volume($multiplier);

        return $this;
    }

    /** Insert real silence on all channels, extending audio rather than shifting timestamps. */
    public function delayAudio(float $seconds): self
    {
        $this->export->delayAudio($seconds);

        return $this;
    }
}

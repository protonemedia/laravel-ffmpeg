<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

/**
 * Forward the output audio filters to the pending export the builder holds in $export.
 *
 * @internal
 */
trait AudioFilters
{
    /**
     * Add a filter to the output audio chain.
     */
    public function audioFilter(string $expression): static
    {
        $this->export->audioFilter($expression);

        return $this;
    }

    /**
     * Multiply the audio amplitude by the given linear factor.
     *
     * A factor of zero mutes the audio.
     */
    public function volume(float $multiplier): static
    {
        $this->export->volume($multiplier);

        return $this;
    }

    /**
     * Delay the audio by inserting silence on every channel.
     *
     * The silence extends the audio rather than shifting its timestamps.
     */
    public function delayAudio(float $seconds): static
    {
        $this->export->delayAudio($seconds);

        return $this;
    }
}

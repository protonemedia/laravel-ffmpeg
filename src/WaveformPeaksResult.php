<?php

namespace ProtoneMedia\LaravelFFMpeg;

/**
 * The published peaks file and the peaks it contains.
 */
readonly class WaveformPeaksResult implements Result
{
    use Concerns\ExportResultFiles;

    /**
     * Create a new waveform peaks result instance.
     */
    public function __construct(protected ExportResult $export, public WaveformPeaks $peaks) {}
}

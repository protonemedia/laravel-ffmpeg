<?php

namespace ProtoneMedia\LaravelFFMpeg;

/**
 * The published peaks file and the peaks it contains.
 */
class WaveformPeaksResult implements Result
{
    use Concerns\ExportResultFiles;

    /**
     * Create a new waveform peaks result instance.
     */
    public function __construct(protected readonly ExportResult $export, public readonly WaveformPeaks $peaks) {}
}

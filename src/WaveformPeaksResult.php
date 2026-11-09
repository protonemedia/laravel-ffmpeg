<?php

namespace ProtoneMedia\LaravelFFMpeg;

readonly class WaveformPeaksResult extends ExportResult
{
    public function __construct(ExportResult $export, public WaveformPeaks $peaks)
    {
        parent::__construct($export->files()->all(), $export->processes()->all());
    }
}

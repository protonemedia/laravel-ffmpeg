<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg;

readonly class WaveformPeaksResult implements Result
{
    use Concerns\ExportResultFiles;

    public function __construct(protected ExportResult $export, public WaveformPeaks $peaks) {}
}

<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg;

final readonly class WaveformPeaksResult implements Result
{
    use Concerns\ExportResultFiles;

    public function __construct(private ExportResult $export, public WaveformPeaks $peaks) {}
}

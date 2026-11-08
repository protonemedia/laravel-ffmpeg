<?php

namespace ProtoneMedia\LaravelFFMpeg;

readonly class LoudnessResult extends ExportResult
{
    public function __construct(
        ExportResult $export,
        public LoudnessStats $measurement,
        public LoudnessStats $normalization,
        public string $requestedMode,
        public int $sampleRate,
    ) {
        parent::__construct($export->files()->all(), $export->processes()->all());
    }

    public function actualMode(): string
    {
        return $this->normalization->mode;
    }
}

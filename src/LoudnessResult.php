<?php

namespace ProtoneMedia\LaravelFFMpeg;

readonly class LoudnessResult implements Result
{
    use Concerns\ExportResultFiles;

    public function __construct(
        protected ExportResult $export,
        public LoudnessStats $measurement,
        public LoudnessStats $normalization,
        public string $requestedMode,
        public int $sampleRate,
    ) {}

    public function actualMode(): string
    {
        return $this->normalization->mode;
    }
}

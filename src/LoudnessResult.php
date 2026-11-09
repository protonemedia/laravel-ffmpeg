<?php

namespace ProtoneMedia\LaravelFFMpeg;

/**
 * The normalized audio file and the loudness statistics FFmpeg reported for it.
 */
readonly class LoudnessResult implements Result
{
    use Concerns\ExportResultFiles;

    /**
     * Create a new loudness result instance.
     */
    public function __construct(
        protected ExportResult $export,
        public LoudnessStats $measurement,
        public LoudnessStats $normalization,
        public string $requestedMode,
        public int $sampleRate,
    ) {}

    /**
     * Get the normalization mode FFmpeg applied, which may differ from the requested mode.
     */
    public function actualMode(): string
    {
        return $this->normalization->mode;
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg;

/**
 * The normalized audio file and the loudness statistics FFmpeg reported for it.
 */
class LoudnessResult implements Result
{
    use Concerns\ExportResultFiles;

    /**
     * Create a new loudness result instance.
     */
    public function __construct(
        protected readonly ExportResult $export,
        public readonly LoudnessStats $measurement,
        public readonly LoudnessStats $normalization,
        public readonly string $requestedMode,
        public readonly int $sampleRate,
    ) {}

    /**
     * Get the normalization mode FFmpeg applied, which may differ from the requested mode.
     */
    public function actualMode(): string
    {
        return $this->normalization->mode;
    }
}

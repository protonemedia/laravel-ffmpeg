<?php

namespace ProtoneMedia\LaravelFFMpeg;

/**
 * A progress report of a running export.
 */
readonly class Progress
{
    /**
     * The phase that stages the inputs.
     */
    public const string STAGING = 'staging';

    /**
     * The phase that probes the staged inputs.
     */
    public const string PROBING = 'probing';

    /**
     * The phase that runs analysis passes before encoding.
     */
    public const string ANALYSIS = 'analysis';

    /**
     * The phase that encodes the outputs.
     */
    public const string ENCODING = 'encoding';

    /**
     * The phase that validates the encoded files.
     */
    public const string VALIDATION = 'validation';

    /**
     * The phase that publishes the validated files.
     */
    public const string PUBLICATION = 'publication';

    /**
     * Create a new progress instance.
     */
    public function __construct(
        public string $phase,
        public ?float $elapsedSeconds = null,
        public ?float $percentage = null,
        public ?float $speed = null,
        public ?float $remainingSeconds = null,
        public int $pass = 1,
        public int $totalPasses = 1,
    ) {}

    /**
     * Determine if the report belongs to the encoding phase.
     */
    public function isEncoding(): bool
    {
        return $this->phase === self::ENCODING;
    }
}

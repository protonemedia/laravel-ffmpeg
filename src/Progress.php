<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg;

readonly class Progress
{
    public const string STAGING = 'staging';

    public const string PROBING = 'probing';

    public const string ANALYSIS = 'analysis';

    public const string ENCODING = 'encoding';

    public const string VALIDATION = 'validation';

    public const string PUBLICATION = 'publication';

    public function __construct(
        public string $phase,
        public ?float $elapsedSeconds = null,
        public ?float $percentage = null,
        public ?float $speed = null,
        public ?float $remainingSeconds = null,
        public int $pass = 1,
        public int $totalPasses = 1,
    ) {}

    public function isEncoding(): bool
    {
        return $this->phase === self::ENCODING;
    }
}

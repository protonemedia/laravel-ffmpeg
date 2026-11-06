<?php

namespace ProtoneMedia\LaravelFFMpeg;

readonly class Progress
{
    public function __construct(
        public string $phase,
        public ?float $elapsedSeconds = null,
        public ?float $percentage = null,
        public ?float $speed = null,
        public ?float $remainingSeconds = null,
        public int $pass = 1,
        public int $totalPasses = 1,
    ) {}
}

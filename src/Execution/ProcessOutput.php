<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

/** @internal */
readonly class ProcessOutput
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $diagnostics,
        public float $elapsedSeconds = 0,
        public array $command = [],
    ) {}

    public function summary(): array
    {
        return [
            'exit_code' => $this->exitCode,
            'elapsed_seconds' => $this->elapsedSeconds,
            'command' => $this->command,
            'diagnostics' => $this->diagnostics,
        ];
    }
}

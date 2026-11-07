<?php

namespace ProtoneMedia\LaravelFFMpeg;

use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $diagnostics,
        public float $elapsedSeconds = 0,
        public array $command = [],
        public string $phase = 'probing',
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function output(): string
    {
        return $this->output;
    }

    public function errorOutput(): string
    {
        return $this->diagnostics;
    }

    public function throw(): self
    {
        if ($this->failed()) {
            throw new MediaException('Media process failed (exit '.$this->exitCode.').', $this->phase, 'exit', $this->diagnostics);
        }

        return $this;
    }

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

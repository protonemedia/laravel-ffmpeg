<?php

namespace ProtoneMedia\LaravelFFMpeg;

use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

/**
 * The outcome of a finished FFmpeg or FFprobe process.
 */
class ProcessResult
{
    /**
     * Create a new process result instance.
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $diagnostics,
        public readonly float $elapsedSeconds = 0,
        public readonly array $command = [],
        public readonly string $phase = 'probing',
    ) {}

    /**
     * Determine if the process succeeded.
     */
    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Determine if the process failed.
     */
    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * Get the standard output of the process.
     */
    public function output(): string
    {
        return $this->output;
    }

    /**
     * Get the diagnostic output of the process.
     */
    public function errorOutput(): string
    {
        return $this->diagnostics;
    }

    /**
     * Throw an exception if the process failed.
     */
    public function throw(): static
    {
        if ($this->failed()) {
            throw new MediaException(
                "Media process failed (exit {$this->exitCode}).",
                $this->phase,
                'exit',
                $this->diagnostics,
            );
        }

        return $this;
    }

    /**
     * Get a summary of the process for the export result.
     */
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

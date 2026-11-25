<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

/**
 * The outcome of a finished FFmpeg or FFprobe process.
 */
class ProcessResult
{
    /**
     * The diagnostic an encoder such as libx264 reports for odd frame dimensions.
     */
    protected const ODD_DIMENSIONS = '/\[(\w+) @ 0x[0-9a-f]+\] (?:width|height) not divisible by 2 \((\d+)x(\d+)\)/';

    /**
     * The diagnostic FFmpeg reports for an encoder its build does not include.
     */
    protected const UNKNOWN_ENCODER = "/Unknown encoder '([\w-]+)'/";

    /**
     * The diagnostic FFprobe and FFmpeg report for input they cannot demux or decode.
     *
     * FFprobe prefixes it with the redacted input label and FFmpeg with the input context,
     * so the same error on an output never matches.
     */
    protected const UNREADABLE_INPUT = '/^(?:\[in#\d+[^\]]*\]|\[input[^\]]*\]:|Error opening input).*'
        .'Invalid data found when processing input/m';

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
            throw $this->toException("Media process failed (exit {$this->exitCode}).");
        }

        return $this;
    }

    /**
     * Create an exception for the failed process that names the condition its diagnostics report.
     *
     * The given message describes the failure when the diagnostics report no known condition.
     *
     * @internal
     */
    public function toException(string $message): MediaException
    {
        if (preg_match(self::ODD_DIMENSIONS, $this->diagnostics, $match)) {
            return $this->failure(
                "The output dimensions [{$match[2]}x{$match[3]}] must be even for {$match[1]}.",
                'dimensions',
            );
        }

        if (preg_match(self::UNKNOWN_ENCODER, $this->diagnostics, $match)) {
            return $this->failure("The FFmpeg build has no [{$match[1]}] encoder.", 'encoder');
        }

        if (Str::contains($this->diagnostics, [
            'Streamcopy requested for output stream fed from a complex filtergraph',
            'Simple and complex filtering cannot be used together',
        ])) {
            return $this->failure($message, 'configuration');
        }

        if (preg_match(self::UNREADABLE_INPUT, $this->diagnostics)) {
            return $this->failure('The input media cannot be read.', 'input');
        }

        return $this->failure($message, 'exit');
    }

    /**
     * Create an exception for the failed process with the given message and category.
     */
    protected function failure(string $message, string $category): MediaException
    {
        return new MediaException($message, $this->phase, $category, $this->diagnostics);
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

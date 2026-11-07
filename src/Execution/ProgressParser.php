<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Progress;

/** @internal */
class ProgressParser
{
    private string $buffer = '';

    private array $record = [];

    private ?Progress $pending = null;

    public function __construct(private readonly ?float $duration) {}

    public function push(string $chunk): void
    {
        // Read bounded lines, even when a single callback contains many records.
        foreach (explode("\n", $chunk) as $index => $part) {
            if ($index > 0) {
                $this->line(rtrim($this->buffer, "\r"));
                $this->buffer = '';
            }

            if (strlen($this->buffer) + strlen($part) > 8192) {
                throw new MediaException('Invalid FFmpeg progress record.', 'encoding', 'progress');
            }

            $this->buffer .= $part;
        }
    }

    private function line(string $line): void
    {
        [$key, $value] = array_pad(explode('=', $line, 2), 2, null);

        if (in_array($key, ['out_time_us', 'speed'], true)) {
            $this->record[$key] = $value;
        } elseif ($key === 'progress') {
            $time = $this->record['out_time_us'] ?? null;
            $speed = rtrim($this->record['speed'] ?? '', 'x');
            $seconds = is_numeric($time) && is_finite((float) $time) ? max(0, (float) $time / 1000000) : null;
            $speed = is_numeric($speed) && is_finite((float) $speed) && $speed > 0 ? (float) $speed : null;
            $known = $this->duration !== null && $this->duration > 0 && $seconds !== null;

            $this->pending = new Progress(
                Progress::ENCODING, $seconds,
                $known ? min(100, 100 * $seconds / $this->duration) : null,
                $speed,
                $known && $speed !== null ? max(0, ($this->duration - $seconds) / $speed) : null,
            );
            $this->record = [];
        }
    }

    public function take(): ?Progress
    {
        $progress = $this->pending;
        $this->pending = null;

        return $progress;
    }
}

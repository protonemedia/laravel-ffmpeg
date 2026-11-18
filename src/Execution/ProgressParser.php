<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use Closure;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Progress;

/**
 * Parses FFmpeg's -progress output into throttled progress snapshots.
 *
 * @internal
 */
class ProgressParser
{
    /**
     * The incomplete line carried over from the previous chunk.
     */
    protected string $buffer = '';

    /**
     * The key and value pairs of the record being read.
     */
    protected array $record = [];

    /**
     * The latest snapshot that has not been delivered yet.
     */
    protected ?Progress $pending = null;

    /**
     * The high-resolution time of the last delivery, in nanoseconds.
     */
    protected int $notifiedAt = 0;

    /**
     * The callback that receives each snapshot.
     */
    protected readonly ?Closure $notify;

    /**
     * Create a new progress parser instance.
     */
    public function __construct(
        protected readonly ?float $duration,
        protected readonly string $phase = Progress::ENCODING,
        protected readonly int $pass = 1,
        protected readonly int $totalPasses = 1,
        ?callable $notify = null,
    ) {
        $this->notify = $notify === null ? null : $notify(...);
    }

    /**
     * Push a chunk of FFmpeg progress output.
     */
    public function push(string $chunk): void
    {
        // We will read the chunk line by line, even when a single callback holds many
        // records, and cap each line's length so a malformed stream cannot grow memory.
        foreach (explode("\n", $chunk) as $index => $part) {
            if ($index > 0) {
                $this->line(rtrim($this->buffer, "\r"));

                $this->buffer = '';
            }

            if (strlen($this->buffer) + strlen($part) > 8192) {
                throw new MediaException('Invalid FFmpeg progress record.', $this->phase, 'progress');
            }

            $this->buffer .= $part;
        }
    }

    /**
     * Handle a single line of progress output.
     */
    protected function line(string $line): void
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
                $this->phase, $seconds,
                $known ? min(100, 100 * $seconds / $this->duration) : null,
                $speed,
                $known && $speed !== null ? max(0, ($this->duration - $seconds) / $speed) : null,
                $this->pass, $this->totalPasses,
            );
            $this->record = [];
        }
    }

    /**
     * Deliver the latest snapshot to the callback.
     *
     * Snapshots are delivered at most every 100 ms, and always once the process has ended.
     */
    public function notify(bool $final): void
    {
        $now = hrtime(true);

        if ($this->notify === null
            || ! $this->pending instanceof Progress
            || (! $final && $now - $this->notifiedAt < 100000000)) {
            return;
        }

        $progress = $this->pending;
        $this->pending = null;

        ($this->notify)($progress);

        $this->notifiedAt = $now;
    }
}

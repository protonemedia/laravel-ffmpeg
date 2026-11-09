<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use RuntimeException;
use Throwable;

/**
 * Blocking, single-rendition H.264/AAC MPEG-TS live publisher. Application code owns
 * the worker's lifetime (for example a supervised Horizon job); this is not a daemon.
 * run() requires an empty dedicated namespace and exactly one writer. It publishes
 * segments before index.m3u8 and retains one extra window for recently stale readers.
 * Storage must provide atomic replacement of a single object; no cross-object
 * transaction is promised. Cancellation/timeout/failure retain the last readable
 * partial playlist without ENDLIST. Only clean EOF produces ENDLIST.
 * Retries reconnect failed encoders; reconnectOnEof also reconnects clean EOF.
 * Numbering/discontinuities survive those reconnects, not a new PHP worker/run().
 */
class PendingLiveHls
{
    use Macroable;

    protected ?Closure $publication = null;

    protected ?Closure $cancellation = null;

    protected int $retries = 0;

    protected float $retryDelay = 1;

    protected bool $reconnectOnEof = false;

    protected ?float $deadline = null;

    protected bool $stopped = false;

    protected bool $running = false;

    // The session state below belongs to a single run() and is reset before it returns.

    /** @var list<array{number: int, duration: float, key: string, discontinuity: bool}> */
    protected array $entries = [];

    /** @var array<string, int> */
    protected array $owned = [];

    protected bool $playlistPublished = false;

    protected int $next = 0;

    protected int $published = 0;

    protected int $attempts = 0;

    protected int $discontinuities = 0;

    protected string $status = 'running';

    protected ?Throwable $applicationFailure = null;

    protected int|float|null $started = null;

    protected ?RuntimeException $interrupt = null;

    public function __construct(
        protected readonly Media|LiveHlsSource $source,
        protected string|Filesystem $disk,
        protected readonly float $segmentDuration = 6,
        protected readonly int $window = 6,
    ) {
        if (! is_finite($segmentDuration) || $segmentDuration < 0.1 || $segmentDuration > 3600) {
            throw new InvalidArgumentException("The live HLS segment duration [{$segmentDuration}] must be between 0.1 and 3600 seconds.");
        }

        if ($window < 2 || $window > 1000) {
            throw new InvalidArgumentException("The live HLS window [{$window}] must be between 2 and 1000 segments.");
        }
    }

    public function toDisk(string|Filesystem $disk): self
    {
        $this->mutable();
        $this->disk = $disk;

        return $this;
    }

    /** Callback receives a running snapshot after the playlist is readable on storage. */
    public function onPublish(callable $callback): self
    {
        $this->mutable();
        $this->publication = Closure::fromCallable($callback);

        return $this;
    }

    /** Polled even while the source is stalled or during retry backoff. */
    public function cancelWhen(callable $callback): self
    {
        $this->mutable();
        $this->cancellation = Closure::fromCallable($callback);

        return $this;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    /** A total session limit, including retries; global process limits still apply. */
    public function timeout(float $seconds): self
    {
        $this->mutable();
        if (! is_finite($seconds) || $seconds <= 0) {
            throw new InvalidArgumentException("The live timeout [{$seconds}] must be positive and finite.");
        }

        $this->deadline = $seconds;

        return $this;
    }

    /** No implicit infinite retries. Every reconnect consumes the finite retry budget. */
    public function retry(int $maxRetries, float $delay = 1, bool $reconnectOnEof = false): self
    {
        $this->mutable();
        if ($maxRetries < 0 || $maxRetries > 10000) {
            throw new InvalidArgumentException("The live reconnect retry count [{$maxRetries}] must be between 0 and 10000.");
        }

        if (! is_finite($delay) || $delay < 0 || $delay > 3600) {
            throw new InvalidArgumentException("The live reconnect delay [{$delay}] must be between 0 and 3600 seconds.");
        }

        $this->retries = $maxRetries;
        $this->retryDelay = $delay;
        $this->reconnectOnEof = $reconnectOnEof;

        return $this;
    }

    protected function mutable(): void
    {
        if ($this->running) {
            throw new RuntimeException('A running live session cannot be reconfigured.');
        }
    }

    public function run(string $directory): LiveHlsResult
    {
        $this->mutable();
        $this->ensureNamespaceIsDedicated($directory);

        $disk = is_string($this->disk) ? Storage::disk($this->disk) : $this->disk;
        $playlist = $directory.'/index.m3u8';
        $workspace = null;
        $failed = false;
        $this->beginSession();
        $this->running = true;

        try {
            if ($disk->allFiles($directory) !== []) {
                throw MediaException::publication('Live HLS requires an empty, exclusively owned destination namespace.', 'ownership');
            }

            $this->interruptIfNecessary();
            $workspace = new Workspace;
            $this->supervise($disk, $directory, $playlist, $workspace);

            return $this->snapshot($playlist);
        } catch (Throwable $throwable) {
            if ($throwable === $this->interrupt) {
                return $this->snapshot($playlist);
            }

            $failed = true;
            if ($throwable === $this->applicationFailure) {
                throw $throwable;
            }

            throw $this->publicationFailure($throwable, $playlist);
        } finally {
            // A stop() request ends this session, not later runs of the same builder.
            $this->running = false;
            $this->stopped = false;
            $this->resetSession();
            try {
                $workspace?->delete();
            } catch (Throwable $exception) {
                // As in Media::withInput(), a cleanup failure must not replace the failure in flight.
                if (! $failed) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Ensure the destination is a dedicated relative namespace.
     */
    protected function ensureNamespaceIsDedicated(string $directory): void
    {
        if (! preg_match('/\A[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*\z/', $directory)) {
            throw new InvalidArgumentException('Live HLS requires a dedicated relative namespace of simple path components.');
        }

        if (strlen($directory) > 200) {
            throw new InvalidArgumentException('The live HLS namespace must not exceed 200 characters.');
        }
    }

    /**
     * Start the session clock with empty publication state.
     */
    protected function beginSession(): void
    {
        $this->resetSession();
        $this->started = hrtime(true);
        $this->interrupt = new RuntimeException('Live session interrupted.');
    }

    /**
     * Reset the session state to its defaults.
     */
    protected function resetSession(): void
    {
        $this->entries = [];
        $this->owned = [];
        $this->playlistPublished = false;
        $this->next = 0;
        $this->published = 0;
        $this->attempts = 0;
        $this->discontinuities = 0;
        $this->status = 'running';
        $this->applicationFailure = null;
        $this->started = null;
        $this->interrupt = null;
    }

    /**
     * Interrupt the session when it is stopped, cancelled or out of time.
     */
    protected function interruptIfNecessary(): void
    {
        try {
            $cancelled = $this->stopped || ($this->cancellation instanceof Closure && ($this->cancellation)());
        } catch (Throwable $throwable) {
            $this->applicationFailure = $throwable;
            throw $throwable;
        }

        if ($cancelled) {
            $this->status = 'cancelled';
            throw $this->interrupt;
        }

        if ($this->deadline !== null && (hrtime(true) - $this->started) / 1e9 >= $this->deadline) {
            $this->status = 'timed_out';
            throw $this->interrupt;
        }
    }

    /**
     * Run the encoder, publishing its segments and reconnecting until it ends or gives up.
     */
    protected function supervise(Filesystem $disk, string $directory, string $playlist, Workspace $workspace): void
    {
        $binary = config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg');
        $workspace->runner->version($binary, 'ffmpeg', $workspace->path);
        $input = $this->stageSource($workspace);
        $workspace->requireProtocols($binary, $input['protocols']);
        $prefix = 'live-'.bin2hex(random_bytes(12)).'-';

        do {
            $this->interruptIfNecessary();
            $this->attempts++;
            $start = $this->next;
            $local = $workspace->path.'/live.m3u8';
            @unlink($local);

            // Suppress source-bearing diagnostics entirely: reconnect failures may echo
            // decoded credentials or URL fragments beyond a simple string replacement.
            $result = $workspace->runner->run($this->encoderCommand($binary, $input, $start, $prefix, $local), $workspace->path,
                [$input['path'] => '[live-source]'], phase: 'live', tick: function () use ($disk, $directory, $playlist, $workspace, $prefix, $start): void {
                    $this->publishPlaylist($disk, $directory, $playlist, $workspace, $prefix, $start);
                    $this->interruptIfNecessary();
                });

            $finished = $result->exitCode === 0 && ! $this->reconnectOnEof;
            $this->publishPlaylist($disk, $directory, $playlist, $workspace, $prefix, $start, $finished);
            if ($finished) {
                if ($this->entries === []) {
                    throw MediaException::live('Live encoder produced no segments.', 'artifact');
                }

                $this->status = 'completed';

                return;
            }

            if ($this->attempts > $this->retries) {
                throw MediaException::live('Live source exhausted its reconnect policy after '.$this->attempts.' attempts.', 'source');
            }

            // Only local generated files are removed. Destination history is owned above.
            $this->pruneLocalSegments($workspace, $prefix);
            $this->waitBeforeReconnecting();
        } while (true);
    }

    /**
     * Stage the source and resolve its input path, protocol whitelist and options.
     *
     * @return array{path: string, protocols: string, options: array}
     */
    protected function stageSource(Workspace $workspace): array
    {
        if ($this->source instanceof Media) {
            return [
                'path' => $workspace->stage($this->source),
                'protocols' => $this->source->protocolWhitelist(),
                'options' => [...$this->source->transportOptions(), ...$this->source->inputOptions()],
            ];
        }

        return [
            'path' => $this->source->url(),
            'protocols' => $this->source->protocols(),
            'options' => $this->source->options(),
        ];
    }

    /**
     * Build the encoder command for one attempt, numbering segments from the given start.
     *
     * @param  array{path: string, protocols: string, options: array}  $input
     */
    protected function encoderCommand(string $binary, array $input, int $start, string $prefix, string $local): array
    {
        return [$binary, '-hide_banner', '-nostdin', '-nostats', '-loglevel', 'error', '-xerror',
            '-protocol_whitelist', $input['protocols'], ...($this->source instanceof Media ? ['-re'] : []), ...$input['options'], '-i', $input['path'],
            '-map', '0:v:0?', '-map', '0:a:0?', '-sn', '-dn', '-c:v', 'libx264', '-preset', 'veryfast', '-tune', 'zerolatency',
            '-threads', '1', '-c:a', 'aac', '-force_key_frames', 'expr:gte(t,n_forced*'.$this->segmentDuration.')',
            '-flags:v', '+cgop', '-f', 'hls', '-hls_time', (string) $this->segmentDuration,
            '-hls_list_size', (string) $this->window, '-hls_delete_threshold', (string) $this->window,
            '-hls_flags', 'delete_segments+temp_file', '-start_number', (string) $start,
            '-hls_segment_filename', $prefix.'%012d.ts', $local];
    }

    /**
     * Publish new encoder segments, then replace the playlist and prune expired segments.
     */
    protected function publishPlaylist(Filesystem $disk, string $directory, string $playlist, Workspace $workspace, string $prefix, int $start, bool $finished = false): void
    {
        $local = $workspace->path.'/live.m3u8';
        if (! is_file($local)) {
            return;
        }

        [$lines, $number] = $this->readEncoderPlaylist($local, $start);
        $changed = $this->publishSegments($disk, $directory, $workspace, $prefix, $start, $lines, $number);
        if ($this->entries === [] || (! $changed && ! $finished)) {
            return;
        }

        while (count($this->entries) > $this->window) {
            $removed = array_shift($this->entries);
            $this->discontinuities += (int) $removed['discontinuity'];
        }

        if (! $disk->put($playlist, $this->renderPlaylist($finished))) {
            throw MediaException::publication('Cannot replace the live playlist.', 'transfer');
        }

        $this->playlistPublished = true;
        // Commit first, prune second: never delete references of the last visible playlist.
        $this->pruneRetention($disk);

        if ($changed && $this->publication instanceof Closure) {
            $this->notifyPublication($playlist);
        }
    }

    /**
     * Read the encoder's local playlist and ensure it continues the published window.
     *
     * @return array{0: list<string>, 1: int}
     */
    protected function readEncoderPlaylist(string $local, int $start): array
    {
        $bytes = file_get_contents($local, length: 1048577);
        if ($bytes === false || strlen($bytes) > 1048576
            || ! preg_match('/^#EXT-X-MEDIA-SEQUENCE:([0-9]+)$/m', $bytes, $sequence)) {
            throw MediaException::publication('Invalid live encoder playlist.', 'artifact');
        }

        $number = (int) $sequence[1];
        if ($number < $start || $number > $this->next) {
            throw MediaException::publication('The live publisher missed an encoder window.', 'window');
        }

        return [explode("\n", trim($bytes)), $number];
    }

    /**
     * Publish the completed segments the encoder playlist references beyond the window.
     *
     * @param  list<string>  $lines
     */
    protected function publishSegments(Filesystem $disk, string $directory, Workspace $workspace, string $prefix, int $start, array $lines, int $number): bool
    {
        $duration = null;
        $changed = false;
        foreach ($lines as $line) {
            if (str_starts_with($line, '#EXTINF:')) {
                if ($duration !== null || ! preg_match('/\A#EXTINF:([0-9]+(?:\.[0-9]+)?),\z/', $line, $match)
                    || ! is_finite($duration = (float) $match[1]) || $duration <= 0
                    || $duration > ceil($this->segmentDuration) + 1) {
                    throw MediaException::publication('Invalid live segment duration.', 'artifact');
                }
            } elseif ($line !== '' && $line[0] !== '#') {
                // Ownership derives from our generated prefix and contiguous counter,
                // never from arbitrary changing playlist references or destination scans.
                $name = $prefix.sprintf('%012d', $number).'.ts';
                if ($duration === null || $line !== $name) {
                    throw MediaException::publication('Unowned live segment reference.', 'ownership');
                }

                if ($number >= $this->next) {
                    $key = $directory.'/'.$name;
                    $this->uploadSegment($disk, $workspace->path.'/'.$name, $key);
                    $this->owned[$key] = $number;
                    $this->entries[] = ['number' => $number, 'duration' => $duration, 'key' => $key,
                        'discontinuity' => $this->attempts > 1 && $number === $start];
                    $this->next = $number + 1;
                    $this->published++;
                    $changed = true;
                }

                $number++;
                $duration = null;
            } elseif (! preg_match('/\A(?:#EXTM3U|#EXT-X-VERSION:[0-9]+|#EXT-X-TARGETDURATION:[0-9]+|#EXT-X-MEDIA-SEQUENCE:[0-9]+|#EXT-X-ENDLIST)\z/', $line)) {
                throw MediaException::publication('Unexpected live playlist directive.', 'artifact');
            }
        }

        if ($duration !== null) {
            throw MediaException::publication('Incomplete live segment reference.', 'artifact');
        }

        return $changed;
    }

    /**
     * Copy a completed local segment to its destination key.
     */
    protected function uploadSegment(Filesystem $disk, string $source, string $key): void
    {
        $input = @fopen($source, 'rb');
        if ($input === false) {
            throw MediaException::publication('Cannot read a completed live segment.', 'artifact');
        }

        try {
            if (fstat($input)['size'] === 0 || ! $disk->put($key, $input)) {
                throw MediaException::publication('Cannot publish a live segment.', 'transfer');
            }
        } finally {
            fclose($input);
        }
    }

    /**
     * Render the playlist for the current window.
     */
    protected function renderPlaylist(bool $finished): string
    {
        // A playlist's target duration cannot change. One second of
        // headroom covers ordinary audio/video frame rounding.
        $text = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:".((int) ceil($this->segmentDuration) + 1)
            ."\n#EXT-X-MEDIA-SEQUENCE:".$this->entries[0]['number']."\n#EXT-X-DISCONTINUITY-SEQUENCE:".$this->discontinuities."\n";
        foreach ($this->entries as $entry) {
            $text .= ($entry['discontinuity'] ? "#EXT-X-DISCONTINUITY\n" : '')
                .'#EXTINF:'.sprintf('%.6F', $entry['duration']).",\n".basename($entry['key'])."\n";
        }

        if ($finished) {
            $text .= "#EXT-X-ENDLIST\n";
        }

        return $text;
    }

    /**
     * Delete published segments older than the retained extra window.
     */
    protected function pruneRetention(Filesystem $disk): void
    {
        foreach ($this->owned as $key => $sequence) {
            if ($sequence < $this->entries[0]['number'] - $this->window) {
                if (! $disk->delete($key)) {
                    throw MediaException::publication('Cannot prune live destination retention.', 'retention');
                }

                unset($this->owned[$key]);
            }
        }
    }

    /**
     * Pass a snapshot of the readable playlist to the publication callback.
     */
    protected function notifyPublication(string $playlist): void
    {
        try {
            ($this->publication)($this->snapshot($playlist));
        } catch (Throwable $throwable) {
            $this->applicationFailure = $throwable;
            throw $throwable;
        }
    }

    /**
     * Remove the local segments of a failed encoder attempt.
     */
    protected function pruneLocalSegments(Workspace $workspace, string $prefix): void
    {
        foreach (glob($workspace->path.'/'.$prefix.'*') ?: [] as $file) {
            if (! @unlink($file)) {
                throw MediaException::cleanup('Cannot prune local live segments.', 'filesystem');
            }
        }
    }

    /**
     * Wait out the retry delay while still honoring cancellation and the timeout.
     */
    protected function waitBeforeReconnecting(): void
    {
        $until = hrtime(true) + (int) ($this->retryDelay * 1e9);
        while (hrtime(true) < $until) {
            $this->interruptIfNecessary();
            usleep(10000);
        }
    }

    /**
     * Create a snapshot of the session's current playlist window.
     */
    protected function snapshot(string $playlist): LiveHlsResult
    {
        return new LiveHlsResult($playlist, array_column($this->entries, 'key'), $this->status, $this->attempts, $this->published,
            $this->entries === [] ? null : $this->entries[0]['number'], $this->entries === [] ? null : $this->entries[array_key_last($this->entries)]['number']);
    }

    /**
     * Wrap a publication failure, listing the files this session left on the destination.
     */
    protected function publicationFailure(Throwable $throwable, string $playlist): MediaException
    {
        // Do not attach adapter/process exceptions: they may contain credentials.
        return new MediaException($throwable instanceof MediaException ? $throwable->getMessage() : 'Live publication failed.',
            $throwable instanceof MediaException ? $throwable->phase : 'publication',
            $throwable instanceof MediaException ? $throwable->category : 'transfer',
            published: array_map(fn (string $key): array => ['disk' => is_string($this->disk) ? $this->disk : null, 'path' => $key],
                [...array_keys($this->owned), ...($this->playlistPublished ? [$playlist] : [])]));
    }
}

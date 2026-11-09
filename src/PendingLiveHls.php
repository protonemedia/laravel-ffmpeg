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
        if (! preg_match('/\A[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*\z/', $directory)) {
            throw new InvalidArgumentException('Live HLS requires a dedicated relative namespace of simple path components.');
        }

        if (strlen($directory) > 200) {
            throw new InvalidArgumentException('The live HLS namespace must not exceed 200 characters.');
        }

        $disk = is_string($this->disk) ? Storage::disk($this->disk) : $this->disk;
        $playlist = $directory.'/index.m3u8';
        $entries = [];
        $owned = [];
        $playlistPublished = false;
        $next = 0;
        $published = 0;
        $attempts = 0;
        $discontinuities = 0;
        $status = 'running';
        $workspace = null;
        $applicationFailure = null;
        $failed = false;
        $started = hrtime(true);
        $interrupt = new RuntimeException('Live session interrupted.');
        $this->running = true;
        $snapshot = function () use ($playlist, &$entries, &$status, &$attempts, &$published): LiveHlsResult {
            return new LiveHlsResult($playlist, array_column($entries, 'key'), $status, $attempts, $published,
                $entries === [] ? null : $entries[0]['number'], $entries === [] ? null : $entries[array_key_last($entries)]['number']);
        };
        $check = function () use (&$status, &$applicationFailure, $started, $interrupt): void {
            try {
                $cancelled = $this->stopped || ($this->cancellation instanceof Closure && ($this->cancellation)());
            } catch (Throwable $throwable) {
                $applicationFailure = $throwable;
                throw $throwable;
            }

            if ($cancelled) {
                $status = 'cancelled';
                throw $interrupt;
            }

            if ($this->deadline !== null && (hrtime(true) - $started) / 1e9 >= $this->deadline) {
                $status = 'timed_out';
                throw $interrupt;
            }
        };

        try {
            if ($disk->allFiles($directory) !== []) {
                throw MediaException::publication('Live HLS requires an empty, exclusively owned destination namespace.', 'ownership');
            }

            $check();
            $workspace = new Workspace;
            $binary = config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg');
            $workspace->runner->version($binary, 'ffmpeg', $workspace->path);
            $path = $this->source instanceof Media ? $workspace->stage($this->source) : $this->source->url();
            $protocols = $this->source instanceof Media ? $this->source->protocolWhitelist() : $this->source->protocols();
            $options = $this->source instanceof Media ? [...$this->source->transportOptions(), ...$this->source->inputOptions()] : $this->source->options();
            $workspace->requireProtocols($binary, $protocols);
            $token = bin2hex(random_bytes(12));

            do {
                $check();
                $attempts++;
                $start = $next;
                $last = $start - 1;
                $local = $workspace->path.'/live.m3u8';
                @unlink($local);
                $prefix = 'live-'.$token.'-';
                $publish = function (bool $finished = false) use ($disk, $directory, $playlist, $workspace, $local, $prefix, $start, &$last,
                    &$entries, &$owned, &$next, &$published, &$discontinuities, &$applicationFailure, &$playlistPublished, $attempts, $snapshot): void {
                    if (! is_file($local)) {
                        return;
                    }

                    $bytes = file_get_contents($local, length: 1048577);
                    if ($bytes === false || strlen($bytes) > 1048576
                        || ! preg_match('/^#EXT-X-MEDIA-SEQUENCE:([0-9]+)$/m', $bytes, $sequence)) {
                        throw MediaException::publication('Invalid live encoder playlist.', 'artifact');
                    }

                    $number = (int) $sequence[1];
                    if ($number < $start || $number > $last + 1) {
                        throw MediaException::publication('The live publisher missed an encoder window.', 'window');
                    }

                    $duration = null;
                    $changed = false;
                    foreach (explode("\n", trim($bytes)) as $line) {
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

                            if ($number > $last) {
                                $input = @fopen($workspace->path.'/'.$name, 'rb');
                                if ($input === false) {
                                    throw MediaException::publication('Cannot read a completed live segment.', 'artifact');
                                }

                                $key = $directory.'/'.$name;
                                try {
                                    if (fstat($input)['size'] === 0 || ! $disk->put($key, $input)) {
                                        throw MediaException::publication('Cannot publish a live segment.', 'transfer');
                                    }
                                } finally {
                                    fclose($input);
                                }

                                $owned[$key] = $number;
                                $entries[] = ['number' => $number, 'duration' => $duration, 'key' => $key,
                                    'discontinuity' => $attempts > 1 && $number === $start];
                                $last = $number;
                                $next = $number + 1;
                                $published++;
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

                    if ($entries === [] || (! $changed && ! $finished)) {
                        return;
                    }

                    while (count($entries) > $this->window) {
                        $removed = array_shift($entries);
                        $discontinuities += (int) $removed['discontinuity'];
                    }

                    // A playlist's target duration cannot change. One second of
                    // headroom covers ordinary audio/video frame rounding.
                    $text = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:".((int) ceil($this->segmentDuration) + 1)
                        ."\n#EXT-X-MEDIA-SEQUENCE:".$entries[0]['number']."\n#EXT-X-DISCONTINUITY-SEQUENCE:".$discontinuities."\n";
                    foreach ($entries as $entry) {
                        $text .= ($entry['discontinuity'] ? "#EXT-X-DISCONTINUITY\n" : '')
                            .'#EXTINF:'.sprintf('%.6F', $entry['duration']).",\n".basename($entry['key'])."\n";
                    }

                    if ($finished) {
                        $text .= "#EXT-X-ENDLIST\n";
                    }

                    if (! $disk->put($playlist, $text)) {
                        throw MediaException::publication('Cannot replace the live playlist.', 'transfer');
                    }

                    $playlistPublished = true;
                    // Commit first, prune second: never delete references of the last visible playlist.
                    foreach ($owned as $key => $sequence) {
                        if ($sequence < $entries[0]['number'] - $this->window) {
                            if (! $disk->delete($key)) {
                                throw MediaException::publication('Cannot prune live destination retention.', 'retention');
                            }

                            unset($owned[$key]);
                        }
                    }

                    if ($changed && $this->publication instanceof Closure) {
                        try {
                            ($this->publication)($snapshot());
                        } catch (Throwable $exception) {
                            $applicationFailure = $exception;
                            throw $exception;
                        }
                    }
                };
                $command = [$binary, '-hide_banner', '-nostdin', '-nostats', '-loglevel', 'error', '-xerror',
                    '-protocol_whitelist', $protocols, ...($this->source instanceof Media ? ['-re'] : []), ...$options, '-i', $path,
                    '-map', '0:v:0?', '-map', '0:a:0?', '-sn', '-dn', '-c:v', 'libx264', '-preset', 'veryfast', '-tune', 'zerolatency',
                    '-threads', '1', '-c:a', 'aac', '-force_key_frames', 'expr:gte(t,n_forced*'.$this->segmentDuration.')',
                    '-flags:v', '+cgop', '-f', 'hls', '-hls_time', (string) $this->segmentDuration,
                    '-hls_list_size', (string) $this->window, '-hls_delete_threshold', (string) $this->window,
                    '-hls_flags', 'delete_segments+temp_file', '-start_number', (string) $start,
                    '-hls_segment_filename', $prefix.'%012d.ts', $local];
                // Suppress source-bearing diagnostics entirely: reconnect failures may echo
                // decoded credentials or URL fragments beyond a simple string replacement.
                $result = $workspace->runner->run($command, $workspace->path, [$path => '[live-source]'],
                    phase: 'live', tick: function () use ($publish, $check): void {
                        $publish();
                        $check();
                    });
                $publish($result->exitCode === 0 && ! $this->reconnectOnEof);
                if ($result->exitCode === 0 && ! $this->reconnectOnEof) {
                    if ($entries === []) {
                        throw MediaException::live('Live encoder produced no segments.', 'artifact');
                    }

                    $status = 'completed';
                    break;
                }

                if ($attempts > $this->retries) {
                    throw MediaException::live('Live source exhausted its reconnect policy after '.$attempts.' attempts.', 'source');
                }

                // Only local generated files are removed. Destination history is owned above.
                foreach (glob($workspace->path.'/'.$prefix.'*') ?: [] as $file) {
                    if (! @unlink($file)) {
                        throw MediaException::cleanup('Cannot prune local live segments.', 'filesystem');
                    }
                }

                $until = hrtime(true) + (int) ($this->retryDelay * 1e9);
                while (hrtime(true) < $until) {
                    $check();
                    usleep(10000);
                }
            } while (true);

            return $snapshot();
        } catch (Throwable $throwable) {
            if ($throwable === $interrupt) {
                return $snapshot();
            }

            $failed = true;
            if ($throwable === $applicationFailure) {
                throw $throwable;
            }

            // Do not attach adapter/process exceptions: they may contain credentials.
            throw new MediaException($throwable instanceof MediaException ? $throwable->getMessage() : 'Live publication failed.',
                $throwable instanceof MediaException ? $throwable->phase : 'publication',
                $throwable instanceof MediaException ? $throwable->category : 'transfer',
                published: array_map(fn (string $key): array => ['disk' => is_string($this->disk) ? $this->disk : null, 'path' => $key],
                    [...array_keys($owned), ...($playlistPublished ? [$playlist] : [])]));
        } finally {
            // A stop() request ends this session, not later runs of the same builder.
            $this->running = false;
            $this->stopped = false;
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
}

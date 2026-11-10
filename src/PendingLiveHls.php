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
 * A blocking publisher of a single H.264 and AAC rendition as live MPEG-TS HLS.
 *
 * This is not a daemon. The application owns the worker's lifetime, for example through a supervised Horizon
 * job. The run() method requires an empty, dedicated namespace and exactly one writer.
 *
 * Segments are published before index.m3u8, and one extra window is retained for readers of a recently stale
 * playlist. Storage must replace a single object atomically, and no transaction across objects is promised.
 *
 * Cancellation, timeouts and failures leave the last readable partial playlist without ENDLIST. Only a clean
 * EOF writes ENDLIST. Retries reconnect failed encoders, and reconnectOnEof also reconnects after a clean EOF.
 * Segment numbering and discontinuities survive those reconnects, but not a new PHP worker or run() call.
 */
class PendingLiveHls
{
    /**
     * The maximum size in bytes of the encoder's local playlist.
     */
    protected const int MAX_ENCODER_PLAYLIST_BYTES = 1048576;

    use Macroable;

    /**
     * The callback that receives a snapshot after each publication.
     */
    protected ?Closure $publication = null;

    /**
     * The callback that cancels the session when it returns true.
     */
    protected ?Closure $cancellation = null;

    /**
     * The maximum number of reconnects.
     */
    protected int $retries = 0;

    /**
     * The delay between reconnects in seconds.
     */
    protected float $retryDelay = 1;

    /**
     * Indicates if a clean EOF should also reconnect.
     */
    protected bool $reconnectOnEof = false;

    /**
     * The total session limit in seconds, including retries.
     */
    protected ?float $deadline = null;

    /**
     * Indicates if a stop has been requested for the current session.
     */
    protected bool $stopped = false;

    /**
     * Indicates if a session is running.
     */
    protected bool $running = false;

    // The session state below belongs to a single run() call and is reset before it returns.

    /**
     * The segments of the current playlist window.
     *
     * @var list<array{number: int, duration: float, key: string, discontinuity: bool}>
     */
    protected array $entries = [];

    /**
     * The sequence numbers of the published segment keys this session owns.
     *
     * @var array<string, int>
     */
    protected array $owned = [];

    /**
     * Indicates if the playlist has been published.
     */
    protected bool $playlistPublished = false;

    /**
     * The sequence number of the next segment to publish.
     */
    protected int $next = 0;

    /**
     * The number of segments published during the session.
     */
    protected int $published = 0;

    /**
     * The number of encoder attempts made during the session.
     */
    protected int $attempts = 0;

    /**
     * The number of discontinuities that have left the playlist window.
     */
    protected int $discontinuities = 0;

    /**
     * The status of the session.
     */
    protected string $status = 'running';

    /**
     * The exception thrown by an application callback, which is rethrown unwrapped.
     */
    protected ?Throwable $applicationFailure = null;

    /**
     * The high-resolution time the session started at in nanoseconds.
     */
    protected int|float|null $started = null;

    /**
     * The exception that ends the session when it is stopped, cancelled or out of time.
     */
    protected ?RuntimeException $interrupt = null;

    /**
     * The filesystem the running session publishes to.
     */
    protected ?Filesystem $filesystem = null;

    /**
     * The destination directory of the running session.
     */
    protected ?string $directory = null;

    /**
     * The playlist path of the running session.
     */
    protected ?string $playlist = null;

    /**
     * The workspace of the running session.
     */
    protected ?Workspace $workspace = null;

    /**
     * The segment file name prefix of the running session.
     */
    protected ?string $prefix = null;

    /**
     * Create a new pending live HLS instance.
     */
    public function __construct(
        protected readonly Media|LiveHlsSource $source,
        protected string|Filesystem $disk,
        protected readonly float $segmentDuration = 6,
        protected readonly int $window = 6,
    ) {
        if (! is_finite($segmentDuration) || $segmentDuration < 0.1 || $segmentDuration > 3600) {
            throw new InvalidArgumentException(
                'The live HLS segment duration must be between 0.1 and 3600 seconds.'
            );
        }

        if ($window < 2 || $window > 1000) {
            throw new InvalidArgumentException("The live HLS window [{$window}] must be between 2 and 1000 segments.");
        }
    }

    /**
     * Set the disk to publish the playlist and segments to.
     */
    public function toDisk(string|Filesystem $disk): static
    {
        $this->mutable();

        $this->disk = $disk;

        return $this;
    }

    /**
     * Register a callback that receives a running snapshot once the playlist is readable on storage.
     */
    public function onPublish(callable $callback): static
    {
        $this->mutable();

        $this->publication = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Cancel the session when the given callback returns true.
     *
     * The callback is polled even while the source is stalled or the session waits to reconnect.
     */
    public function cancelWhen(callable $callback): static
    {
        $this->mutable();

        $this->cancellation = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Request the running session to stop.
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * Set the total session limit in seconds, including retries.
     *
     * The global process limits still apply.
     */
    public function timeout(float $seconds): static
    {
        $this->mutable();

        if (! is_finite($seconds) || $seconds <= 0) {
            throw new InvalidArgumentException('The live timeout must be positive and finite.');
        }

        $this->deadline = $seconds;

        return $this;
    }

    /**
     * Set the reconnect policy of the session.
     *
     * Retries are never infinite, and every reconnect consumes the finite retry budget.
     */
    public function retry(int $maxRetries, float $delay = 1, bool $reconnectOnEof = false): static
    {
        $this->mutable();

        if ($maxRetries < 0 || $maxRetries > 10000) {
            throw new InvalidArgumentException(
                "The live reconnect retry count [{$maxRetries}] must be between 0 and 10000."
            );
        }

        if (! is_finite($delay) || $delay < 0 || $delay > 3600) {
            throw new InvalidArgumentException(
                'The live reconnect delay must be between 0 and 3600 seconds.'
            );
        }

        $this->retries = $maxRetries;
        $this->retryDelay = $delay;
        $this->reconnectOnEof = $reconnectOnEof;

        return $this;
    }

    /**
     * Ensure no session is running.
     */
    protected function mutable(): void
    {
        if ($this->running) {
            throw new RuntimeException('A running live session cannot be reconfigured.');
        }
    }

    /**
     * Run the live session and publish it to the given directory until it ends.
     */
    public function run(string $directory): LiveHlsResult
    {
        $this->mutable();
        $this->ensureNamespaceIsDedicated($directory);

        $disk = is_string($this->disk) ? Storage::disk($this->disk) : $this->disk;
        $playlist = "{$directory}/index.m3u8";
        $workspace = null;
        $failed = false;

        $this->beginSession($disk, $directory, $playlist);

        $this->running = true;

        try {
            if ($disk->allFiles($directory) !== []) {
                throw MediaException::publication(
                    'Live HLS requires an empty, exclusively owned destination namespace.',
                    'ownership',
                );
            }

            $this->interruptIfNecessary();

            $workspace = new Workspace;
            $this->workspace = $workspace;

            $this->supervise();

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
            // A stop() request only ends this session, so we will clear it here to let later
            // runs of the same builder proceed.
            $this->running = false;
            $this->stopped = false;

            $this->resetSession();

            try {
                $workspace?->delete();
            } catch (Throwable $exception) {
                // As in Media::withInput(), a cleanup failure must not replace a failure that is
                // already in flight.
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
            throw new InvalidArgumentException(
                'Live HLS requires a dedicated relative namespace of simple path components.'
            );
        }

        if (strlen($directory) > 200) {
            throw new InvalidArgumentException('The live HLS namespace must not exceed 200 characters.');
        }
    }

    /**
     * Start the session clock with empty publication state for the given destination.
     */
    protected function beginSession(Filesystem $filesystem, string $directory, string $playlist): void
    {
        $this->resetSession();

        $this->started = hrtime(true);
        $this->interrupt = new RuntimeException('Live session interrupted.');
        $this->filesystem = $filesystem;
        $this->directory = $directory;
        $this->playlist = $playlist;
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
        $this->filesystem = null;
        $this->directory = null;
        $this->playlist = null;
        $this->workspace = null;
        $this->prefix = null;
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
    protected function supervise(): void
    {
        $workspace = $this->workspace;
        $binary = config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg');

        $workspace->runner->version($binary, 'ffmpeg', $workspace->path);

        $input = $this->stageSource($workspace);

        $workspace->requireProtocols($binary, $input['protocols']);

        $this->prefix = 'live-'.bin2hex(random_bytes(12)).'-';

        do {
            $this->interruptIfNecessary();
            $this->attempts++;
            $start = $this->next;
            @unlink("{$workspace->path}/live.m3u8");

            // We will suppress diagnostics that mention the source entirely. Reconnect failures
            // may echo decoded credentials or URL fragments that a simple string replacement misses.
            $result = $workspace->runner->run(
                $this->encoderCommand($binary, $input, $start),
                $workspace->path,
                [$input['path'] => '[live-source]'],
                phase: 'live',
                tick: function () use ($start): void {
                    $this->publishPlaylist($start);
                    $this->interruptIfNecessary();
                },
            );

            $finished = $result->exitCode === 0 && ! $this->reconnectOnEof;

            $this->publishPlaylist($start, $finished);

            if ($finished) {
                if ($this->entries === []) {
                    throw MediaException::live('Live encoder produced no segments.', 'artifact');
                }

                $this->status = 'completed';

                return;
            }

            if ($this->attempts > $this->retries) {
                throw MediaException::live(
                    "Live source exhausted its reconnect policy after {$this->attempts} attempts.",
                    'source',
                );
            }

            // Only the generated local files are removed here. The published destination history
            // is owned and pruned by the publication step.
            $this->pruneLocalSegments($workspace, $this->prefix);
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
    protected function encoderCommand(string $binary, array $input, int $start): array
    {
        // RTSP sources rebase their audio timestamps once the first RTCP report arrives, and
        // the backward jump is fatal under -xerror. Resampling keeps the audio monotonic.
        return [
            $binary, '-hide_banner', '-nostdin', '-nostats', '-loglevel', 'error', '-xerror',
            '-protocol_whitelist', $input['protocols'],
            ...($this->source instanceof Media ? ['-re'] : []),
            ...$input['options'],
            '-i', $input['path'],
            '-map', '0:v:0?', '-map', '0:a:0?', '-sn', '-dn',
            '-c:v', 'libx264', '-preset', 'veryfast', '-tune', 'zerolatency', '-threads', '1', '-c:a', 'aac',
            '-af', 'aresample=async=1', '-force_key_frames', "expr:gte(t,n_forced*{$this->segmentDuration})",
            '-flags:v', '+cgop', '-f', 'hls', '-hls_time', (string) $this->segmentDuration,
            '-hls_list_size', (string) $this->window, '-hls_delete_threshold', (string) $this->window,
            '-hls_flags', 'delete_segments+temp_file', '-start_number', (string) $start,
            '-hls_segment_filename', "{$this->prefix}%012d.ts", "{$this->workspace->path}/live.m3u8",
        ];
    }

    /**
     * Publish new encoder segments, then replace the playlist and prune expired segments.
     */
    protected function publishPlaylist(int $start, bool $finished = false): void
    {
        $local = "{$this->workspace->path}/live.m3u8";

        if (! is_file($local)) {
            return;
        }

        [$lines, $number] = $this->readEncoderPlaylist($local, $start);
        $changed = $this->publishSegments($start, $lines, $number);

        if ($this->entries === [] || (! $changed && ! $finished)) {
            return;
        }

        while (count($this->entries) > $this->window) {
            $removed = array_shift($this->entries);
            $this->discontinuities += (int) $removed['discontinuity'];
        }

        if (! $this->filesystem->put($this->playlist, $this->renderPlaylist($finished))) {
            throw MediaException::publication('Cannot replace the live playlist.', 'transfer');
        }

        $this->playlistPublished = true;

        // The playlist is committed before we prune, so we never delete a segment that the
        // last visible playlist still references.
        $this->pruneRetention($this->filesystem);

        if ($changed && $this->publication instanceof Closure) {
            $this->notifyPublication($this->playlist);
        }
    }

    /**
     * Read the encoder's local playlist and ensure it continues the published window.
     *
     * @return array{0: list<string>, 1: int}
     */
    protected function readEncoderPlaylist(string $local, int $start): array
    {
        $bytes = file_get_contents($local, length: self::MAX_ENCODER_PLAYLIST_BYTES + 1);

        if ($bytes === false || strlen($bytes) > self::MAX_ENCODER_PLAYLIST_BYTES
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
    protected function publishSegments(int $start, array $lines, int $number): bool
    {
        $duration = null;
        $changed = false;

        foreach ($lines as $line) {
            if (str_starts_with($line, '#EXTINF:')) {
                if ($duration !== null
                    || ! preg_match('/\A#EXTINF:([0-9]+(?:\.[0-9]+)?),\z/', $line, $match)
                    || ! is_finite($duration = (float) $match[1]) || $duration <= 0
                    || $duration > ceil($this->segmentDuration) + 1) {
                    throw MediaException::publication('Invalid live segment duration.', 'artifact');
                }
            } elseif ($line !== '' && $line[0] !== '#') {
                // Ownership derives from our generated prefix and contiguous counter, never from
                // arbitrary, changing playlist references or from scanning the destination.
                $name = $this->prefix.sprintf('%012d', $number).'.ts';

                if ($duration === null || $line !== $name) {
                    throw MediaException::publication('Unowned live segment reference.', 'ownership');
                }

                if ($number >= $this->next) {
                    $key = "{$this->directory}/{$name}";

                    $this->uploadSegment($this->filesystem, "{$this->workspace->path}/{$name}", $key);

                    $this->owned[$key] = $number;
                    $this->entries[] = [
                        'number' => $number,
                        'duration' => $duration,
                        'key' => $key,
                        'discontinuity' => $this->attempts > 1 && $number === $start,
                    ];
                    $this->next = $number + 1;
                    $this->published++;
                    $changed = true;
                }

                $number++;
                $duration = null;
            } elseif (! preg_match(
                '/\A(?:#EXTM3U|#EXT-X-VERSION:[0-9]+|#EXT-X-TARGETDURATION:[0-9]+|#EXT-X-MEDIA-SEQUENCE:[0-9]+|#EXT-X-ENDLIST)\z/',
                $line,
            )) {
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
        // The target duration of a playlist cannot change while it is live, so we will add one
        // second of headroom to cover the ordinary rounding of audio and video frames.
        $text = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:".((int) ceil($this->segmentDuration) + 1)
            ."\n#EXT-X-MEDIA-SEQUENCE:{$this->entries[0]['number']}"
            ."\n#EXT-X-DISCONTINUITY-SEQUENCE:{$this->discontinuities}\n";

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
        foreach (glob("{$workspace->path}/{$prefix}*") ?: [] as $file) {
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
        return new LiveHlsResult(
            $playlist,
            array_column($this->entries, 'key'),
            $this->status,
            $this->attempts,
            $this->published,
            $this->entries === [] ? null : $this->entries[0]['number'],
            $this->entries === [] ? null : $this->entries[array_key_last($this->entries)]['number'],
        );
    }

    /**
     * Wrap a publication failure, listing the files this session left on the destination.
     */
    protected function publicationFailure(Throwable $throwable, string $playlist): MediaException
    {
        // Adapter and process exceptions are never attached as the previous exception
        // because they may contain credentials.
        return new MediaException(
            $throwable instanceof MediaException ? $throwable->getMessage() : 'Live publication failed.',
            $throwable instanceof MediaException ? $throwable->phase : 'publication',
            $throwable instanceof MediaException ? $throwable->category : 'transfer',
            published: array_map(
                fn (string $key): array => ['disk' => is_string($this->disk) ? $this->disk : null, 'path' => $key],
                [...array_keys($this->owned), ...($this->playlistPublished ? [$playlist] : [])],
            ),
        );
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg;

use InvalidArgumentException;
use JsonException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\ProcessRunner;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/**
 * A bounded FFprobe query, separate from the ordinary metadata snapshot.
 */
class PendingProbe
{
    /**
     * The stream selector, or null for every stream.
     */
    protected ?string $selector = null;

    /**
     * The requested field names, keyed by section.
     */
    protected array $fields = [];

    /**
     * The section the query reads: streams, packets or frames.
     */
    protected string $mode = 'streams';

    /**
     * Indicates if only keyframes are decoded.
     */
    protected bool $onlyKeyframes = false;

    /**
     * Indicates if the frames of each stream are counted.
     */
    protected bool $countFrames = false;

    /**
     * Indicates if the packets of each stream are counted.
     */
    protected bool $countPackets = false;

    /**
     * The start of the read interval in seconds.
     */
    protected float $start = 0;

    /**
     * The length of the read interval in seconds.
     */
    protected float $duration = 10;

    /**
     * The number of packets to read instead of a duration.
     */
    protected ?int $packetLimit = null;

    /**
     * The maximum size of the FFprobe output in bytes.
     */
    protected int $outputLimit = 4194304;

    /**
     * The query timeout in seconds.
     */
    protected float $timeout = 30;

    /**
     * Create a new pending probe instance.
     */
    public function __construct(protected readonly Media $media) {}

    /**
     * Limit the query to a global stream index, or to a type and optional zero-based ordinal such as v:0.
     */
    public function selectStreams(string $selector): self
    {
        if (! preg_match('/\A(?:[0-9]{1,6}|[vVasdt](?::[0-9]{1,6})?)\z/', $selector)) {
            throw new InvalidArgumentException(
                'The probe stream selector must be a stream index or a typed selector such as v:0.'
            );
        }

        $this->selector = $selector;

        return $this;
    }

    /**
     * Select the fields to read from each section, without raw FFprobe syntax.
     *
     * @param  array<string, list<string>>  $entries  Field names keyed by section.
     */
    public function entries(array $entries): self
    {
        if ($entries === [] || count($entries) > 9) {
            throw new InvalidArgumentException('Probe entries must select between 1 and 9 sections.');
        }

        foreach ($entries as $section => $fields) {
            if (! in_array($section, [
                'stream', 'stream_tags', 'stream_disposition', 'packet', 'frame',
                'format', 'format_tags', 'chapter', 'chapter_tags',
            ], true)) {
                throw new InvalidArgumentException("The probe section [{$section}] is not supported.");
            }

            if (! is_array($fields) || ! array_is_list($fields) || $fields === [] || count($fields) > 64) {
                throw new InvalidArgumentException("The probe section [{$section}] must list 1 to 64 field names.");
            }

            foreach ($fields as $field) {
                if (! is_string($field) || ! preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,127}\z/', $field)) {
                    throw new InvalidArgumentException('Invalid probe field name.');
                }
            }
        }

        $this->fields = $entries;

        return $this;
    }

    /**
     * Read packets in addition to streams.
     */
    public function packets(): self
    {
        $this->mode = 'packets';
        $this->onlyKeyframes = false;

        return $this;
    }

    /**
     * Read decoded frames in addition to streams.
     */
    public function frames(): self
    {
        $this->mode = 'frames';
        $this->onlyKeyframes = false;

        return $this;
    }

    /**
     * Read only decoded keyframes.
     * These are decoder keyframes, not a claim that every returned timestamp is an exact seek point.
     */
    public function keyframes(): self
    {
        $this->mode = 'frames';
        $this->onlyKeyframes = true;

        return $this;
    }

    /**
     * Count the frames of each stream.
     */
    public function countFrames(bool $enabled = true): self
    {
        $this->countFrames = $enabled;

        return $this;
    }

    /**
     * Count the packets of each stream.
     */
    public function countPackets(bool $enabled = true): self
    {
        $this->countPackets = $enabled;

        return $this;
    }

    /**
     * Read the given interval in seconds.
     * FFprobe starts at a nearby seek point instead of trimming exactly, and times round to microseconds.
     */
    public function readInterval(float $start, float $duration): self
    {
        $this->validateStart($start);
        if (! is_finite($duration) || $duration < 0.000001 || $duration > 300) {
            throw new InvalidArgumentException('Probe duration must be between one microsecond and 300 seconds.');
        }

        $this->start = $start;
        $this->duration = $duration;
        $this->packetLimit = null;

        return $this;
    }

    /**
     * Read the given number of demuxed packets from the start time.
     * The limit bounds packets, not decoded frames, so B-frame decoder delay can affect frame counts.
     */
    public function readPackets(int $count, float $start = 0): self
    {
        $this->validateStart($start);
        if ($count < 1 || $count > 100000) {
            throw new InvalidArgumentException('Probe packet count must be between 1 and 100000.');
        }

        $this->start = $start;
        $this->packetLimit = $count;

        return $this;
    }

    /**
     * Set the maximum output size in bytes and the timeout in seconds.
     */
    public function limits(int $outputBytes = 4194304, float $timeout = 30): self
    {
        if ($outputBytes < 1 || $outputBytes > 16777216) {
            throw new InvalidArgumentException(
                "The probe output limit [{$outputBytes}] must be between 1 and 16777216 bytes."
            );
        }

        if (! is_finite($timeout) || $timeout <= 0 || $timeout > 120) {
            throw new InvalidArgumentException(
                "The probe timeout [{$timeout}] must be greater than 0 and at most 120 seconds."
            );
        }

        $this->outputLimit = $outputBytes;
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Run the query against the media and get its result.
     */
    public function get(): ProbeResult
    {
        return $this->media->withInput(
            fn (string $path, Workspace $workspace): ProbeResult => $this->query($path, $workspace),
            new ProcessRunner($this->timeout),
        );
    }

    /**
     * Run the query against the staged input.
     */
    protected function query(string $path, Workspace $workspace): ProbeResult
    {
        $runner = $workspace->runner;
        $binary = config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe');
        // The runner's operation budget is this query's timeout.
        $runner->version($binary, 'ffprobe', $workspace->path);

        // This is the same staged source and probe arguments used by Media::probe().
        $protocols = $this->media->protocolWhitelist();
        $workspace->requireProtocols($binary, $protocols);
        $command = $this->command($binary, $protocols, $path);
        $redactions = [$path => '[input]'] + $this->media->redactions();
        $result = $runner->run($command, $workspace->path, $redactions, $this->outputLimit, timeout: $this->timeout);
        if ($result->exitCode !== 0) {
            throw MediaException::probing(
                'FFprobe query failed (exit '.$result->exitCode.').',
                'exit',
                $result->diagnostics,
            );
        }

        $data = Media::redactProbeData($this->decode($result->output), $redactions);

        return new ProbeResult($data, [
            'start' => $this->start, 'duration' => $this->packetLimit === null ? $this->duration : null,
            'packets' => $this->packetLimit, 'interval' => $this->interval(),
            'output_bytes' => $this->outputLimit, 'timeout' => $this->timeout,
        ]);
    }

    /**
     * Build the FFprobe command for the staged input.
     */
    protected function command(string $binary, string $protocols, string $path): array
    {
        $command = [$binary, '-v', 'error', '-print_format', 'json', '-protocol_whitelist', $protocols,
            ...$this->media->probeOptions(), '-read_intervals', $this->interval(), '-show_streams'];
        if ($this->selector !== null) {
            $command[] = '-select_streams';
            $command[] = $this->selector;
        }

        if ($this->mode !== 'streams') {
            $command[] = '-show_'.$this->mode;
        }

        if ($this->onlyKeyframes) {
            $command[] = '-skip_frame';
            $command[] = 'nokey';
        }

        foreach (['frames' => $this->countFrames, 'packets' => $this->countPackets] as $kind => $enabled) {
            if ($enabled) {
                $command[] = '-count_'.$kind;
            }
        }

        return [...$command, ...$this->entryArguments(), $path];
    }

    /**
     * Build the arguments that select the requested sections and fields.
     */
    protected function entryArguments(): array
    {
        if ($this->fields === []) {
            return [];
        }

        $arguments = [];
        $entries = [];
        foreach ($this->fields as $section => $fields) {
            $entries[] = $section.'='.implode(',', $fields);
            if ($section === 'format' || $section === 'format_tags') {
                $arguments[] = '-show_format';
            } elseif ($section === 'chapter' || $section === 'chapter_tags') {
                $arguments[] = '-show_chapters';
            }
        }

        return [...$arguments, '-show_entries', implode(':', $entries)];
    }

    /**
     * Decode the query output and ensure each section is a list of rows.
     */
    protected function decode(string $output): array
    {
        try {
            $data = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw MediaException::probing('FFprobe query returned invalid JSON.', 'metadata');
        }

        // A top-level JSON list also decodes to an array, so we also require the output to
        // start as an object.
        if (! is_array($data) || ! str_starts_with(ltrim($output), '{')) {
            throw MediaException::probing('FFprobe query returned invalid sections.', 'metadata');
        }

        foreach (['streams', 'packets', 'frames', 'chapters'] as $section) {
            if (array_key_exists($section, $data)
                && (! is_array($data[$section])
                    || ! array_is_list($data[$section])
                    || array_any($data[$section], fn ($row): bool => ! is_array($row)))) {
                throw MediaException::probing('FFprobe query returned invalid sections.', 'metadata');
            }
        }

        return $data;
    }

    /**
     * Get the read interval argument for FFprobe.
     */
    protected function interval(): string
    {
        // FFprobe's duration parser does not accept scientific notation.
        $seconds = static fn (float $value): string => rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $seconds($this->start).'%+'.($this->packetLimit === null
            ? $seconds($this->duration) : '#'.$this->packetLimit);
    }

    /**
     * Ensure the given start time is finite and within range.
     */
    protected function validateStart(float $start): void
    {
        if (! is_finite($start) || $start < 0 || $start > 315360000) {
            throw new InvalidArgumentException('Probe start must be finite and between 0 and 315360000 seconds.');
        }
    }
}

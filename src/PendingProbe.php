<?php

namespace ProtoneMedia\LaravelFFMpeg;

use InvalidArgumentException;
use JsonException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\ProcessRunner;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/** A bounded query, separate from the ordinary Metadata snapshot. */
class PendingProbe
{
    protected ?string $selector = null;

    protected array $fields = [];

    protected string $mode = 'streams';

    protected bool $onlyKeyframes = false;

    protected bool $countFrames = false;

    protected bool $countPackets = false;

    protected float $start = 0;

    protected float $duration = 10;

    protected ?int $packetLimit = null;

    protected int $outputLimit = 4194304;

    protected float $timeout = 30;

    public function __construct(protected readonly Media $media) {}

    /** Global stream index, or a type and optional zero-based type ordinal (v:0). */
    public function selectStreams(string $selector): self
    {
        if (! preg_match('/\A(?:[0-9]{1,6}|[vVasdt](?::[0-9]{1,6})?)\z/', $selector)) {
            throw new InvalidArgumentException('Use a stream index or a typed stream selector such as v:0.');
        }

        $this->selector = $selector;

        return $this;
    }

    /** @param array<string, list<string>> $entries Section => field names; no raw FFprobe syntax. */
    public function entries(array $entries): self
    {
        if ($entries === [] || count($entries) > 9) {
            throw new InvalidArgumentException('Select at least one probe section.');
        }

        foreach ($entries as $section => $fields) {
            if (! in_array($section, ['stream', 'stream_tags', 'stream_disposition', 'packet', 'frame', 'format', 'format_tags', 'chapter', 'chapter_tags'], true)
                || ! is_array($fields) || ! array_is_list($fields) || $fields === [] || count($fields) > 64) {
                throw new InvalidArgumentException('Probe entries require known sections and 1 to 64 field names per section.');
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

    public function packets(): self
    {
        $this->mode = 'packets';
        $this->onlyKeyframes = false;

        return $this;
    }

    public function frames(): self
    {
        $this->mode = 'frames';
        $this->onlyKeyframes = false;

        return $this;
    }

    /** Decoder keyframes, not a claim that every returned timestamp is an exact seek point. */
    public function keyframes(): self
    {
        $this->mode = 'frames';
        $this->onlyKeyframes = true;

        return $this;
    }

    public function countFrames(bool $enabled = true): self
    {
        $this->countFrames = $enabled;

        return $this;
    }

    public function countPackets(bool $enabled = true): self
    {
        $this->countPackets = $enabled;

        return $this;
    }

    /** FFprobe seeks to a nearby seek point, not exact trimming. Times round to microseconds. */
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

    /** Bound demuxed packets, not decoded frames; B-frame decoder delay can affect frame counts. */
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

    public function limits(int $outputBytes = 4194304, float $timeout = 30): self
    {
        if ($outputBytes < 1 || $outputBytes > 16777216 || ! is_finite($timeout) || $timeout <= 0 || $timeout > 120) {
            throw new InvalidArgumentException('Probe limits require 1 to 16777216 bytes and a positive timeout up to 120 seconds.');
        }

        $this->outputLimit = $outputBytes;
        $this->timeout = $timeout;

        return $this;
    }

    public function get(): ProbeResult
    {
        return $this->media->withInput(function (string $path, Workspace $workspace): ProbeResult {
            $runner = $workspace->runner;
            $binary = config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe');
            // The runner's operation budget is this query's timeout.
            $runner->version($binary, 'ffprobe', $workspace->path);

            // This is the same staged source and probe arguments used by Media::probe().
            $protocols = $this->media->protocolWhitelist();
            $workspace->requireProtocols($binary, $protocols);
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

            if ($this->fields !== []) {
                $entries = [];
                foreach ($this->fields as $section => $fields) {
                    $entries[] = $section.'='.implode(',', $fields);
                    if ($section === 'format' || $section === 'format_tags') {
                        $command[] = '-show_format';
                    } elseif ($section === 'chapter' || $section === 'chapter_tags') {
                        $command[] = '-show_chapters';
                    }
                }

                $command[] = '-show_entries';
                $command[] = implode(':', $entries);
            }

            $command[] = $path;
            $redactions = [$path => '[input]'] + $this->media->redactions();
            $result = $runner->run($command, $workspace->path, $redactions, $this->outputLimit, timeout: $this->timeout);
            if ($result->exitCode !== 0) {
                throw new MediaException('FFprobe query failed (exit '.$result->exitCode.').', 'probing', 'exit', $result->diagnostics);
            }

            try {
                $data = json_decode($result->output, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new MediaException('FFprobe query returned invalid JSON.', 'probing', 'metadata');
            }

            if (! is_array($data) || ! str_starts_with(ltrim($result->output), '{')) {
                throw new MediaException('FFprobe query returned invalid sections.', 'probing', 'metadata');
            }

            foreach (['streams', 'packets', 'frames', 'chapters'] as $section) {
                if (array_key_exists($section, $data) && (! is_array($data[$section]) || ! array_is_list($data[$section])
                    || array_any($data[$section], fn ($row): bool => ! is_array($row)))) {
                    throw new MediaException('FFprobe query returned invalid sections.', 'probing', 'metadata');
                }
            }

            $data = Media::redactProbeData($data, $redactions);

            return new ProbeResult($data, [
                'start' => $this->start, 'duration' => $this->packetLimit === null ? $this->duration : null,
                'packets' => $this->packetLimit, 'interval' => $this->interval(),
                'output_bytes' => $this->outputLimit, 'timeout' => $this->timeout,
            ]);
        }, new ProcessRunner($this->timeout));
    }

    protected function interval(): string
    {
        // FFprobe's duration parser does not accept scientific notation.
        $seconds = static fn (float $value): string => rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $seconds($this->start).'%+'.($this->packetLimit === null
            ? $seconds($this->duration) : '#'.$this->packetLimit);
    }

    protected function validateStart(float $start): void
    {
        if (! is_finite($start) || $start < 0 || $start > 315360000) {
            throw new InvalidArgumentException('Probe start must be finite and between 0 and 315360000 seconds.');
        }
    }
}

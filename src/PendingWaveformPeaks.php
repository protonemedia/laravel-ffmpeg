<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/** Audio data, not the separate waveform image operation. */
class PendingWaveformPeaks extends PendingExport
{
    protected string $outputRole = 'waveform-peaks';

    private int $track = 0;

    private bool $mixMono = false;

    private ?int $targetBuckets = null;

    private int $rate = 0;

    private int $inputChannels = 0;

    private ?string $inputLayout = null;

    private ?WaveformPeaks $peaks = null;

    // Limits aggregate cells, not decoded input length. PCM is spooled to the
    // private operation workspace and read in bounded chunks, never stdout.
    private const MAX_CELLS = 100000;

    public function __construct(Media $media, string|Filesystem $disk, private readonly int $samplesPerBucket = 256)
    {
        parent::__construct($media, $disk);
        if ($samplesPerBucket < 1) {
            throw new InvalidArgumentException('Samples per bucket must be positive.');
        }
    }

    /** Zero-based audio stream ordinal, not the global stream index. */
    public function audioTrack(int $track): self
    {
        $this->assertOutputMutable();
        if ($track < 0) {
            throw new InvalidArgumentException('The audio track must be nonnegative.');
        }
        $this->track = $track;

        return $this;
    }

    /** Equal-weight arithmetic downmix before reduction; otherwise retain channels. */
    public function mono(bool $enabled = true): self
    {
        $this->assertOutputMutable();
        $this->mixMono = $enabled;

        return $this;
    }

    /** Request at most this many nonempty, evenly partitioned buckets. */
    public function buckets(int $count): self
    {
        $this->assertOutputMutable();
        if ($count < 1 || $count > self::MAX_CELLS) {
            throw new InvalidArgumentException('Bucket count must be between 1 and 100000.');
        }
        $this->targetBuckets = $count;

        return $this;
    }

    public function output(string $path, callable $configure): self
    {
        throw new InvalidArgumentException('Peaks support one root-configured output.');
    }

    public function get(): WaveformPeaks
    {
        $this->execute('peaks.json', contents: true);

        return $this->peaks;
    }

    protected function prepareEncoding(array $metadata): ?float
    {
        $stream = $metadata[0]->audioStreams()->get($this->track);
        $this->rate = (int) ($stream?->get('sample_rate') ?? 0);
        $this->inputChannels = (int) ($stream?->get('channels') ?? 0);
        if ($this->rate < 1 || $this->inputChannels < 1 || $this->inputChannels > 64) {
            throw new MediaException('The selected audio track requires a sample rate and 1 to 64 channels.', 'probing', 'mapping');
        }
        // FFprobe reports custom orders as "4 channels (FL+FR+LFE+BC)".
        $layout = (string) ($stream->get('channel_layout') ?? '');
        if (preg_match('/\A[0-9]+ channels \(([A-Za-z0-9+]+)\)\z/', $layout, $match)) {
            $layout = $match[1];
        }
        $this->inputLayout = $layout !== 'unknown' && preg_match('/\A[A-Za-z0-9.+()]+\z/', $layout) ? $layout : null;

        return parent::prepareEncoding($metadata);
    }

    protected function command(array $outputs, array $paths, ?array $outputPaths = null): array
    {
        if (count($outputs) !== 1 || count($paths) !== 1 || $outputs[0]->hasMaps() || $outputs[0]->inputSeekOptions() !== []) {
            throw new InvalidArgumentException('Peaks require one input/output, audioTrack() selection, and accurate clips.');
        }
        // A per-track filter would be lost by mono() and a per-track rate overridden by the PCM -ar below.
        if ($outputs[0]->outputConfiguration()['audioStreams'] !== []) {
            throw new InvalidArgumentException('Peaks accept audioFilter(), not per-track audio settings.');
        }
        $command = parent::command($outputs, $paths, $outputPaths);
        if (in_array('-filter_complex', $command, true)) {
            throw new InvalidArgumentException('Peaks accept audioFilter(), not a complex graph.');
        }
        // Replace the export pipeline's default optional video/audio maps with
        // exactly one required audio track (raw PCM supports one stream).
        while (($index = array_search('-map', $command, true)) !== false) {
            array_splice($command, $index, 2);
        }
        // Preserve the decoded track rate and channel layout even if caller
        // preprocessing changes format. JSON timing describes these PCM frames.
        // -ac alone would remix any layout that is not FFmpeg's default for its count.
        $channels = $this->mixMono ? 1 : ($this->inputChannels ?: 1);
        if ($this->mixMono && $this->inputChannels > 0) {
            $terms = array_map(fn ($i) => sprintf('%.17g', 1 / $this->inputChannels).'*c'.$i, range(0, $this->inputChannels - 1));
            $pan = 'pan=mono|c0='.implode('+', $terms);
            $index = array_search('-filter:a', $command, true);
            if ($index === false) {
                array_splice($command, -1, 0, ['-filter:a', $pan]);
            } else {
                $command[$index + 1] .= ','.$pan;
            }
        }
        array_splice($command, -1, 0, ['-map', '0:a:'.$this->track, '-vn', '-sn', '-dn', '-c:a', 'pcm_s16le',
            '-ar', (string) ($this->rate ?: 48000),
            ...($this->mixMono || $this->inputLayout === null ? ['-ac', (string) $channels] : ['-ch_layout', $this->inputLayout]),
            '-f', 's16le']);

        return $command;
    }

    protected function outputPaths(array $outputs, Workspace $workspace): array
    {
        return [$workspace->path.'/decoded.s16le'];
    }

    protected function artifacts(array $outputs, array $paths): array
    {
        $path = $paths[0];
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : false;
        $channels = $this->mixMono ? 1 : $this->inputChannels;
        $frameBytes = $channels * 2;
        if ($size === false || $size % $frameBytes !== 0) {
            throw new MediaException('The decoded PCM has an incomplete frame.', 'validation', 'artifact');
        }
        $frames = intdiv($size, $frameBytes);
        $count = $frames === 0 ? 0 : ($this->targetBuckets === null
            ? intdiv($frames - 1, $this->samplesPerBucket) + 1 : min($frames, $this->targetBuckets));
        if ($count > intdiv(self::MAX_CELLS, $channels)) {
            throw new MediaException('Peaks exceed 100000 channel buckets; request fewer buckets.', 'validation', 'output_limit');
        }
        $data = array_fill(0, $channels, []);
        $lengths = [];
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new MediaException('Cannot read decoded PCM.', 'validation', 'filesystem');
        }
        try {
            for ($bucket = 0; $bucket < $count; $bucket++) {
                // Quotient/remainder avoids overflowing frames * bucket.
                $length = $this->targetBuckets === null ? min($this->samplesPerBucket, $frames - $bucket * $this->samplesPerBucket)
                    : intdiv($frames, $count) + ($bucket < $frames % $count ? 1 : 0);
                $lengths[] = $length;
                $min = array_fill(0, $channels, 32767);
                $max = array_fill(0, $channels, -32768);
                $remaining = $length;
                while ($remaining > 0) {
                    $take = min($remaining, 2048);
                    $bytes = fread($file, $take * $frameBytes);
                    if ($bytes === false || strlen($bytes) !== $take * $frameBytes) {
                        throw new MediaException('Cannot read complete PCM frames.', 'validation', 'filesystem');
                    }
                    // v is literally unsigned little-endian 16-bit, independent
                    // of host byte order. Convert two's complement explicitly.
                    foreach (unpack('v*', $bytes) as $index => $unsigned) {
                        $channel = ($index - 1) % $channels;
                        $sample = $unsigned >= 32768 ? $unsigned - 65536 : $unsigned;
                        $min[$channel] = min($min[$channel], $sample);
                        $max[$channel] = max($max[$channel], $sample);
                    }
                    $remaining -= $take;
                }
                for ($channel = 0; $channel < $channels; $channel++) {
                    $data[$channel][] = [$min[$channel], $max[$channel]];
                }
            }
        } finally {
            fclose($file);
        }
        $this->peaks = new WaveformPeaks($this->rate, $this->inputChannels, $this->track,
            $this->mixMono ? 'mono' : 'separate', $frames, $this->targetBuckets === null ? $this->samplesPerBucket : null, $lengths, $data);
        $json = json_encode($this->peaks, JSON_THROW_ON_ERROR);
        $target = dirname($path).'/peaks.json';
        if (@file_put_contents($target, $json) !== strlen($json) || ! chmod($target, 0600)) {
            throw new MediaException('Cannot write waveform peaks.', 'validation', 'filesystem');
        }

        return [['path' => $target, 'output' => 0, 'destination' => $outputs[0]->destination()]];
    }
}

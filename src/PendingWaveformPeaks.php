<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\MeasuresDuration;
use ProtoneMedia\LaravelFFMpeg\Pipeline\PackagesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesCommand;

/** Audio data, not the separate waveform image operation. */
class PendingWaveformPeaks implements Exportable, MeasuresDuration, PackagesArtifacts, ShapesCommand
{
    use Concerns\AudioFilters;
    use Concerns\Clips;
    use Concerns\Fades;
    use Concerns\GlobalOptions;
    use Concerns\OutputOptions;
    use Concerns\RunsExport;
    use Conditionable, Macroable;

    protected readonly PendingExport $export;

    protected int $track = 0;

    protected bool $mixMono = false;

    protected ?int $targetBuckets = null;

    protected int $rate = 0;

    protected int $inputChannels = 0;

    protected ?string $inputLayout = null;

    protected ?WaveformPeaks $peaks = null;

    // Limits aggregate cells, not decoded input length. PCM is spooled to the
    // private operation workspace and read in bounded chunks, never stdout.
    protected const int MAX_CELLS = 100000;

    public function __construct(Media $media, string|Filesystem $disk, protected readonly int $samplesPerBucket = 256)
    {
        if ($samplesPerBucket < 1) {
            throw new InvalidArgumentException('Samples per bucket must be positive.');
        }

        $this->export = new PendingExport($media, $disk, $this, 'waveform-peaks');
    }

    /** Zero-based audio stream ordinal, not the global stream index. */
    public function audioTrack(int $track): self
    {
        $this->export->assertOutputMutable();
        if ($track < 0) {
            throw new InvalidArgumentException('The audio track must be nonnegative.');
        }

        $this->track = $track;

        return $this;
    }

    /** Equal-weight arithmetic downmix before reduction; otherwise retain channels. */
    public function mono(bool $enabled = true): self
    {
        $this->export->assertOutputMutable();
        $this->mixMono = $enabled;

        return $this;
    }

    /** Request at most this many nonempty, evenly partitioned buckets. */
    public function buckets(int $count): self
    {
        $this->export->assertOutputMutable();
        if ($count < 1 || $count > self::MAX_CELLS) {
            throw new InvalidArgumentException('Bucket count must be between 1 and 100000.');
        }

        $this->targetBuckets = $count;

        return $this;
    }

    public function get(): WaveformPeaks
    {
        $this->export->execute('peaks.json', contents: true);

        return $this->peaks;
    }

    public function save(?string $path = null): WaveformPeaksResult
    {
        return new WaveformPeaksResult($this->export->save($path), $this->peaks);
    }

    /** @internal Records the selected track's rate and layout for the PCM decode; progress keeps the default duration. */
    public function duration(array $metadata, ?float $default): ?float
    {
        $stream = $metadata[0]->audioStreams()->get($this->track);
        $this->rate = (int) ($stream?->get('sample_rate') ?? 0);
        $this->inputChannels = (int) ($stream?->get('channels') ?? 0);
        if ($this->rate < 1 || $this->inputChannels < 1 || $this->inputChannels > 64) {
            throw MediaException::probing('The selected audio track requires a sample rate and 1 to 64 channels.', 'mapping');
        }

        // FFprobe reports custom orders as "4 channels (FL+FR+LFE+BC)".
        $layout = (string) ($stream->get('channel_layout') ?? '');
        if (preg_match('/\A[0-9]+ channels \(([A-Za-z0-9+]+)\)\z/', $layout, $match)) {
            $layout = $match[1];
        }

        $this->inputLayout = $layout !== 'unknown' && preg_match('/\A[A-Za-z0-9.+()]+\z/', $layout) ? $layout : null;

        return $default;
    }

    /** @internal */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        if ($outputs[0]->inputSeekOptions() !== []) {
            throw new InvalidArgumentException('Peaks require accurate clips, not keyframe seek.');
        }

        $command = $default;
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
            $terms = array_map(fn ($i): string => sprintf('%.17g', 1 / $this->inputChannels).'*c'.$i, range(0, $this->inputChannels - 1));
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

    /** @internal */
    public function outputPaths(array $default, array $outputs, Workspace $workspace): array
    {
        return [$workspace->path.'/decoded.s16le'];
    }

    /** @internal Reduces the decoded PCM to bounded extrema and publishes them as version-1 JSON. */
    public function artifacts(array $default, array $outputs, array $paths): array
    {
        $path = $paths[0];
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : false;
        $channels = $this->mixMono ? 1 : $this->inputChannels;
        $frameBytes = $channels * 2;
        if ($size === false || $size % $frameBytes !== 0) {
            throw MediaException::validation('The decoded PCM has an incomplete frame.', 'artifact');
        }

        $frames = intdiv($size, $frameBytes);
        $count = $frames === 0 ? 0 : ($this->targetBuckets === null
            ? intdiv($frames - 1, $this->samplesPerBucket) + 1 : min($frames, $this->targetBuckets));
        if ($count > intdiv(self::MAX_CELLS, $channels)) {
            throw MediaException::validation('Peaks exceed 100000 channel buckets; request fewer buckets.', 'output_limit');
        }

        $data = array_fill(0, $channels, []);
        $lengths = [];
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw MediaException::validation('Cannot read decoded PCM.', 'filesystem');
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
                        throw MediaException::validation('Cannot read complete PCM frames.', 'filesystem');
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
            throw MediaException::validation('Cannot write waveform peaks.', 'filesystem');
        }

        return [['path' => $target, 'output' => 0, 'destination' => $outputs[0]->destination()]];
    }
}

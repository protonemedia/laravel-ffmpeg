<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\MeasuresDuration;
use ProtoneMedia\LaravelFFMpeg\Pipeline\OwnsGraph;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesOutputs;

/** Joins sources end to end after normalizing their geometry, frame rate and audio format. */
final class PendingConcat implements Exportable, MeasuresDuration, OwnsGraph, ShapesOutputs
{
    use Concerns\Clips;
    use Concerns\GlobalOptions;
    use Concerns\OutputOptions;
    use Concerns\RunsExport;
    use Conditionable;

    private readonly PendingExport $export;

    private ?array $normalization = null;

    private ?array $audioNormalization = null;

    /** @param array<Media> $sources */
    public function __construct(private readonly array $sources, string|Filesystem $disk)
    {
        if (! array_is_list($sources) || count($sources) < 2) {
            throw new InvalidArgumentException('Transcoded concat requires at least two media inputs.');
        }

        $this->export = new PendingExport($sources[0], $disk, $this);

        foreach (array_slice($sources, 1) as $source) {
            $this->export->addInput($source);
        }

        // addInput() falls back to the default disk; keep the first input's.
        $this->export->toDisk($disk);
    }

    public function normalizeVideo(int $width, int $height, int|float|string $frameRate, string $fit = 'contain'): self
    {
        $this->export->assertOutputMutable();

        if ($width <= 0 || $height <= 0 || $width % 2 || $height % 2
            || ! in_array($fit, ['contain', 'cover', 'stretch'], true)) {
            throw new InvalidArgumentException('Normalization requires positive even dimensions, a positive frame rate, and contain, cover, or stretch.');
        }

        $geometry = new ExportOutput('normalization.mp4', $this->export->outputConfiguration()['disk']);
        match ($fit) {
            'stretch' => $geometry->resize($width, $height),
            'cover' => $geometry->cover($width, $height),
            'contain' => $geometry->contain($width, $height),
        };
        $geometry->frameRate($frameRate);
        $this->normalization = [...$geometry->outputConfiguration()['videoFilters'], 'setsar=1', 'setpts=PTS-STARTPTS'];

        return $this;
    }

    public function normalizeAudio(int $sampleRate = 48000, int $channels = 2, ?int $channel = null): self
    {
        $this->export->assertOutputMutable();

        if ($sampleRate <= 0 || ! in_array($channels, [1, 2], true) || ($channel !== null && $channel < 0)) {
            throw new InvalidArgumentException('Audio normalization requires a positive sample rate, mono or stereo, and a nonnegative channel index.');
        }

        $this->audioNormalization = ['sampleRate' => $sampleRate, 'channels' => $channels, 'channel' => $channel];

        return $this;
    }

    public function video(?string $codec = null, mixed $bitrate = null, ?float $crf = null, ?string $preset = null): self
    {
        $this->export->video($codec, $bitrate, $crf, $preset);

        return $this;
    }

    public function videoOptions(array $tokens): self
    {
        $this->export->videoOptions($tokens);

        return $this;
    }

    public function audio(?string $codec = null, mixed $bitrate = null): self
    {
        $this->export->audio($codec, $bitrate);

        return $this;
    }

    public function audioOptions(array $tokens): self
    {
        $this->export->audioOptions($tokens);

        return $this;
    }

    public function withoutAudio(): self
    {
        $this->export->withoutAudio();

        return $this;
    }

    public function metadata(string $key, string $value): self
    {
        $this->export->metadata($key, $value);

        return $this;
    }

    public function streamMetadata(string|int $stream, string $key, string $value): self
    {
        $this->export->streamMetadata($stream, $key, $value);

        return $this;
    }

    public function disposition(string|int $stream, string|array $flags): self
    {
        $this->export->disposition($stream, $flags);

        return $this;
    }

    public function twoPass(): self
    {
        $this->export->twoPass();

        return $this;
    }

    public function save(?string $path = null): ExportResult
    {
        return $this->export->save($path);
    }

    /** @internal */
    public function outputs(array $default, ?string $path): array
    {
        $settings = $this->export->outputConfiguration();

        if ($this->normalization === null || ($this->audioNormalization === null && ! $settings['omitAudio'])) {
            throw new InvalidArgumentException('Choose normalizeVideo() and either normalizeAudio() or withoutAudio().');
        }

        if (($settings['omitAudio'] && $this->audioNormalization !== null)
            || ($settings['video']['codec'] ?? null) === 'copy' || ($settings['audio']['codec'] ?? null) === 'copy') {
            throw new InvalidArgumentException('Transcoded concat requires transcoded video and audio, and either normalizeAudio() or withoutAudio(), not both.');
        }

        if (array_any($this->sources, fn (Media $source): bool => $source->inputOptions() !== [])) {
            throw new InvalidArgumentException('Transcoded concat does not support input options that can change source timing.');
        }

        $settings['maps'] = ['[concat_video]'];

        if ($this->audioNormalization !== null) {
            $settings['maps'][] = '[concat_audio]';
        }

        $settings['omitAudio'] = false;
        $settings['metadataSource'] = $settings['chapterSource'] = -1;

        return [new ExportOutput($path ?? 'inspection.mp4', $settings['disk'])->configure($settings)];
    }

    /** @internal */
    public function graph(): string
    {
        $branches = [];
        $pads = [];
        foreach ($this->sources as $index => $source) {
            $branches[] = '['.$index.':V:0]'.implode(',', $this->normalization ?? []).'[concat_v'.$index.']';
            $pads[] = '[concat_v'.$index.']';

            if ($this->audioNormalization !== null) {
                ['sampleRate' => $rate, 'channels' => $channels, 'channel' => $channel] = $this->audioNormalization;
                $pan = $channel === null ? '' : 'pan=mono|c0=c'.$channel.',';
                $branches[] = '['.$index.':a:0]'.$pan.'aresample='.$rate.':async=1:first_pts=0,aformat=sample_rates='.$rate.':channel_layouts='.($channels === 1 ? 'mono' : 'stereo').'[concat_a'.$index.']';
                $pads[] = '[concat_a'.$index.']';
            }
        }

        $branches[] = implode('', $pads).'concat=n='.count($this->sources).':v=1:a='.($this->audioNormalization === null ? '0[concat_video]' : '1[concat_video][concat_audio]');

        return implode(';', $branches);
    }

    /** @internal Validates every source; filter frame rounding and audio tails keep progress indeterminate. */
    public function duration(array $metadata, ?float $default): ?float
    {
        foreach ($metadata as $item) {
            if ($item->video() === null) {
                throw new MediaException('Transcoded concat requires a video stream in every source.', 'probing', 'mapping');
            }

            if ($item->duration() === null || $item->duration() <= 0) {
                throw new MediaException('Transcoded concat requires finite sources.', 'probing', 'metadata');
            }

            PendingExport::assertZeroOrigins($item, 'Transcoded concat');

            if ($this->audioNormalization !== null) {
                $audio = $item->audio();
                $channel = $this->audioNormalization['channel'];

                if ($audio === null || ($channel !== null && ($audio->channels() === null || $channel >= $audio->channels()))) {
                    throw new MediaException('Every concat source requires audio with the requested channel.', 'probing', 'mapping');
                }

                if (! is_numeric($audio->get('start_time')) || (float) $audio->get('start_time') < 0) {
                    throw new MediaException('Every concat source requires audio with a nonnegative origin.', 'probing', 'metadata');
                }
            }
        }

        // Filter frame rounding and audio tails can alter the sum; leave progress indeterminate.
        return null;
    }
}

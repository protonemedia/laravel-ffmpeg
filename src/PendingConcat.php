<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

class PendingConcat extends PendingExport
{
    private ?array $normalization = null;

    private ?array $audioNormalization = null;

    /** @param array<Media> $sources */
    public function __construct(private readonly array $sources, string|Filesystem $disk)
    {
        if (! array_is_list($sources) || count($sources) < 2
            || array_any($sources, fn ($source) => ! $source instanceof Media)) {
            throw new InvalidArgumentException('Transcoded concat requires at least two media inputs.');
        }

        parent::__construct($sources[0], $disk);

        foreach (array_slice($sources, 1) as $source) {
            parent::addInput($source);
        }

        $this->toDisk($disk);
    }

    public function normalizeVideo(int $width, int $height, int|float|string $frameRate, string $fit = 'contain'): self
    {
        $this->assertOutputMutable();

        if ($width <= 0 || $height <= 0 || $width % 2 || $height % 2
            || ! in_array($fit, ['contain', 'cover', 'stretch'], true)) {
            throw new InvalidArgumentException('Normalization requires positive even dimensions, a positive frame rate, and contain, cover, or stretch.');
        }

        $geometry = new ExportOutput('normalization.mp4', $this->outputConfiguration()['disk']);
        $fit === 'stretch' ? $geometry->resize($width, $height) : $geometry->fit($width, $height, $fit);
        $geometry->frameRate($frameRate);
        $this->normalization = [...$geometry->outputConfiguration()['videoFilters'], 'setsar=1', 'setpts=PTS-STARTPTS'];

        return $this;
    }

    public function normalizeAudio(int $sampleRate = 48000, int $channels = 2, ?int $channel = null): self
    {
        $this->assertOutputMutable();

        if ($sampleRate <= 0 || ! in_array($channels, [1, 2], true) || ($channel !== null && $channel < 0)) {
            throw new InvalidArgumentException('Audio normalization requires a positive sample rate, mono or stereo, and a nonnegative channel index.');
        }

        $this->audioNormalization = compact('sampleRate', 'channels', 'channel');

        return $this;
    }

    public function addInput(Media $media): int
    {
        throw new InvalidArgumentException('Declare all concat inputs in concatTranscoded().');
    }

    public function output(string $path, callable $configure): self
    {
        throw new InvalidArgumentException('Transcoded concat supports one root-configured output.');
    }

    public function filterComplex(string $expression): self
    {
        throw new InvalidArgumentException('Transcoded concat owns its graph; use export()->filterComplex() for custom graphs.');
    }

    protected function configuredOutputs(?string $path = null): array
    {
        $settings = $this->outputConfiguration();

        if ($this->normalization === null || ($this->audioNormalization === null && ! $settings['omitAudio'])) {
            throw new InvalidArgumentException('Choose normalizeVideo() and either normalizeAudio() or withoutAudio().');
        }

        if ($settings['omitVideo'] || ($settings['omitAudio'] && $this->audioNormalization !== null)
            || $settings['videoFilters'] !== [] || $settings['audioFilters'] !== []
            || $settings['videoStreams'] !== [] || $settings['audioStreams'] !== []
            || $settings['maps'] !== [] || ($settings['metadataSource'] ?? -1) >= 0 || ($settings['chapterSource'] ?? -1) >= 0
            || ($settings['video']['codec'] ?? null) === 'copy' || ($settings['audio']['codec'] ?? null) === 'copy') {
            throw new InvalidArgumentException('Transcoded concat owns its maps and source metadata policy, and requires transcoded video without root filters or conflicting audio policy.');
        }

        if (array_any($this->sources, fn ($source) => $source->inputOptions() !== [])) {
            throw new InvalidArgumentException('Transcoded concat does not support input options that can change source timing.');
        }

        $settings['maps'] = ['[concat_video]'];

        if ($this->audioNormalization !== null) {
            $settings['maps'][] = '[concat_audio]';
        }

        $settings['omitAudio'] = $settings['omitVideo'] = false;
        $settings['metadataSource'] = $settings['chapterSource'] = -1;

        return [(new ExportOutput($path ?? 'inspection.mp4', $settings['disk']))->configure($settings)];
    }

    protected function filterGraph(): ?string
    {
        $branches = $pads = [];

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

    protected function prepareEncoding(array $metadata): ?float
    {
        foreach ($metadata as $item) {
            if ($item->video() === null) {
                throw new MediaException('Transcoded concat requires a video stream in every source.', 'probing', 'mapping');
            }
            if ($item->duration() === null || $item->duration() <= 0) {
                throw new MediaException('Transcoded concat requires finite sources.', 'probing', 'metadata');
            }
            $this->assertZeroOrigins($item, 'Transcoded concat');

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

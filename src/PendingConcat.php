<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\MeasuresDuration;
use ProtoneMedia\LaravelFFMpeg\Pipeline\OwnsGraph;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesOutputs;

/**
 * Sources joined end to end after conforming their geometry, frame rate and audio format.
 */
class PendingConcat implements Exportable, MeasuresDuration, OwnsGraph, ShapesOutputs
{
    use Concerns\Clips;
    use Concerns\GlobalOptions;
    use Concerns\OutputOptions;
    use Concerns\RunsExport;
    use Conditionable, Macroable;

    /**
     * The export pipeline this builder configures.
     */
    protected readonly PendingExport $export;

    /**
     * The video filters that conform every source.
     */
    protected ?array $videoConformance = null;

    /**
     * The sample rate, channel count and source channel that every audio track is conformed to.
     */
    protected ?array $audioConformance = null;

    /**
     * Create a new pending concat instance.
     *
     * @param  array<Media>  $sources
     */
    public function __construct(protected readonly array $sources, string|Filesystem $disk)
    {
        if (! array_is_list($sources)) {
            throw new InvalidArgumentException('Transcoded concat requires a list of media inputs.');
        }

        if (count($sources) < 2) {
            throw new InvalidArgumentException('Transcoded concat requires at least two media inputs.');
        }

        $this->export = new PendingExport($sources[0], $disk, $this);

        foreach (array_slice($sources, 1) as $source) {
            $this->export->addInput($source);
        }

        // Adding an input resets the destination to the default disk, so we will set the
        // first input's disk again.
        $this->export->toDisk($disk);
    }

    /**
     * Conform the video of every source to the given dimensions and frame rate.
     */
    public function conformVideo(int $width, int $height, int|float|string $frameRate, string $fit = 'contain'): static
    {
        $this->export->assertOutputMutable();

        if ($width <= 0 || $height <= 0 || $width % 2 || $height % 2) {
            throw new InvalidArgumentException(
                "The conform dimensions [{$width}x{$height}] must be positive and even."
            );
        }

        if (! in_array($fit, ['contain', 'cover', 'stretch'], true)) {
            throw new InvalidArgumentException("The conform fit [{$fit}] must be contain, cover, or stretch.");
        }

        // We will let a throwaway output compile the geometry and frame rate filters, so
        // every source is sized exactly the way an ordinary export would size it.
        $geometry = new ExportOutput('conformance.mp4', $this->export->outputConfiguration()['disk']);
        match ($fit) {
            'stretch' => $geometry->resize($width, $height),
            'cover' => $geometry->cover($width, $height),
            'contain' => $geometry->contain($width, $height),
        };
        $geometry->frameRate($frameRate);
        $this->videoConformance = [
            ...$geometry->outputConfiguration()['videoFilters'], 'setsar=1', 'setpts=PTS-STARTPTS',
        ];

        return $this;
    }

    /**
     * Conform the audio of every source to the given sample rate, channel count and source channel.
     */
    public function conformAudio(int $sampleRate = 48000, int $channels = 2, ?int $channel = null): static
    {
        $this->export->assertOutputMutable();

        if ($sampleRate <= 0) {
            throw new InvalidArgumentException("The conform sample rate [{$sampleRate}] must be positive.");
        }

        if (! in_array($channels, [1, 2], true)) {
            throw new InvalidArgumentException("The conform channel count [{$channels}] must be 1 or 2.");
        }

        if ($channel !== null && $channel < 0) {
            throw new InvalidArgumentException("The conform channel index [{$channel}] must not be negative.");
        }

        $this->audioConformance = ['sampleRate' => $sampleRate, 'channels' => $channels, 'channel' => $channel];

        return $this;
    }

    /**
     * Set the video encoder, bitrate, CRF and preset.
     */
    public function video(
        ?string $codec = null,
        mixed $bitrate = null,
        ?float $crf = null,
        ?string $preset = null,
    ): static {
        $this->export->video($codec, $bitrate, $crf, $preset);

        return $this;
    }

    /**
     * Set allowed encoder options for every output video track.
     */
    public function videoOptions(array $tokens): static
    {
        $this->export->videoOptions($tokens);

        return $this;
    }

    /**
     * Set the audio encoder and bitrate.
     */
    public function audio(?string $codec = null, mixed $bitrate = null): static
    {
        $this->export->audio($codec, $bitrate);

        return $this;
    }

    /**
     * Set allowed encoder options for every output audio track.
     */
    public function audioOptions(array $tokens): static
    {
        $this->export->audioOptions($tokens);

        return $this;
    }

    /**
     * Omit the audio from the output.
     */
    public function withoutAudio(): static
    {
        $this->export->withoutAudio();

        return $this;
    }

    /**
     * Set a container metadata tag on the output.
     */
    public function metadata(string $key, string $value): static
    {
        $this->export->metadata($key, $value);

        return $this;
    }

    /**
     * Set a metadata tag on the given output stream.
     */
    public function streamMetadata(string|int $stream, string $key, string $value): static
    {
        $this->export->streamMetadata($stream, $key, $value);

        return $this;
    }

    /**
     * Set the disposition flags of the given output stream.
     */
    public function disposition(string|int $stream, string|array $flags): static
    {
        $this->export->disposition($stream, $flags);

        return $this;
    }

    /**
     * Encode the output in two passes.
     */
    public function twoPass(): static
    {
        $this->export->twoPass();

        return $this;
    }

    /**
     * Save the joined media to the given path.
     */
    public function save(?string $path = null): ExportResult
    {
        return $this->export->save($path);
    }

    /**
     * Ensure the sources are conformed and map the joined streams to the output.
     *
     * @internal
     */
    public function outputs(array $default, ?string $path): array
    {
        $settings = $this->export->outputConfiguration();

        if ($this->videoConformance === null) {
            throw new InvalidArgumentException('Transcoded concat requires conformVideo().');
        }

        if ($this->audioConformance === null && ! $settings['omitAudio']) {
            throw new InvalidArgumentException('Transcoded concat requires conformAudio() or withoutAudio().');
        }

        if ($settings['omitAudio'] && $this->audioConformance !== null) {
            throw new InvalidArgumentException('Transcoded concat accepts conformAudio() or withoutAudio(), not both.');
        }

        if (($settings['video']['codec'] ?? null) === 'copy') {
            throw new InvalidArgumentException('Transcoded concat must transcode video, not copy it.');
        }

        if (($settings['audio']['codec'] ?? null) === 'copy') {
            throw new InvalidArgumentException('Transcoded concat must transcode audio, not copy it.');
        }

        if (array_any($this->sources, fn (Media $source): bool => $source->inputOptions() !== [])) {
            throw new InvalidArgumentException(
                'Transcoded concat does not support input options that can change source timing.'
            );
        }

        $settings['maps'] = ['[concat_video]'];

        if ($this->audioConformance !== null) {
            $settings['maps'][] = '[concat_audio]';
        }

        $settings['omitAudio'] = false;
        $settings['metadataSource'] = $settings['chapterSource'] = -1;

        return [new ExportOutput($path ?? 'inspection.mp4', $settings['disk'])->configure($settings)];
    }

    /**
     * Build the graph that conforms every source and joins them.
     *
     * @internal
     */
    public function graph(): string
    {
        $branches = [];
        $pads = [];

        foreach ($this->sources as $index => $source) {
            $branches[] = "[{$index}:V:0]".implode(',', $this->videoConformance ?? [])."[concat_v{$index}]";
            $pads[] = "[concat_v{$index}]";

            if ($this->audioConformance !== null) {
                ['sampleRate' => $rate, 'channels' => $channels, 'channel' => $channel] = $this->audioConformance;
                $pan = $channel === null ? '' : "pan=mono|c0=c{$channel},";
                $branches[] = "[{$index}:a:0]{$pan}aresample={$rate}"
                    .":async=1:first_pts=0,aformat=sample_rates={$rate}"
                    .':channel_layouts='.($channels === 1 ? 'mono' : 'stereo')."[concat_a{$index}]";
                $pads[] = "[concat_a{$index}]";
            }
        }

        $branches[] = implode('', $pads).'concat=n='.count($this->sources).':v=1:a='
            .($this->audioConformance === null ? '0[concat_video]' : '1[concat_video][concat_audio]');

        return implode(';', $branches);
    }

    /**
     * Ensure every source can be joined, leaving progress indeterminate.
     *
     * @internal
     */
    public function duration(array $metadata, ?float $default): ?float
    {
        foreach ($metadata as $item) {
            if ($item->video() === null) {
                throw MediaException::probing('Transcoded concat requires a video stream in every source.', 'mapping');
            }

            if ($item->duration() === null || $item->duration() <= 0) {
                throw MediaException::probing('Transcoded concat requires finite sources.', 'metadata');
            }

            PendingExport::assertZeroOrigins($item, 'Transcoded concat');

            if ($this->audioConformance !== null) {
                $audio = $item->audio();
                $channel = $this->audioConformance['channel'];

                if ($audio === null) {
                    throw MediaException::probing('Every concat source requires an audio stream.', 'mapping');
                }

                if ($channel !== null && ($audio->channels() === null || $channel >= $audio->channels())) {
                    throw MediaException::probing(
                        "Every concat source requires the audio channel [{$channel}].",
                        'mapping',
                    );
                }

                if (! is_numeric($audio->get('start_time')) || (float) $audio->get('start_time') < 0) {
                    throw MediaException::probing(
                        'Every concat source requires audio with a nonnegative origin.',
                        'metadata',
                    );
                }
            }
        }

        // Filter frame rounding and audio tails can change the summed duration, so we will
        // leave progress indeterminate.
        return null;
    }
}

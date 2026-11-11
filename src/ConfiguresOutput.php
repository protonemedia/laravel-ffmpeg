<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Concerns\CompilesOutputArguments;
use ProtoneMedia\LaravelFFMpeg\Concerns\ConfiguresEncoders;
use ProtoneMedia\LaravelFFMpeg\Concerns\ConfiguresOutputFilters;
use ProtoneMedia\LaravelFFMpeg\Concerns\ConfiguresOutputMetadata;
use ProtoneMedia\LaravelFFMpeg\Concerns\ConfiguresStreamMaps;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;

/**
 * Configure the encoding, filters, maps, metadata and destination of an output.
 *
 * @internal
 */
trait ConfiguresOutput
{
    use CompilesOutputArguments;
    use ConfiguresEncoders;
    use ConfiguresOutputFilters;
    use ConfiguresOutputMetadata;
    use ConfiguresStreamMaps;

    /**
     * The disk the output is published to.
     */
    protected string|Filesystem $disk;

    /**
     * Indicates if the output disk was set explicitly.
     */
    protected bool $outputDiskExplicit = false;

    /**
     * The encoder settings shared by every output video track.
     */
    protected array $video = [];

    /**
     * The encoder overrides of individual output video tracks, keyed by track.
     */
    protected array $videoStreams = [];

    /**
     * The encoder settings shared by every output audio track.
     */
    protected array $audio = [];

    /**
     * The encoder overrides of individual output audio tracks, keyed by track.
     */
    protected array $audioStreams = [];

    /**
     * The explicit stream maps of the output.
     */
    protected array $maps = [];

    /**
     * The filters of the output video chain.
     */
    protected array $videoFilters = [];

    /**
     * The filters of the output audio chain.
     */
    protected array $audioFilters = [];

    /**
     * The allowed raw FFmpeg options of the output.
     */
    protected array $outputOptions = [];

    /**
     * The options passed to the disk when the output is published.
     */
    protected array $writeOptions = [];

    /**
     * The container metadata tags of the output.
     */
    protected array $metadata = [];

    /**
     * The metadata tags of individual output streams.
     */
    protected array $streamMetadata = [];

    /**
     * The disposition flags of individual output streams.
     */
    protected array $dispositions = [];

    /**
     * Indicates if the output omits the audio.
     */
    protected bool $omitAudio = false;

    /**
     * Indicates if the output omits the video.
     */
    protected bool $omitVideo = false;

    /**
     * The input the container metadata is copied from, or -1 to omit it.
     */
    protected ?int $metadataSource = null;

    /**
     * The input the chapters are copied from, or -1 to omit them.
     */
    protected ?int $chapterSource = null;

    /**
     * The start, duration and seek mode of the output clip.
     */
    protected ?array $clip = null;

    /**
     * Ensure the output settings may still be changed.
     */
    abstract protected function assertOutputMutable(): void;

    /**
     * Add allowed raw FFmpeg options to the output.
     */
    public function outputOptions(array $tokens): static
    {
        $this->assertOutputMutable();

        $this->outputOptions = CommandCompiler::options([...$this->outputOptions, ...$tokens], 'output');

        return $this;
    }

    /**
     * Clip the output to the given start and either a duration or an end.
     */
    public function clip(
        float|string $start,
        float|string|null $duration = null,
        float|string|null $end = null,
        string $seek = 'accurate',
    ): static {
        $this->assertOutputMutable();

        $start = CommandCompiler::seconds($start);
        $duration = $duration === null ? null : CommandCompiler::seconds($duration);
        $end = $end === null ? null : CommandCompiler::seconds($end);

        if (! is_finite($start) || $start < 0) {
            throw new InvalidArgumentException('The clip start must be finite and not negative.');
        }

        if (($duration === null) === ($end === null)) {
            throw new InvalidArgumentException('A clip requires either a duration or an end, not both.');
        }

        if ($duration !== null && (! is_finite($duration) || $duration <= 0)) {
            throw new InvalidArgumentException('The clip duration must be finite and positive.');
        }

        if ($end !== null && (! is_finite($end) || $end <= $start)) {
            throw new InvalidArgumentException('The clip end must be finite and after the start.');
        }

        if (! in_array($seek, ['accurate', 'keyframe'], true)) {
            throw new InvalidArgumentException("The clip seek mode [{$seek}] must be accurate or keyframe.");
        }

        $this->clip = ['start' => $start, 'duration' => $duration ?? $end - $start, 'seek' => $seek];

        return $this;
    }

    /**
     * Set the disk the output is published to.
     */
    public function toDisk(string|Filesystem $disk, array $options = []): static
    {
        $this->assertOutputMutable();

        $this->disk = $disk;
        $this->outputDiskExplicit = true;
        $this->writeOptions = array_replace($this->writeOptions, $options);

        return $this;
    }

    /**
     * Set the visibility of the published output.
     */
    public function visibility(string $visibility): static
    {
        $this->assertOutputMutable();

        $this->writeOptions['visibility'] = $visibility;

        return $this;
    }

    /**
     * Get the input options that seek a keyframe clip.
     */
    public function inputSeekOptions(): array
    {
        return $this->clip !== null && $this->clip['seek'] === 'keyframe'
            ? ['-ss', CommandCompiler::timestamp($this->clip['start']), '-noaccurate_seek']
            : [];
    }

    /**
     * Get the explicit maps, or the default first video and audio maps.
     */
    public function maps(): array
    {
        if ($this->maps !== []) {
            return $this->maps;
        }

        return collect([
            $this->omitVideo ? null : '0:V:0?', $this->omitAudio ? null : '0:a:0?',
        ])->filter()->values()->all();
    }

    /**
     * Determine if the output has explicit maps.
     */
    public function hasMaps(): bool
    {
        return $this->maps !== [];
    }

    /**
     * Get the start, duration and seek mode of the output clip.
     */
    public function clipSettings(): ?array
    {
        return $this->clip;
    }

    /**
     * Get the disk the output is published to.
     */
    public function filesystem(): string|Filesystem
    {
        return $this->disk;
    }

    /**
     * Get the options passed to the disk when the output is published.
     */
    public function storageOptions(): array
    {
        return $this->writeOptions;
    }

    /**
     * Get the validated output settings to transfer to a concrete destination.
     *
     * @internal
     */
    public function outputConfiguration(): array
    {
        return [
            'disk' => $this->disk,
            'outputDiskExplicit' => $this->outputDiskExplicit,
            'video' => $this->video,
            'videoStreams' => $this->videoStreams,
            'audio' => $this->audio,
            'audioStreams' => $this->audioStreams,
            'maps' => $this->maps,
            'videoFilters' => $this->videoFilters,
            'audioFilters' => $this->audioFilters,
            'outputOptions' => $this->outputOptions,
            'writeOptions' => $this->writeOptions,
            'metadata' => $this->metadata,
            'streamMetadata' => $this->streamMetadata,
            'dispositions' => $this->dispositions,
            'omitAudio' => $this->omitAudio,
            'omitVideo' => $this->omitVideo,
            'metadataSource' => $this->metadataSource,
            'chapterSource' => $this->chapterSource,
            'clip' => $this->clip,
        ];
    }

    /**
     * Merge these settings over the given shared defaults when the command compiles.
     *
     * HLS variants and DASH representations expose this, while root and output settings do not.
     *
     * @internal
     */
    protected function inherit(array $shared): array
    {
        foreach ($this->outputConfiguration() as $key => $value) {
            if (in_array($key, ['disk', 'outputDiskExplicit', 'writeOptions'], true)) {
                continue;
            }

            if (in_array($key, ['video', 'audio', 'metadata'], true)) {
                $shared[$key] = array_replace($shared[$key], $value);
            } elseif (in_array($key, ['videoFilters', 'audioFilters', 'streamMetadata', 'dispositions'], true)) {
                $shared[$key] = [...$shared[$key], ...$value];
            } elseif ($key === 'outputOptions') {
                $overrides = collect($value)->nth(2)->all();

                $tokens = $shared[$key];
                $shared[$key] = [];

                for ($i = 0; $i < count($tokens); $i += 2) {
                    if ($tokens[$i] === '-streamid' || ! in_array($tokens[$i], $overrides, true)) {
                        $shared[$key][] = $tokens[$i];
                        $shared[$key][] = $tokens[$i + 1];
                    }
                }

                array_push($shared[$key], ...$value);
            } elseif (! in_array($value, [[], null, false], true)) {
                $shared[$key] = $value;
            }
        }

        return $shared;
    }

    /**
     * Get the input the container metadata is copied from.
     */
    public function metadataSource(): ?int
    {
        return $this->metadataSource;
    }

    /**
     * Get the input the chapters are copied from.
     */
    public function chapterSource(): ?int
    {
        return $this->chapterSource;
    }
}

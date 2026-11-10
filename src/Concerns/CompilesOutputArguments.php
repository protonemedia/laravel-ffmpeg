<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use Illuminate\Support\Str;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;

/**
 * Compile the FFmpeg options of an output.
 *
 * @internal
 */
trait CompilesOutputArguments
{
    /**
     * Compile the FFmpeg options of the output, using the given callback to compile each filter.
     */
    public function outputArguments(callable $compileFilter, array $maps): array
    {
        $this->validateOutputSettings();

        return [
            ...$this->clipArguments(),
            ...$this->mapArguments($maps),
            ...$this->encoderArguments($compileFilter),
            ...$this->trackEncoderArguments($compileFilter),
            ...$this->metadataArguments(),
            ...$this->defaultArguments(),
            ...$this->outputOptions,
        ];
    }

    /**
     * Ensure the track selection, rate control and copied streams are consistent.
     */
    protected function validateOutputSettings(): void
    {
        $this->ensureSingleRateControl($this->video);

        if ($this->maps !== [] && ($this->omitAudio || $this->omitVideo)) {
            throw new InvalidArgumentException('Use maps alone to omit tracks when explicit mapping is enabled.');
        }

        if ($this->maps === [] && $this->omitAudio && $this->omitVideo) {
            throw new InvalidArgumentException('An export must select audio or video.');
        }

        foreach ([[$this->video, $this->videoFilters], [$this->audio, $this->audioFilters]] as [$encoding, $filters]) {
            $this->ensureCopyIsUnmodified($encoding, $filters);
        }

        foreach ($this->videoStreams as $overrides) {
            $encoding = array_replace($this->video, $overrides);
            $this->ensureSingleRateControl($encoding);
            $this->ensureCopyIsUnmodified($encoding, $this->videoFilters);
        }

        foreach ($this->audioStreams as $overrides) {
            $this->ensureCopyIsUnmodified(array_replace($this->audio, $overrides), $this->audioFilters);
        }

        $this->validateCopyOptions();
    }

    /**
     * Compile the output seek and duration of the clip.
     */
    protected function clipArguments(): array
    {
        if ($this->clip === null) {
            return [];
        }

        $options = [];

        if ($this->clip['seek'] === 'accurate') {
            $options[] = '-ss';
            $options[] = CommandCompiler::timestamp($this->clip['start']);
        }

        $options[] = '-t';
        $options[] = CommandCompiler::timestamp($this->clip['duration']);

        return $options;
    }

    /**
     * Compile the stream maps and the metadata and chapter sources.
     */
    protected function mapArguments(array $maps): array
    {
        $options = [];

        foreach ($maps as $map) {
            // FFmpeg 9's metadata selector would read a trailing "?" as part of its value,
            // so we will give the optional marker a colon of its own.
            if (! str_ends_with($map, ':?')) {
                $map = Str::replaceEnd('?', ':?', $map);
            }

            $options[] = '-map';
            $options[] = $map;
        }

        $options[] = '-map_metadata';
        $options[] = (string) ($this->metadataSource ?? 0);
        $options[] = '-map_chapters';
        $options[] = (string) ($this->chapterSource ?? ($this->clip === null ? 0 : -1));

        return $options;
    }

    /**
     * Compile the encoder settings and filters shared by all video and audio tracks.
     */
    protected function encoderArguments(callable $compileFilter): array
    {
        $options = [];

        foreach ([
            ['v', $this->video, $this->videoFilters],
            ['a', $this->audio, $this->audioFilters],
        ] as [$type, $encoding, $filters]) {
            foreach ($encoding as $name => $value) {
                $option = match ($name) {
                    'codec' => 'c', 'bitrate' => 'b', default => $name
                };
                $options[] = "-{$option}:{$type}";
                $options[] = (string) $value;
            }

            if ($filters !== []) {
                $options[] = "-filter:{$type}";
                $options[] = implode(',', array_map($compileFilter, $filters));
            }
        }

        return $options;
    }

    /**
     * Compile the encoder settings and filters of individual output tracks.
     */
    protected function trackEncoderArguments(callable $compileFilter): array
    {
        $options = [];

        foreach ([
            ['v', $this->videoStreams, $this->videoFilters],
            ['a', $this->audioStreams, $this->audioFilters],
        ] as [$type, $streams, $baseFilters]) {
            foreach ($streams as $track => $encoding) {
                foreach ($encoding as $name => $value) {
                    if ($name === 'filters') {
                        $options[] = "-filter:{$type}:{$track}";
                        $options[] = implode(',', array_map($compileFilter, [...$baseFilters, ...$value]));

                        continue;
                    }

                    $option = match ($name) {
                        'codec' => 'c', 'bitrate' => 'b', default => $name
                    };
                    $options[] = "-{$option}:{$type}:{$track}";
                    $options[] = (string) $value;
                }
            }
        }

        return $options;
    }

    /**
     * Compile the container metadata, stream metadata and dispositions.
     */
    protected function metadataArguments(): array
    {
        $options = [];

        foreach ($this->metadata as $key => $value) {
            $options[] = '-metadata';
            $options[] = "{$key}={$value}";
        }

        foreach ($this->streamMetadata as [$stream, $key, $value]) {
            $options[] = "-metadata:s:{$stream}";
            $options[] = "{$key}={$value}";
        }

        foreach ($this->dispositions as [$stream, $flags]) {
            $options[] = "-disposition:{$stream}";
            $options[] = $flags;
        }

        return $options;
    }

    /**
     * Compile the frame rate mode and thread count the output options do not set.
     */
    protected function defaultArguments(): array
    {
        $options = [];

        if (! $this->omitVideo
            && ($this->video['codec'] ?? null) !== 'copy'
            && ! array_any(
                $this->outputOptions,
                fn ($token): int|false => preg_match('/^-(?:r|fps_mode)(?::|$)/', $token),
            )) {
            $options[] = '-fps_mode:v';
            $options[] = 'passthrough';
        }

        // A published config replaces the package's whole ffmpeg array, so the threads key may
        // be missing. We will fall back to the default then, and omit the option only when the
        // key is explicitly false or null.
        $threads = config('laravel-ffmpeg.ffmpeg.threads', 12);

        if ($threads !== false && $threads !== null && ! in_array('-threads', $this->outputOptions, true)) {
            $options[] = '-threads';
            $options[] = (string) $threads;
        }

        return $options;
    }

    /**
     * Ensure the given encoding does not combine a target bitrate with CRF.
     */
    protected function ensureSingleRateControl(array $encoding): void
    {
        if (isset($encoding['bitrate'], $encoding['crf'])) {
            throw new InvalidArgumentException('Choose either a target bitrate or CRF, not both.');
        }
    }

    /**
     * Ensure a copied stream has no filters, encoder settings, or accurate clipping.
     */
    protected function ensureCopyIsUnmodified(array $encoding, array $filters): void
    {
        if (($encoding['codec'] ?? null) !== 'copy') {
            return;
        }

        if ($filters !== []) {
            throw new InvalidArgumentException('Copied streams cannot have filters.');
        }

        if (count($encoding) > 1) {
            throw new InvalidArgumentException('Copied streams cannot have encoder settings.');
        }

        if ($this->clip !== null && $this->clip['seek'] === 'accurate') {
            throw new InvalidArgumentException('Copied streams cannot use accurate clipping.');
        }
    }

    /**
     * Ensure no encoder output option targets a copied stream.
     */
    protected function validateCopyOptions(): void
    {
        $encoderOptions = [
            'preset', 'tune', 'profile', 'level', 'pix_fmt', 'maxrate', 'minrate', 'bufsize',
            'g', 'keyint_min', 'sc_threshold', 'bf', 'refs', 'ar', 'ac', 'q', 'r', 'x264-params', 'x265-params',
            'cpu-used', 'deadline', 'row-mt', 'rc', 'cq', 'rc-lookahead', 'spatial-aq', 'temporal-aq', 'aq-strength',
            'color_primaries', 'color_trc', 'colorspace', 'color_range', 'compression_level', 'sample_fmt',
            'channel_layout',
        ];

        for ($i = 0; $i < count($this->outputOptions); $i += 2) {
            $parts = explode(':', substr($this->outputOptions[$i], 1));

            if (! in_array($parts[0], $encoderOptions, true)) {
                continue;
            }

            foreach ([
                ['v', $this->video, $this->videoStreams],
                ['a', $this->audio, $this->audioStreams],
            ] as [$type, $base, $streams]) {
                if (isset($parts[1]) && $parts[1] !== $type) {
                    continue;
                }

                if (isset($parts[2])) {
                    $encodings = [array_replace($base, $streams[(int) $parts[2]] ?? [])];
                } else {
                    $encodings = [
                        $base, ...array_map(fn ($override): array => array_replace($base, $override), $streams),
                    ];
                }

                if (array_any($encodings, fn ($encoding): bool => ($encoding['codec'] ?? null) === 'copy')) {
                    throw new InvalidArgumentException('Copied streams cannot have encoder output options.');
                }
            }
        }
    }
}

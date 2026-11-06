<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\FilterGraph;
use ProtoneMedia\LaravelFFMpeg\Execution\ResourceFilter;

/** @internal */
trait ConfiguresOutput
{
    private string|Filesystem $disk;

    private bool $outputDiskExplicit = false;

    private array $video = [];

    private array $audio = [];

    private array $maps = [];

    private array $videoFilters = [];

    private array $audioFilters = [];

    private array $outputOptions = [];

    private array $writeOptions = [];

    private array $metadata = [];

    private array $streamMetadata = [];

    private array $dispositions = [];

    private bool $omitAudio = false;

    private bool $omitVideo = false;

    private ?int $metadataSource = null;

    private ?int $chapterSource = null;

    private ?array $clip = null;

    abstract protected function assertOutputMutable(): void;

    public function video(?string $codec = null, mixed $bitrate = null, ?float $crf = null, ?string $preset = null): self
    {
        $this->assertOutputMutable();
        $this->codec($codec);
        $this->bitrate($bitrate);

        if ($crf !== null && (! is_finite($crf) || $crf < 0)) {
            throw new InvalidArgumentException('CRF must be a finite, nonnegative encoder-specific value.');
        }

        if ($preset !== null && ! preg_match('/^[a-zA-Z0-9_-]+$/D', $preset)) {
            throw new InvalidArgumentException('Invalid encoder preset.');
        }

        $this->video = array_replace($this->video, array_filter(compact('codec', 'bitrate', 'crf', 'preset'), fn ($value) => $value !== null));

        return $this;
    }

    public function audio(?string $codec = null, mixed $bitrate = null): self
    {
        $this->assertOutputMutable();
        $this->codec($codec);
        $this->bitrate($bitrate);
        $this->audio = array_replace($this->audio, array_filter(compact('codec', 'bitrate'), fn ($value) => $value !== null));

        return $this;
    }

    private function codec(?string $codec): void
    {
        if ($codec !== null && ! preg_match('/^[a-zA-Z0-9_]+$/D', $codec)) {
            throw new InvalidArgumentException('Invalid FFmpeg encoder name.');
        }
    }

    private function bitrate(mixed $bitrate): void
    {
        if ($bitrate !== null && (! is_string($bitrate) || ! preg_match('/^[0-9]+(?:\.[0-9]+)?[kKmMgG]$/D', $bitrate) || (float) $bitrate <= 0)) {
            throw new InvalidArgumentException('Bitrates require an explicit unit string, such as 2500k.');
        }
    }

    public function map(string $specifier): self
    {
        $this->assertOutputMutable();

        if (! preg_match('/^-?[0-9]+(?::[^\x00\r\n?\[\]]+)?\??$/D', $specifier)
            && ! preg_match('/^\[[^\x00\r\n?\[\]]+\]$/D', $specifier)) {
            throw new InvalidArgumentException('Maps must reference a numeric input index or a graph label in brackets.');
        }

        $this->maps[] = $specifier;

        return $this;
    }

    public function mapVideo(Stream|int|null $stream = null, ?int $input = null, ?int $track = null, bool $optional = false): self
    {
        $this->assertOutputMutable();

        if (is_int($stream)) {
            if ($input !== null) {
                throw new InvalidArgumentException('Specify an input either positionally or by name, not both.');
            }

            $input = $stream;
            $stream = null;
        }

        if (($input !== null && $input < 0) || ($track !== null && $track < 0) || ($stream !== null && $track !== null)) {
            throw new InvalidArgumentException('Stream input and track indexes must be nonnegative.');
        }

        return $stream !== null ? $this->mapProbedStream($stream, 'video', $input, $optional)
            : $this->map(($input ?? 0).':v:'.($track ?? 0).($optional ? '?' : ''));
    }

    public function mapAudio(Stream|int|null $stream = null, ?int $input = null, ?int $track = null, ?string $language = null, bool $optional = false): self
    {
        $this->assertOutputMutable();

        if (is_int($stream)) {
            if ($input !== null) {
                throw new InvalidArgumentException('Specify an input either positionally or by name, not both.');
            }

            $input = $stream;
            $stream = null;
        }

        if (($input !== null && $input < 0) || ($track !== null && $track < 0) || ($track !== null && $language !== null)
            || ($stream !== null && ($track !== null || $language !== null))
            || ($language !== null && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $language))) {
            throw new InvalidArgumentException('Choose a nonnegative audio track or a language tag, not both.');
        }

        $selector = $language === null ? (string) ($track ?? 0) : 'm:language:'.$language;

        return $stream !== null ? $this->mapProbedStream($stream, 'audio', $input, $optional)
            : $this->map(($input ?? 0).':a:'.$selector.($optional ? '?' : ''));
    }

    private function mapProbedStream(Stream $stream, string $type, ?int $input, bool $optional): self
    {
        if ($stream->get('codec_type') !== $type || $stream->index() === null || $stream->sourceIdentity() === null) {
            throw new InvalidArgumentException('Mapping requires a probed '.$type.' stream.');
        }

        $this->maps[] = compact('stream', 'input', 'optional');

        return $this;
    }

    public function withoutAudio(): self
    {
        $this->assertOutputMutable();
        $this->omitAudio = true;

        return $this;
    }

    public function withoutVideo(): self
    {
        $this->assertOutputMutable();
        $this->omitVideo = true;

        return $this;
    }

    public function videoFilter(string $expression): self
    {
        $this->assertOutputMutable();
        FilterGraph::validateExpression($expression);
        $this->videoFilters[] = $expression;

        return $this;
    }

    /** One dimension preserves display aspect ratio; two dimensions stretch. */
    public function resize(?int $width = null, ?int $height = null): self
    {
        $this->assertOutputMutable();

        if (($width === null && $height === null) || ($width !== null && $width <= 0) || ($height !== null && $height <= 0)) {
            throw new InvalidArgumentException('Resize requires at least one positive dimension.');
        }

        $this->videoFilters[] = 'scale=w='.($width ?? -2).':h='.($height ?? -2).':reset_sar=1';

        return $this;
    }

    /** Fit display geometry into an exact square-pixel canvas. */
    public function fit(int $width, int $height, string $mode = 'contain'): self
    {
        $this->assertOutputMutable();

        if ($width <= 0 || $height <= 0 || $width % 2 !== 0 || $height % 2 !== 0 || ! in_array($mode, ['contain', 'cover'], true)) {
            throw new InvalidArgumentException('Fit requires positive even dimensions and contain or cover mode.');
        }

        $aspect = $mode === 'contain' ? 'decrease' : 'increase';
        $canvas = $mode === 'contain'
            ? 'pad=w='.$width.':h='.$height.':x=(ow-iw)/2:y=(oh-ih)/2:color=black'
            : 'crop=w='.$width.':h='.$height.':x=(iw-ow)/2:y=(ih-oh)/2';
        $this->videoFilters[] = 'scale=w='.$width.':h='.$height.':force_original_aspect_ratio='.$aspect.':force_divisible_by=2:reset_sar=1,'.$canvas;

        return $this;
    }

    public function drawText(string $text, Media $font, int $size = 24, int $x = 0, int $y = 0, string $color = 'white'): self
    {
        $this->assertOutputMutable();
        $this->videoFilters[] = ResourceFilter::drawText($text, $font, $size, $x, $y, $color);

        return $this;
    }

    public function burnSubtitles(Media $subtitles): self
    {
        $this->assertOutputMutable();
        $this->videoFilters[] = ResourceFilter::burnSubtitles($subtitles);

        return $this;
    }

    /** @internal */
    public function resourceFilters(): array
    {
        return array_values(array_filter($this->videoFilters, fn ($filter) => $filter instanceof ResourceFilter));
    }

    public function audioFilter(string $expression): self
    {
        $this->assertOutputMutable();
        FilterGraph::validateExpression($expression);
        $this->audioFilters[] = $expression;

        return $this;
    }

    public function outputOptions(array $tokens): self
    {
        $this->assertOutputMutable();
        $this->outputOptions = CommandCompiler::options([...$this->outputOptions, ...$tokens], 'output');

        return $this;
    }

    public function withoutMetadata(): self
    {
        $this->assertOutputMutable();
        $this->metadataSource = -1;

        return $this;
    }

    public function metadataFrom(?int $input): self
    {
        $this->assertOutputMutable();

        if ($input !== null && $input < 0) {
            throw new InvalidArgumentException('The metadata source must be a nonnegative input index or null to omit metadata.');
        }

        $this->metadataSource = $input ?? -1;

        return $this;
    }

    public function metadata(string $key, string $value): self
    {
        $this->assertOutputMutable();
        $this->metadataTag($key, $value);

        $this->metadata[$key] = $value;

        return $this;
    }

    public function streamMetadata(string|int $stream, string $key, string $value): self
    {
        $this->assertOutputMutable();
        $this->outputStream($stream);
        $this->metadataTag($key, $value);

        $this->streamMetadata[] = [$stream, $key, $value];

        return $this;
    }

    public function disposition(string|int $stream, string|array $flags): self
    {
        $this->assertOutputMutable();
        $this->outputStream($stream);

        if (is_string($flags)) {
            $flags = $flags === '0' ? [] : explode('+', $flags);
        }

        if (! array_is_list($flags) || array_any($flags, fn ($flag) => ! is_string($flag) || ! in_array($flag, [
            'default', 'dub', 'original', 'comment', 'lyrics', 'karaoke', 'forced',
            'hearing_impaired', 'visual_impaired', 'clean_effects', 'attached_pic',
            'timed_thumbnails', 'non_diegetic', 'captions', 'descriptions', 'metadata',
            'dependent', 'still_image', 'multilayer',
        ], true))) {
            throw new InvalidArgumentException('Dispositions require supported flag names, or an empty list to clear them.');
        }

        $this->dispositions[] = [$stream, $flags === [] ? '0' : implode('+', array_unique($flags))];

        return $this;
    }

    private function outputStream(string|int $stream): void
    {
        if (! preg_match('/^(?:[0-9]+|[vasdt](?::[0-9]+)?)$/D', (string) $stream)) {
            throw new InvalidArgumentException('Select an output stream by absolute index, type, or type and track index.');
        }
    }

    private function metadataTag(string $key, string $value): void
    {
        if (! preg_match('/^[a-zA-Z0-9_.:-]+$/D', $key) || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Invalid metadata tag.');
        }
    }

    public function chaptersFrom(?int $input): self
    {
        $this->assertOutputMutable();

        if ($input !== null && $input < 0) {
            throw new InvalidArgumentException('The chapter source must be a nonnegative input index or null to omit chapters.');
        }

        $this->chapterSource = $input ?? -1;

        return $this;
    }

    public function clip(float $start, ?float $duration = null, ?float $end = null, string $seek = 'accurate'): self
    {
        $this->assertOutputMutable();

        if (! is_finite($start) || $start < 0 || ($duration === null) === ($end === null)
            || ($duration !== null && (! is_finite($duration) || $duration <= 0))
            || ($end !== null && (! is_finite($end) || $end <= $start))
            || ! in_array($seek, ['accurate', 'keyframe'], true)) {
            throw new InvalidArgumentException('A clip needs a nonnegative start and either a positive duration or a later end.');
        }

        $this->clip = ['start' => $start, 'duration' => $duration ?? $end - $start, 'seek' => $seek];

        return $this;
    }

    public function toDisk(string|Filesystem $disk, array $options = []): self
    {
        $this->assertOutputMutable();
        $this->disk = $disk;
        $this->outputDiskExplicit = true;
        $this->writeOptions = $options;

        return $this;
    }

    public function visibility(string $visibility): self
    {
        $this->assertOutputMutable();
        $this->writeOptions['visibility'] = $visibility;

        return $this;
    }

    public function outputArguments(callable $compileFilter, array $maps): array
    {
        if (isset($this->video['bitrate'], $this->video['crf'])) {
            throw new InvalidArgumentException('Choose either a target bitrate or CRF.');
        }

        if ($this->maps !== [] && ($this->omitAudio || $this->omitVideo)) {
            throw new InvalidArgumentException('Use maps alone to omit tracks when explicit mapping is enabled.');
        }

        if ($this->maps === [] && $this->omitAudio && $this->omitVideo) {
            throw new InvalidArgumentException('An export must select audio or video.');
        }

        foreach ([[$this->video, $this->videoFilters], [$this->audio, $this->audioFilters]] as [$encoding, $filters]) {
            if (($encoding['codec'] ?? null) === 'copy' && ($filters !== [] || count($encoding) > 1
                || ($this->clip !== null && $this->clip['seek'] === 'accurate'))) {
                throw new InvalidArgumentException('Copied streams cannot have filters, encoder settings, or accurate clipping.');
            }
        }

        $options = [];

        if ($this->clip !== null) {
            if ($this->clip['seek'] === 'accurate') {
                array_push($options, '-ss', (string) $this->clip['start']);
            }

            array_push($options, '-t', (string) $this->clip['duration']);
        }

        foreach ($maps as $map) {
            // FFmpeg 9's metadata selector otherwise consumes '?' as part of its value.
            if (str_ends_with($map, '?') && ! str_ends_with($map, ':?')) {
                $map = substr($map, 0, -1).':?';
            }

            array_push($options, '-map', $map);
        }

        array_push($options, '-map_metadata', (string) ($this->metadataSource ?? 0), '-map_chapters', (string) ($this->chapterSource ?? ($this->clip === null ? 0 : -1)));

        foreach ([['v', $this->video, $this->videoFilters], ['a', $this->audio, $this->audioFilters]] as [$type, $encoding, $filters]) {
            foreach ($encoding as $name => $value) {
                $option = match ($name) { 'codec' => 'c', 'bitrate' => 'b', default => $name };
                array_push($options, '-'.$option.':'.$type, (string) $value);
            }

            if ($filters !== []) {
                array_push($options, '-filter:'.$type, implode(',', array_map($compileFilter, $filters)));
            }
        }

        foreach ($this->metadata as $key => $value) {
            array_push($options, '-metadata', $key.'='.$value);
        }

        foreach ($this->streamMetadata as [$stream, $key, $value]) {
            array_push($options, '-metadata:s:'.$stream, $key.'='.$value);
        }

        foreach ($this->dispositions as [$stream, $flags]) {
            array_push($options, '-disposition:'.$stream, $flags);
        }

        if (! $this->omitVideo && ($this->video['codec'] ?? null) !== 'copy'
            && ! array_any($this->outputOptions, fn ($token) => preg_match('/^-(?:r|fps_mode)(?::|$)/', $token))) {
            array_push($options, '-fps_mode:v', 'passthrough');
        }

        $threads = config('laravel-ffmpeg.ffmpeg.threads');

        if ($threads !== false && $threads !== null && ! in_array('-threads', $this->outputOptions, true)) {
            array_push($options, '-threads', (string) $threads);
        }

        return [...$options, ...$this->outputOptions];
    }

    public function inputSeekOptions(): array
    {
        return $this->clip !== null && $this->clip['seek'] === 'keyframe'
            ? ['-ss', (string) $this->clip['start'], '-noaccurate_seek']
            : [];
    }

    public function maps(): array
    {
        return $this->maps !== [] ? $this->maps : array_values(array_filter([
            $this->omitVideo ? null : '0:V:0?', $this->omitAudio ? null : '0:a:0?',
        ]));
    }

    public function hasMaps(): bool
    {
        return $this->maps !== [];
    }

    public function clipSettings(): ?array
    {
        return $this->clip;
    }

    public function filesystem(): string|Filesystem
    {
        return $this->disk;
    }

    public function storageOptions(): array
    {
        return $this->writeOptions;
    }

    /** @internal Transfer validated output settings to a concrete destination. */
    public function outputConfiguration(): array
    {
        return [
            'disk' => $this->disk,
            'outputDiskExplicit' => $this->outputDiskExplicit,
            'video' => $this->video,
            'audio' => $this->audio,
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

    public function metadataSource(): ?int
    {
        return $this->metadataSource;
    }

    public function chapterSource(): ?int
    {
        return $this->chapterSource;
    }
}

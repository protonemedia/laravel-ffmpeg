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

    private array $videoStreams = [];

    private array $audio = [];

    private array $audioStreams = [];

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

    /** Override encoding in mapped output video order, not input stream order. */
    public function videoStream(int $track, callable $configure): self
    {
        $this->assertOutputMutable();
        if ($track < 0 || isset($this->videoStreams[$track])) {
            throw new InvalidArgumentException('Video track indexes must be nonnegative and configured once.');
        }

        $settings = new VideoStreamSettings;
        $configure($settings);
        $this->videoStreams[$track] = $settings->settings();

        return $this;
    }

    /** Override encoding in mapped output audio order, not input stream order. */
    public function audioStream(int $track, callable $configure): self
    {
        $this->assertOutputMutable();
        if ($track < 0 || isset($this->audioStreams[$track])) {
            throw new InvalidArgumentException('Audio track indexes must be nonnegative and configured once.');
        }

        $settings = new AudioStreamSettings;
        $configure($settings);
        $this->audioStreams[$track] = $settings->settings();

        return $this;
    }

    public function videoOptions(array $tokens): self
    {
        $this->assertOutputMutable();
        $this->video = array_replace($this->video, (new VideoStreamSettings)->options($tokens)->settings());

        return $this;
    }

    public function audioOptions(array $tokens): self
    {
        $this->assertOutputMutable();
        $this->audio = array_replace($this->audio, (new AudioStreamSettings)->options($tokens)->settings());

        return $this;
    }

    /** Signal color characteristics; this does not transform pixel values. */
    public function color(string $primaries, string $transfer, string $space, string $range = 'tv'): self
    {
        $this->videoOptions(['-color_primaries', $primaries, '-color_trc', $transfer, '-colorspace', $space, '-color_range', $range]);

        return $this->videoFilter(VideoStreamSettings::colorFilter($primaries, $transfer, $space, $range));
    }

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

        $this->video = array_replace($this->video, array_filter(['codec' => $codec, 'bitrate' => $bitrate, 'crf' => $crf, 'preset' => $preset], fn ($value): bool => $value !== null));

        return $this;
    }

    public function audio(?string $codec = null, mixed $bitrate = null): self
    {
        $this->assertOutputMutable();
        $this->codec($codec);
        $this->bitrate($bitrate);
        $this->audio = array_replace($this->audio, array_filter(['codec' => $codec, 'bitrate' => $bitrate], fn ($value): bool => $value !== null));

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
        if ($bitrate !== null && (! is_int($bitrate) || $bitrate <= 0)
            && (! is_string($bitrate) || ! preg_match('/^[0-9]+(?:\.[0-9]+)?[kKmMgG]$/D', $bitrate) || (float) $bitrate <= 0)) {
            throw new InvalidArgumentException('Bitrates require a positive integer in bits per second or an explicit unit string, such as 2500k.');
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

        if (($input !== null && $input < 0) || ($track !== null && $track < 0) || ($stream instanceof Stream && $track !== null)) {
            throw new InvalidArgumentException('Stream input and track indexes must be nonnegative.');
        }

        return $stream instanceof Stream ? $this->mapProbedStream($stream, 'video', $input, $optional)
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
            || ($stream instanceof Stream && ($track !== null || $language !== null))
            || ($language !== null && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $language))) {
            throw new InvalidArgumentException('Choose a nonnegative audio track or a language tag, not both.');
        }

        $selector = $language === null ? (string) ($track ?? 0) : 'm:language:'.$language;

        return $stream instanceof Stream ? $this->mapProbedStream($stream, 'audio', $input, $optional)
            : $this->map(($input ?? 0).':a:'.$selector.($optional ? '?' : ''));
    }

    private function mapProbedStream(Stream $stream, string $type, ?int $input, bool $optional): self
    {
        if ($stream->get('codec_type') !== $type || $stream->index() === null || $stream->sourceIdentity() === null) {
            throw new InvalidArgumentException('Mapping requires a probed '.$type.' stream.');
        }

        $this->maps[] = ['stream' => $stream, 'input' => $input, 'optional' => $optional];

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

    /** Clockwise quarter turns, applied in filter order after FFmpeg's input autorotation. */
    public function rotate(int $degrees): self
    {
        return $this->videoFilter(match ($degrees) {
            90, -270 => 'transpose=clock',
            180, -180 => 'hflip,vflip',
            270, -90 => 'transpose=cclock',
            default => throw new InvalidArgumentException('Rotation requires a clockwise quarter turn: 90, 180, or 270 degrees, or its negative equivalent.'),
        });
    }

    public function crop(int $width, int $height, ?int $x = null, ?int $y = null): self
    {
        if ($width <= 0 || $height <= 0 || $width % 2 || $height % 2 || ($x !== null && $x < 0) || ($y !== null && $y < 0)) {
            throw new InvalidArgumentException('Crop requires positive even dimensions and nonnegative offsets.');
        }

        return $this->videoFilter('crop=w='.$width.':h='.$height.':x='.($x ?? '(iw-ow)/2').':y='.($y ?? '(ih-oh)/2').':exact=1');
    }

    public function pad(int $width, int $height, ?int $x = null, ?int $y = null, string $color = 'black'): self
    {
        if ($width <= 0 || $height <= 0 || $width % 2 || $height % 2 || ($x !== null && $x < 0) || ($y !== null && $y < 0)
            || ! preg_match('/\A(?:[a-zA-Z]+|(?:#|0x)[a-fA-F0-9]{6}(?:[a-fA-F0-9]{2})?)\z/', $color)) {
            throw new InvalidArgumentException('Pad requires positive even dimensions, nonnegative offsets, and a color name or RGB/RGBA hexadecimal color.');
        }

        return $this->videoFilter('pad=w='.$width.':h='.$height.':x='.($x ?? '(ow-iw)/2').':y='.($y ?? '(oh-ih)/2').':color='.$color);
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

    public function lut3d(Media $look): self
    {
        $this->assertOutputMutable();
        $this->videoFilters[] = ResourceFilter::lut3d($look);

        return $this;
    }

    /** @internal */
    public function resourceFilters(): array
    {
        return array_values(array_filter($this->videoFilters, fn ($filter): bool => $filter instanceof ResourceFilter));
    }

    public function audioFilter(string $expression): self
    {
        $this->assertOutputMutable();
        FilterGraph::validateExpression($expression);
        $this->audioFilters[] = $expression;

        return $this;
    }

    /** Resample video frames in filter order, without changing playback speed. */
    public function frameRate(int|float|string $rate): self
    {
        if (is_float($rate) && ! is_finite($rate)) {
            throw new InvalidArgumentException('Frame rate requires a finite positive number or positive rational.');
        }

        $parts = explode('/', (string) $rate);

        if (count($parts) > 2 || array_any($parts, fn ($part): bool => ! preg_match('/\A(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?\z/', $part)
            || ! is_finite((float) $part) || (float) $part <= 0)
            || ! is_finite((float) $parts[0] / (float) ($parts[1] ?? 1))
            || (float) $parts[0] / (float) ($parts[1] ?? 1) <= 0) {
            throw new InvalidArgumentException('Frame rate requires a finite positive number or positive rational.');
        }

        return $this->videoFilter('fps=fps='.$rate);
    }

    public function flipHorizontal(): self
    {
        return $this->videoFilter('hflip');
    }

    public function flipVertical(): self
    {
        return $this->videoFilter('vflip');
    }

    public function grayscale(): self
    {
        return $this->videoFilter('hue=s=0');
    }

    /**
     * Deinterlace before calling geometry helpers. Frame mode preserves frame count,
     * unlike FFmpeg's default field mode, which outputs a frame for each field.
     */
    public function deinterlace(string $mode = 'frame', string $parity = 'auto', bool $onlyInterlaced = true): self
    {
        if (! in_array($mode, ['frame', 'field'], true) || ! in_array($parity, ['auto', 'tff', 'bff'], true)) {
            throw new InvalidArgumentException('Deinterlace requires frame or field mode and auto, tff, or bff parity.');
        }

        return $this->videoFilter('bwdif=mode=send_'.$mode.':parity='.$parity.':deint='.($onlyInterlaced ? 'interlaced' : 'all'));
    }

    /** Linear amplitude multiplier; zero mutes the audio. */
    public function volume(float $multiplier): self
    {
        if (! is_finite($multiplier) || $multiplier < 0) {
            throw new InvalidArgumentException('Volume requires a finite nonnegative multiplier.');
        }

        return $this->audioFilter('volume='.$multiplier);
    }

    /** Insert real silence on all channels, extending audio rather than shifting timestamps. */
    public function delayAudio(float $seconds): self
    {
        if (! is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('Audio delay requires finite nonnegative seconds.');
        }

        // FFmpeg 9 treats fractional values with an 's' suffix as milliseconds.
        $delay = floor($seconds) === $seconds
            ? sprintf('%.0F', $seconds).'s'
            : rtrim(rtrim(sprintf('%.17F', $seconds * 1000), '0'), '.');

        return $this->audioFilter('adelay=delays='.$delay.':all=1');
    }

    /** Fade both video and audio by default, starting at zero seconds. */
    public function fadeIn(float $duration, float $start = 0, bool $video = true, bool $audio = true): self
    {
        return $this->fade('in', $duration, $start, $video, $audio);
    }

    /** Fade both video and audio by default; start is an explicit time in seconds. */
    public function fadeOut(float $duration, float $start, bool $video = true, bool $audio = true): self
    {
        return $this->fade('out', $duration, $start, $video, $audio);
    }

    private function fade(string $type, float $duration, float $start, bool $video, bool $audio): self
    {
        $this->assertOutputMutable();

        if (! is_finite($duration) || $duration <= 0 || ! is_finite($start) || $start < 0 || (! $video && ! $audio)) {
            throw new InvalidArgumentException('Fades require a finite positive duration, a finite nonnegative start, and video or audio.');
        }

        $timing = 't='.$type.':st='.rtrim(rtrim(sprintf('%.17F', $start), '0'), '.').':d='.rtrim(rtrim(sprintf('%.17F', $duration), '0'), '.');

        if ($video) {
            $this->videoFilter('fade='.$timing);
        }

        if ($audio) {
            $this->audioFilter('afade='.$timing);
        }

        return $this;
    }

    public function outputOptions(array $tokens): self
    {
        $this->assertOutputMutable();
        $this->outputOptions = CommandCompiler::options([...$this->outputOptions, ...$tokens], 'output');

        return $this;
    }

    public function withoutMetadata(bool $chapters = false): self
    {
        $this->assertOutputMutable();
        $this->metadataSource = -1;

        if ($chapters) {
            $this->chapterSource = -1;
        }

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

        if (! array_is_list($flags) || array_any($flags, fn ($flag): bool => ! is_string($flag) || ! in_array($flag, [
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

    public function clip(float|string $start, float|string|null $duration = null, float|string|null $end = null, string $seek = 'accurate'): self
    {
        $this->assertOutputMutable();
        $start = CommandCompiler::seconds($start);
        $duration = $duration === null ? null : CommandCompiler::seconds($duration);
        $end = $end === null ? null : CommandCompiler::seconds($end);

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
        $this->writeOptions = array_replace($this->writeOptions, $options);

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

        foreach ($this->videoStreams as $track => $overrides) {
            $encoding = array_replace($this->video, $overrides);
            if (isset($encoding['bitrate'], $encoding['crf'])
                || (($encoding['codec'] ?? null) === 'copy' && (count($encoding) > 1 || $this->videoFilters !== []
                    || ($this->clip !== null && $this->clip['seek'] === 'accurate')))) {
                throw new InvalidArgumentException('Video track settings conflict with rate control, copying, or filters.');
            }
        }

        foreach ($this->audioStreams as $overrides) {
            $encoding = array_replace($this->audio, $overrides);
            if (($encoding['codec'] ?? null) === 'copy' && (count($encoding) > 1 || $this->audioFilters !== []
                || ($this->clip !== null && $this->clip['seek'] === 'accurate'))) {
                throw new InvalidArgumentException('Copied audio tracks cannot have filters or encoder settings.');
            }
        }

        $this->validateCopyOptions();

        if ($this->clip !== null) {
            if ($this->clip['seek'] === 'accurate') {
                $options[] = '-ss';
                $options[] = CommandCompiler::timestamp($this->clip['start']);
            }

            $options[] = '-t';
            $options[] = CommandCompiler::timestamp($this->clip['duration']);
        }

        foreach ($maps as $map) {
            // FFmpeg 9's metadata selector otherwise consumes '?' as part of its value.
            if (str_ends_with($map, '?') && ! str_ends_with($map, ':?')) {
                $map = substr($map, 0, -1).':?';
            }

            $options[] = '-map';
            $options[] = $map;
        }

        $options[] = '-map_metadata';
        $options[] = (string) ($this->metadataSource ?? 0);
        $options[] = '-map_chapters';
        $options[] = (string) ($this->chapterSource ?? ($this->clip === null ? 0 : -1));

        foreach ([['v', $this->video, $this->videoFilters], ['a', $this->audio, $this->audioFilters]] as [$type, $encoding, $filters]) {
            foreach ($encoding as $name => $value) {
                $option = match ($name) {
                    'codec' => 'c', 'bitrate' => 'b', default => $name
                };
                $options[] = '-'.$option.':'.$type;
                $options[] = (string) $value;
            }

            if ($filters !== []) {
                $options[] = '-filter:'.$type;
                $options[] = implode(',', array_map($compileFilter, $filters));
            }
        }

        foreach ([['v', $this->videoStreams, $this->videoFilters], ['a', $this->audioStreams, $this->audioFilters]] as [$type, $streams, $baseFilters]) {
            foreach ($streams as $track => $encoding) {
                foreach ($encoding as $name => $value) {
                    if ($name === 'filters') {
                        $options[] = '-filter:'.$type.':'.$track;
                        $options[] = implode(',', array_map($compileFilter, [...$baseFilters, ...$value]));

                        continue;
                    }

                    $option = match ($name) {
                        'codec' => 'c', 'bitrate' => 'b', default => $name
                    };
                    $options[] = '-'.$option.':'.$type.':'.$track;
                    $options[] = (string) $value;
                }
            }
        }

        foreach ($this->metadata as $key => $value) {
            $options[] = '-metadata';
            $options[] = $key.'='.$value;
        }

        foreach ($this->streamMetadata as [$stream, $key, $value]) {
            $options[] = '-metadata:s:'.$stream;
            $options[] = $key.'='.$value;
        }

        foreach ($this->dispositions as [$stream, $flags]) {
            $options[] = '-disposition:'.$stream;
            $options[] = $flags;
        }

        if (! $this->omitVideo && ($this->video['codec'] ?? null) !== 'copy'
            && ! array_any($this->outputOptions, fn ($token): int|false => preg_match('/^-(?:r|fps_mode)(?::|$)/', $token))) {
            $options[] = '-fps_mode:v';
            $options[] = 'passthrough';
        }

        // A published ffmpeg array replaces the package one; omit threads only when it is false or null.
        $threads = config('laravel-ffmpeg.ffmpeg.threads', 12);

        if ($threads !== false && $threads !== null && ! in_array('-threads', $this->outputOptions, true)) {
            $options[] = '-threads';
            $options[] = (string) $threads;
        }

        return [...$options, ...$this->outputOptions];
    }

    private function validateCopyOptions(): void
    {
        $encoderOptions = ['preset', 'tune', 'profile', 'level', 'pix_fmt', 'maxrate', 'minrate', 'bufsize',
            'g', 'keyint_min', 'sc_threshold', 'bf', 'refs', 'ar', 'ac', 'q', 'r', 'x264-params', 'x265-params',
            'cpu-used', 'deadline', 'row-mt', 'rc', 'cq', 'rc-lookahead', 'spatial-aq', 'temporal-aq', 'aq-strength',
            'color_primaries', 'color_trc', 'colorspace', 'color_range', 'compression_level', 'sample_fmt', 'channel_layout'];
        for ($i = 0; $i < count($this->outputOptions); $i += 2) {
            $parts = explode(':', substr($this->outputOptions[$i], 1));
            if (! in_array($parts[0], $encoderOptions, true)) {
                continue;
            }

            foreach ([['v', $this->video, $this->videoStreams], ['a', $this->audio, $this->audioStreams]] as [$type, $base, $streams]) {
                if (isset($parts[1]) && $parts[1] !== $type) {
                    continue;
                }

                $encodings = isset($parts[2]) ? [array_replace($base, $streams[(int) $parts[2]] ?? [])]
                    : [$base, ...array_map(fn ($override): array => array_replace($base, $override), $streams)];
                if (array_any($encodings, fn ($encoding): bool => ($encoding['codec'] ?? null) === 'copy')) {
                    throw new InvalidArgumentException('Copied streams cannot have encoder output options.');
                }
            }
        }
    }

    public function inputSeekOptions(): array
    {
        return $this->clip !== null && $this->clip['seek'] === 'keyframe'
            ? ['-ss', CommandCompiler::timestamp($this->clip['start']), '-noaccurate_seek']
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
     * @internal Resolve shared defaults at compilation, not declaration time.
     * HLS variants and DASH representations expose this; root and output settings do not.
     */
    private function inherit(array $shared): array
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
                $overrides = [];
                for ($i = 0; $i < count($value); $i += 2) {
                    $overrides[] = $value[$i];
                }

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

    public function metadataSource(): ?int
    {
        return $this->metadataSource;
    }

    public function chapterSource(): ?int
    {
        return $this->chapterSource;
    }
}

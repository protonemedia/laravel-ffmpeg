<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\FilterGraph;
use ProtoneMedia\LaravelFFMpeg\Execution\ResourceFilter;

/**
 * Configure the encoding, filters, maps, metadata and destination of an output.
 *
 * @internal
 */
trait ConfiguresOutput
{
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
     * Override the encoding of one output video track.
     *
     * Tracks count in mapped output order, not input stream order.
     */
    public function videoStream(int $track, callable $configure): self
    {
        $this->assertOutputMutable();
        $this->ensureIndexIsNotNegative('video track', $track);

        if (isset($this->videoStreams[$track])) {
            throw new InvalidArgumentException("The video track [{$track}] is already configured.");
        }

        $settings = new VideoStreamSettings;
        $configure($settings);
        $this->videoStreams[$track] = $settings->settings();

        return $this;
    }

    /**
     * Override the encoding of one output audio track.
     *
     * Tracks count in mapped output order, not input stream order.
     */
    public function audioStream(int $track, callable $configure): self
    {
        $this->assertOutputMutable();
        $this->ensureIndexIsNotNegative('audio track', $track);

        if (isset($this->audioStreams[$track])) {
            throw new InvalidArgumentException("The audio track [{$track}] is already configured.");
        }

        $settings = new AudioStreamSettings;
        $configure($settings);
        $this->audioStreams[$track] = $settings->settings();

        return $this;
    }

    /**
     * Set allowed encoder options for every output video track.
     */
    public function videoOptions(array $tokens): self
    {
        $this->assertOutputMutable();
        $this->video = array_replace($this->video, (new VideoStreamSettings)->options($tokens)->settings());

        return $this;
    }

    /**
     * Set allowed encoder options for every output audio track.
     */
    public function audioOptions(array $tokens): self
    {
        $this->assertOutputMutable();
        $this->audio = array_replace($this->audio, (new AudioStreamSettings)->options($tokens)->settings());

        return $this;
    }

    /**
     * Signal the color characteristics of the output video.
     *
     * This does not transform pixel values.
     */
    public function color(string $primaries, string $transfer, string $space, string $range = 'tv'): self
    {
        $this->videoOptions([
            '-color_primaries', $primaries,
            '-color_trc', $transfer,
            '-colorspace', $space,
            '-color_range', $range,
        ]);

        return $this->videoFilter(VideoStreamSettings::colorFilter($primaries, $transfer, $space, $range));
    }

    /**
     * Set the video encoder, bitrate, CRF and preset.
     */
    public function video(
        ?string $codec = null,
        mixed $bitrate = null,
        ?float $crf = null,
        ?string $preset = null,
    ): self {
        $this->assertOutputMutable();
        $this->codec($codec);
        $this->bitrate($bitrate);

        if ($crf !== null && (! is_finite($crf) || $crf < 0)) {
            throw new InvalidArgumentException('CRF must be a finite, nonnegative encoder-specific value.');
        }

        if ($preset !== null && ! preg_match('/^[a-zA-Z0-9_-]+$/D', $preset)) {
            throw new InvalidArgumentException('Invalid encoder preset.');
        }

        $this->video = array_replace($this->video, collect([
            'codec' => $codec, 'bitrate' => $bitrate, 'crf' => $crf, 'preset' => $preset,
        ])->whereNotNull()->all());

        return $this;
    }

    /**
     * Set the audio encoder and bitrate.
     */
    public function audio(?string $codec = null, mixed $bitrate = null): self
    {
        $this->assertOutputMutable();
        $this->codec($codec);
        $this->bitrate($bitrate);
        $this->audio = array_replace($this->audio, collect([
            'codec' => $codec, 'bitrate' => $bitrate,
        ])->whereNotNull()->all());

        return $this;
    }

    /**
     * Ensure the given encoder name is valid.
     */
    protected function codec(?string $codec): void
    {
        if ($codec !== null && ! preg_match('/^[a-zA-Z0-9_]+$/D', $codec)) {
            throw new InvalidArgumentException('Invalid FFmpeg encoder name.');
        }
    }

    /**
     * Ensure the given bitrate is positive and has a valid unit.
     */
    protected function bitrate(mixed $bitrate): void
    {
        if ($bitrate !== null && (! is_int($bitrate) || $bitrate <= 0)
            && (! is_string($bitrate)
                || ! preg_match('/^[0-9]+(?:\.[0-9]+)?[kKmMgG]$/D', $bitrate)
                || (float) $bitrate <= 0)) {
            throw new InvalidArgumentException(
                'Bitrates require a positive integer in bits per second or an explicit unit string, such as 2500k.',
            );
        }
    }

    /**
     * Map the given input stream specifier or graph label to the output.
     */
    public function map(string $specifier): self
    {
        $this->assertOutputMutable();

        if (! preg_match('/^-?[0-9]+(?::[^\x00\r\n?\[\]]+)?\??$/D', $specifier)
            && ! preg_match('/^\[[^\x00\r\n?\[\]]+\]$/D', $specifier)) {
            throw new InvalidArgumentException(
                'Maps must reference a numeric input index or a graph label in brackets.',
            );
        }

        $this->maps[] = $specifier;

        return $this;
    }

    /**
     * Map a video stream from the given input to the output.
     */
    public function mapVideo(
        Stream|int|null $stream = null,
        ?int $input = null,
        ?int $track = null,
        bool $optional = false,
    ): self {
        $this->assertOutputMutable();

        if (is_int($stream)) {
            if ($input !== null) {
                throw new InvalidArgumentException('Specify an input either positionally or by name, not both.');
            }

            $input = $stream;
            $stream = null;
        }

        $this->ensureIndexIsNotNegative('input', $input);
        $this->ensureIndexIsNotNegative('video track', $track);

        if ($stream instanceof Stream && $track !== null) {
            throw new InvalidArgumentException('A probed stream cannot be combined with a track index.');
        }

        if ($stream instanceof Stream) {
            return $this->mapProbedStream($stream, 'video', $input, $optional);
        }

        return $this->map(($input ?? 0).':v:'.($track ?? 0).($optional ? '?' : ''));
    }

    /**
     * Map an audio stream from the given input to the output.
     */
    public function mapAudio(
        Stream|int|null $stream = null,
        ?int $input = null,
        ?int $track = null,
        ?string $language = null,
        bool $optional = false,
    ): self {
        $this->assertOutputMutable();

        if (is_int($stream)) {
            if ($input !== null) {
                throw new InvalidArgumentException('Specify an input either positionally or by name, not both.');
            }

            $input = $stream;
            $stream = null;
        }

        $this->ensureIndexIsNotNegative('input', $input);
        $this->ensureIndexIsNotNegative('audio track', $track);

        if ($track !== null && $language !== null) {
            throw new InvalidArgumentException('Choose an audio track or a language tag, not both.');
        }

        if ($stream instanceof Stream && ($track !== null || $language !== null)) {
            throw new InvalidArgumentException(
                'A probed stream cannot be combined with a track index or language tag.',
            );
        }

        if ($language !== null && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $language)) {
            throw new InvalidArgumentException("The language tag [{$language}] is invalid.");
        }

        $selector = $language === null ? (string) ($track ?? 0) : "m:language:{$language}";

        if ($stream instanceof Stream) {
            return $this->mapProbedStream($stream, 'audio', $input, $optional);
        }

        return $this->map(($input ?? 0).':a:'.$selector.($optional ? '?' : ''));
    }

    /**
     * Map the given probed stream, resolving its input when the command compiles.
     */
    protected function mapProbedStream(Stream $stream, string $type, ?int $input, bool $optional): self
    {
        if ($stream->get('codec_type') !== $type) {
            throw new InvalidArgumentException("The mapped stream is not a {$type} stream.");
        }

        if ($stream->index() === null || $stream->sourceIdentity() === null) {
            throw new InvalidArgumentException('The mapped stream must come from a probe.');
        }

        $this->maps[] = ['stream' => $stream, 'input' => $input, 'optional' => $optional];

        return $this;
    }

    /**
     * Ensure the given input or track index is not negative.
     */
    protected function ensureIndexIsNotNegative(string $subject, ?int $index): void
    {
        if ($index !== null && $index < 0) {
            throw new InvalidArgumentException("The {$subject} [{$index}] must not be negative.");
        }
    }

    /**
     * Omit the audio from the output.
     */
    public function withoutAudio(): self
    {
        $this->assertOutputMutable();
        $this->omitAudio = true;

        return $this;
    }

    /**
     * Omit the video from the output.
     */
    public function withoutVideo(): self
    {
        $this->assertOutputMutable();
        $this->omitVideo = true;

        return $this;
    }

    /**
     * Add a filter to the output video chain.
     */
    public function videoFilter(string $expression): self
    {
        $this->assertOutputMutable();
        FilterGraph::validateExpression($expression);
        $this->videoFilters[] = $expression;

        return $this;
    }

    /**
     * Resize the video to the given width, height, or both.
     *
     * One dimension preserves the display aspect ratio, while two dimensions stretch the video.
     */
    public function resize(?int $width = null, ?int $height = null): self
    {
        $this->assertOutputMutable();

        if ($width === null && $height === null) {
            throw new InvalidArgumentException('Resize requires a width, a height, or both.');
        }

        if (($width ?? 1) <= 0 || ($height ?? 1) <= 0) {
            throw new InvalidArgumentException(
                'The dimensions ['.($width ?? 'auto').'x'.($height ?? 'auto').'] must be positive.',
            );
        }

        $this->videoFilters[] = 'scale=w='.($width ?? -2).':h='.($height ?? -2).':reset_sar=1';

        return $this;
    }

    /**
     * Scale the video to fill an exact square-pixel canvas, cropping the centered overflow.
     */
    public function cover(int $width, int $height): self
    {
        return $this->canvas($width, $height, null);
    }

    /**
     * Scale the video to fit inside an exact square-pixel canvas, padding the centered remainder.
     */
    public function contain(int $width, int $height, string $background = 'black'): self
    {
        return $this->canvas($width, $height, $background);
    }

    /**
     * Scale the video onto an exact canvas, cropping without a background or padding with one.
     */
    protected function canvas(int $width, int $height, ?string $background): self
    {
        $this->assertOutputMutable();
        $this->ensureEvenDimensions($width, $height);

        if ($background !== null) {
            CommandCompiler::color($background);
        }

        $canvas = $background === null
            ? "crop=w={$width}:h={$height}:x=(iw-ow)/2:y=(ih-oh)/2"
            : "pad=w={$width}:h={$height}:x=(ow-iw)/2:y=(oh-ih)/2:color={$background}";
        $this->videoFilters[] = "scale=w={$width}:h={$height}"
            .':force_original_aspect_ratio='.($background === null ? 'increase' : 'decrease')
            .":force_divisible_by=2:reset_sar=1,{$canvas}";

        return $this;
    }

    /**
     * Rotate the video clockwise by the given multiple of 90 degrees.
     *
     * The rotation runs in filter order, after FFmpeg's input autorotation.
     */
    public function rotate(int $angle): self
    {
        return $this->videoFilter(match ($angle) {
            90, -270 => 'transpose=clock',
            180, -180 => 'hflip,vflip',
            270, -90 => 'transpose=cclock',
            default => throw new InvalidArgumentException(
                'Rotation requires a clockwise quarter turn: 90, 180, or 270 degrees, or its negative equivalent.',
            ),
        });
    }

    /**
     * Crop the video to the given size, centered unless an offset is given.
     */
    public function crop(int $width, int $height, ?int $x = null, ?int $y = null): self
    {
        $this->ensureEvenDimensions($width, $height);
        $this->ensureOffsetIsNotNegative($x, $y);

        return $this->videoFilter(
            "crop=w={$width}:h={$height}:x=".($x ?? '(iw-ow)/2').':y='.($y ?? '(ih-oh)/2').':exact=1',
        );
    }

    /**
     * Pad the video to the given size, centered unless an offset is given.
     */
    public function pad(int $width, int $height, ?int $x = null, ?int $y = null, string $background = 'black'): self
    {
        $this->ensureEvenDimensions($width, $height);
        $this->ensureOffsetIsNotNegative($x, $y);
        CommandCompiler::color($background);

        return $this->videoFilter(
            "pad=w={$width}:h={$height}:x=".($x ?? '(ow-iw)/2').':y='.($y ?? '(oh-ih)/2').":color={$background}",
        );
    }

    /**
     * Ensure the given frame dimensions are positive and even, as most encoders require.
     */
    protected function ensureEvenDimensions(int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0 || $width % 2 !== 0 || $height % 2 !== 0) {
            throw new InvalidArgumentException("The dimensions [{$width}x{$height}] must be positive and even.");
        }
    }

    /**
     * Ensure the given offset does not point outside the top-left corner.
     */
    protected function ensureOffsetIsNotNegative(?int $x, ?int $y): void
    {
        if (($x ?? 0) < 0 || ($y ?? 0) < 0) {
            throw new InvalidArgumentException(
                'The offset ['.($x ?? 'center').', '.($y ?? 'center').'] must not be negative.',
            );
        }
    }

    /**
     * Draw the given text on the video with the given font.
     */
    public function drawText(
        string $text,
        Media $font,
        int $size = 24,
        int $x = 0,
        int $y = 0,
        string $color = 'white',
    ): self {
        $this->assertOutputMutable();
        $this->videoFilters[] = ResourceFilter::drawText($text, $font, $size, $x, $y, $color);

        return $this;
    }

    /**
     * Burn the given subtitles into the video.
     */
    public function burnSubtitles(Media $subtitles): self
    {
        $this->assertOutputMutable();
        $this->videoFilters[] = ResourceFilter::burnSubtitles($subtitles);

        return $this;
    }

    /**
     * Apply the given 3D LUT file to the video.
     */
    public function lut3d(Media $look): self
    {
        $this->assertOutputMutable();
        $this->videoFilters[] = ResourceFilter::lut3d($look);

        return $this;
    }

    /**
     * Get the video filters that stage their own resources.
     *
     * @internal
     */
    public function resourceFilters(): array
    {
        return collect($this->videoFilters)->whereInstanceOf(ResourceFilter::class)->values()->all();
    }

    /**
     * Add a filter to the output audio chain.
     */
    public function audioFilter(string $expression): self
    {
        $this->assertOutputMutable();
        FilterGraph::validateExpression($expression);
        $this->audioFilters[] = $expression;

        return $this;
    }

    /**
     * Resample the video to the given frame rate.
     *
     * Frames are resampled in filter order without changing the playback speed.
     */
    public function frameRate(int|float|string $rate): self
    {
        if (is_float($rate) && ! is_finite($rate)) {
            throw new InvalidArgumentException('Frame rate requires a finite positive number or positive rational.');
        }

        $parts = explode('/', (string) $rate);

        if (count($parts) > 2
            || array_any($parts, function ($part): bool {
                if (! preg_match('/\A(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?\z/', $part)) {
                    return true;
                }

                return ! is_finite((float) $part) || (float) $part <= 0;
            })
            || ! is_finite((float) $parts[0] / (float) ($parts[1] ?? 1))
            || (float) $parts[0] / (float) ($parts[1] ?? 1) <= 0) {
            throw new InvalidArgumentException('Frame rate requires a finite positive number or positive rational.');
        }

        return $this->videoFilter("fps=fps={$rate}");
    }

    /**
     * Flip the video horizontally.
     */
    public function flipHorizontally(): self
    {
        return $this->videoFilter('hflip');
    }

    /**
     * Flip the video vertically.
     */
    public function flipVertically(): self
    {
        return $this->videoFilter('vflip');
    }

    /**
     * Convert the video to grayscale.
     */
    public function grayscale(): self
    {
        return $this->videoFilter('hue=s=0');
    }

    /**
     * Deinterlace the video, which must happen before any geometry helpers.
     *
     * Frame mode keeps the frame count, while FFmpeg's default field mode outputs a frame for each field.
     */
    public function deinterlace(string $mode = 'frame', string $parity = 'auto', bool $onlyInterlaced = true): self
    {
        if (! in_array($mode, ['frame', 'field'], true)) {
            throw new InvalidArgumentException("The deinterlace mode [{$mode}] must be frame or field.");
        }

        if (! in_array($parity, ['auto', 'tff', 'bff'], true)) {
            throw new InvalidArgumentException("The deinterlace parity [{$parity}] must be auto, tff, or bff.");
        }

        return $this->videoFilter(
            "bwdif=mode=send_{$mode}:parity={$parity}:deint=".($onlyInterlaced ? 'interlaced' : 'all'),
        );
    }

    /**
     * Multiply the audio amplitude by the given linear factor.
     *
     * A factor of zero mutes the audio.
     */
    public function volume(float $multiplier): self
    {
        if (! is_finite($multiplier) || $multiplier < 0) {
            throw new InvalidArgumentException('Volume requires a finite nonnegative multiplier.');
        }

        return $this->audioFilter("volume={$multiplier}");
    }

    /**
     * Delay the audio by inserting silence on every channel.
     *
     * The silence extends the audio rather than shifting its timestamps.
     */
    public function delayAudio(float $seconds): self
    {
        if (! is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('Audio delay requires finite nonnegative seconds.');
        }

        // FFmpeg 9 reads a fractional value with an "s" suffix as milliseconds, so we
        // will pass whole seconds with the suffix and fractional delays as plain milliseconds.
        $delay = floor($seconds) === $seconds
            ? sprintf('%.0F', $seconds).'s'
            : rtrim(rtrim(sprintf('%.17F', $seconds * 1000), '0'), '.');

        return $this->audioFilter("adelay=delays={$delay}:all=1");
    }

    /**
     * Fade the video and audio in, starting at zero seconds by default.
     */
    public function fadeIn(float $duration, float $start = 0, bool $video = true, bool $audio = true): self
    {
        return $this->fade('in', $duration, $start, $video, $audio);
    }

    /**
     * Fade the video and audio out from the given start time in seconds.
     */
    public function fadeOut(float $duration, float $start, bool $video = true, bool $audio = true): self
    {
        return $this->fade('out', $duration, $start, $video, $audio);
    }

    /**
     * Add the video and audio filters of a fade in the given direction.
     */
    protected function fade(string $type, float $duration, float $start, bool $video, bool $audio): self
    {
        $this->assertOutputMutable();

        if (! is_finite($duration) || $duration <= 0) {
            throw new InvalidArgumentException('The fade duration must be finite and positive.');
        }

        if (! is_finite($start) || $start < 0) {
            throw new InvalidArgumentException('The fade start must be finite and not negative.');
        }

        if (! $video && ! $audio) {
            throw new InvalidArgumentException('A fade must apply to video, audio, or both.');
        }

        $timing = "t={$type}"
            .':st='.rtrim(rtrim(sprintf('%.17F', $start), '0'), '.')
            .':d='.rtrim(rtrim(sprintf('%.17F', $duration), '0'), '.');

        if ($video) {
            $this->videoFilter("fade={$timing}");
        }

        if ($audio) {
            $this->audioFilter("afade={$timing}");
        }

        return $this;
    }

    /**
     * Add allowed raw FFmpeg options to the output.
     */
    public function outputOptions(array $tokens): self
    {
        $this->assertOutputMutable();
        $this->outputOptions = CommandCompiler::options([...$this->outputOptions, ...$tokens], 'output');

        return $this;
    }

    /**
     * Omit the container metadata, and optionally the chapters, from the output.
     */
    public function withoutMetadata(bool $chapters = false): self
    {
        $this->assertOutputMutable();
        $this->metadataSource = -1;

        if ($chapters) {
            $this->chapterSource = -1;
        }

        return $this;
    }

    /**
     * Copy the container metadata from the given input, or omit it when the input is null.
     */
    public function metadataFrom(?int $input): self
    {
        $this->assertOutputMutable();

        if ($input !== null && $input < 0) {
            throw new InvalidArgumentException(
                'The metadata source must be a nonnegative input index or null to omit metadata.',
            );
        }

        $this->metadataSource = $input ?? -1;

        return $this;
    }

    /**
     * Set a container metadata tag on the output.
     */
    public function metadata(string $key, string $value): self
    {
        $this->assertOutputMutable();
        $this->metadataTag($key, $value);

        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * Set a metadata tag on the given output stream.
     */
    public function streamMetadata(string|int $stream, string $key, string $value): self
    {
        $this->assertOutputMutable();
        $this->outputStream($stream);
        $this->metadataTag($key, $value);

        $this->streamMetadata[] = [$stream, $key, $value];

        return $this;
    }

    /**
     * Set the disposition flags of the given output stream.
     *
     * An empty list clears the flags.
     */
    public function disposition(string|int $stream, string|array $flags): self
    {
        $this->assertOutputMutable();
        $this->outputStream($stream);

        if (is_string($flags)) {
            $flags = $flags === '0' ? [] : explode('+', $flags);
        }

        if (! array_is_list($flags) || array_any($flags, function ($flag): bool {
            $supported = [
                'default', 'dub', 'original', 'comment', 'lyrics', 'karaoke', 'forced',
                'hearing_impaired', 'visual_impaired', 'clean_effects', 'attached_pic',
                'timed_thumbnails', 'non_diegetic', 'captions', 'descriptions', 'metadata',
                'dependent', 'still_image', 'multilayer',
            ];

            return ! is_string($flag) || ! in_array($flag, $supported, true);
        })) {
            throw new InvalidArgumentException(
                'Dispositions require supported flag names, or an empty list to clear them.',
            );
        }

        $this->dispositions[] = [$stream, $flags === [] ? '0' : implode('+', array_unique($flags))];

        return $this;
    }

    /**
     * Ensure the given output stream selector is valid.
     */
    protected function outputStream(string|int $stream): void
    {
        if (! preg_match('/^(?:[0-9]+|[vasdt](?::[0-9]+)?)$/D', (string) $stream)) {
            throw new InvalidArgumentException(
                'Select an output stream by absolute index, type, or type and track index.',
            );
        }
    }

    /**
     * Ensure the given metadata key and value are valid.
     */
    protected function metadataTag(string $key, string $value): void
    {
        if (! preg_match('/^[a-zA-Z0-9_.:-]+$/D', $key)) {
            throw new InvalidArgumentException(
                'Metadata keys may contain only letters, numbers, underscores, periods, colons, and dashes.',
            );
        }

        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException('Metadata values must not contain NUL bytes.');
        }
    }

    /**
     * Copy the chapters from the given input, or omit them when the input is null.
     */
    public function chaptersFrom(?int $input): self
    {
        $this->assertOutputMutable();

        if ($input !== null && $input < 0) {
            throw new InvalidArgumentException(
                'The chapter source must be a nonnegative input index or null to omit chapters.',
            );
        }

        $this->chapterSource = $input ?? -1;

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
    ): self {
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
    public function toDisk(string|Filesystem $disk, array $options = []): self
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
    public function visibility(string $visibility): self
    {
        $this->assertOutputMutable();
        $this->writeOptions['visibility'] = $visibility;

        return $this;
    }

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

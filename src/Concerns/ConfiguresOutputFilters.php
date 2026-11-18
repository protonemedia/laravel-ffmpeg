<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\FilterGraph;
use ProtoneMedia\LaravelFFMpeg\Execution\ResourceFilter;
use ProtoneMedia\LaravelFFMpeg\Media;

/**
 * Configure the video and audio filter chains of an output.
 *
 * @internal
 */
trait ConfiguresOutputFilters
{
    /**
     * Add a filter to the output video chain.
     */
    public function videoFilter(string $expression): static
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
    public function resize(?int $width = null, ?int $height = null): static
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
    public function cover(int $width, int $height): static
    {
        return $this->canvas($width, $height, null);
    }

    /**
     * Scale the video to fit inside an exact square-pixel canvas, padding the centered remainder.
     */
    public function contain(int $width, int $height, string $background = 'black'): static
    {
        return $this->canvas($width, $height, $background);
    }

    /**
     * Scale the video onto an exact canvas, cropping without a background or padding with one.
     */
    protected function canvas(int $width, int $height, ?string $background): static
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
    public function rotate(int $angle): static
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
    public function crop(int $width, int $height, ?int $x = null, ?int $y = null): static
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
    public function pad(int $width, int $height, ?int $x = null, ?int $y = null, string $background = 'black'): static
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
    ): static {
        $this->assertOutputMutable();

        $this->videoFilters[] = ResourceFilter::drawText($text, $font, $size, $x, $y, $color);

        return $this;
    }

    /**
     * Burn the given subtitles into the video.
     */
    public function burnSubtitles(Media $subtitles): static
    {
        $this->assertOutputMutable();

        $this->videoFilters[] = ResourceFilter::burnSubtitles($subtitles);

        return $this;
    }

    /**
     * Apply the given 3D LUT file to the video.
     */
    public function lut3d(Media $look): static
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
    public function audioFilter(string $expression): static
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
    public function frameRate(int|float|string $rate): static
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
    public function flipHorizontally(): static
    {
        return $this->videoFilter('hflip');
    }

    /**
     * Flip the video vertically.
     */
    public function flipVertically(): static
    {
        return $this->videoFilter('vflip');
    }

    /**
     * Convert the video to grayscale.
     */
    public function grayscale(): static
    {
        return $this->videoFilter('hue=s=0');
    }

    /**
     * Deinterlace the video, which must happen before any geometry helpers.
     *
     * Frame mode keeps the frame count, while FFmpeg's default field mode outputs a frame for each field.
     */
    public function deinterlace(string $mode = 'frame', string $parity = 'auto', bool $onlyInterlaced = true): static
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
    public function volume(float $multiplier): static
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
    public function delayAudio(float $seconds): static
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
    public function fadeIn(float $duration, float $start = 0, bool $video = true, bool $audio = true): static
    {
        return $this->fade('in', $duration, $start, $video, $audio);
    }

    /**
     * Fade the video and audio out from the given start time in seconds.
     */
    public function fadeOut(float $duration, float $start, bool $video = true, bool $audio = true): static
    {
        return $this->fade('out', $duration, $start, $video, $audio);
    }

    /**
     * Add the video and audio filters of a fade in the given direction.
     */
    protected function fade(string $type, float $duration, float $start, bool $video, bool $audio): static
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
}

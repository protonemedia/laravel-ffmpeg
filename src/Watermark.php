<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Image\Transformation;
use Illuminate\Image\Transformations\Blur;
use Illuminate\Image\Transformations\Crop;
use Illuminate\Image\Transformations\FlipHorizontally;
use Illuminate\Image\Transformations\FlipVertically;
use Illuminate\Image\Transformations\Grayscale;
use Illuminate\Image\Transformations\Rotate;
use Illuminate\Image\Transformations\Sharpen;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use Intervention\Image\ImageManager;
use InvalidArgumentException;

/**
 * Configures one watermark inside the watermark() callback.
 *
 * Placement, size(), opacity() and loop() change how FFmpeg draws the mark, in output pixels.
 * Image edits change the image itself before FFmpeg reads it, with Laravel's image manipulation.
 * They use the same names and arguments as Illuminate\Image\Image and run in call order.
 */
class Watermark
{
    use Conditionable, Macroable;

    /** @var array{left?: int, right?: int} */
    protected array $horizontal = [];

    /** @var array{top?: int, bottom?: int} */
    protected array $vertical = [];

    protected bool $centered = false;

    protected ?int $width = null;

    protected ?int $height = null;

    protected float $opacity = 1;

    protected bool $loop = false;

    /** @var list<Transformation> */
    protected array $edits = [];

    /** Keep the mark this many output pixels from the left edge. */
    public function left(int $margin = 0): self
    {
        return $this->pin('horizontal', 'left', 'right', $margin);
    }

    /** Keep the mark this many output pixels from the right edge. */
    public function right(int $margin = 0): self
    {
        return $this->pin('horizontal', 'right', 'left', $margin);
    }

    /** Keep the mark this many output pixels from the top edge. */
    public function top(int $margin = 0): self
    {
        return $this->pin('vertical', 'top', 'bottom', $margin);
    }

    /** Keep the mark this many output pixels from the bottom edge. */
    public function bottom(int $margin = 0): self
    {
        return $this->pin('vertical', 'bottom', 'top', $margin);
    }

    /** Center the mark on every axis that no edge method pins, so center()->bottom(40) is bottom-center. */
    public function center(): self
    {
        $this->centered = true;

        return $this;
    }

    /** Draw the mark at this size in output pixels. One dimension keeps the aspect ratio; two stretch. */
    public function size(?int $width = null, ?int $height = null): self
    {
        if (($width === null && $height === null) || ($width !== null && $width < 1) || ($height !== null && $height < 1)) {
            throw new InvalidArgumentException('A watermark size requires at least one positive dimension.');
        }

        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    /** Draw the mark with this opacity, from 0 (invisible) to 1 (opaque). */
    public function opacity(float $opacity): self
    {
        if (! is_finite($opacity) || $opacity < 0 || $opacity > 1) {
            throw new InvalidArgumentException('Watermark opacity must be between zero and one.');
        }

        $this->opacity = $opacity;

        return $this;
    }

    /** Repeat an animated GIF for the whole video instead of playing it once, whatever loop count the GIF embeds. */
    public function loop(bool $loop = true): self
    {
        $this->loop = $loop;

        return $this;
    }

    public function grayscale(): self
    {
        return $this->edit(new Grayscale);
    }

    public function blur(int $amount = 5): self
    {
        return $this->edit(new Blur($this->amount($amount)));
    }

    public function sharpen(int $amount = 10): self
    {
        return $this->edit(new Sharpen($this->amount($amount)));
    }

    /** Rotate the image clockwise by any angle, filling the corners with the background color. */
    public function rotate(float $angle, ?string $background = null): self
    {
        if (! is_finite($angle)) {
            throw new InvalidArgumentException('A watermark rotation requires a finite angle.');
        }

        return $this->edit(new Rotate($angle, $background));
    }

    /**
     * Crop the image itself, in the image's own pixels, from the given top-left offset.
     *
     * The offset is required: a video crop() without offsets centers, while Laravel Image starts at the top-left corner.
     */
    public function crop(int $width, int $height, int $x, int $y): self
    {
        if ($width < 1 || $height < 1 || $x < 0 || $y < 0) {
            throw new InvalidArgumentException('A watermark crop requires positive dimensions and a nonnegative offset.');
        }

        return $this->edit(new Crop($width, $height, $x, $y));
    }

    public function flipHorizontally(): self
    {
        return $this->edit(new FlipHorizontally);
    }

    public function flipVertically(): self
    {
        return $this->edit(new FlipVertically);
    }

    /**
     * @internal
     *
     * @return array{horizontal: array{0: 'left'|'right'|'center', 1: int}, vertical: array{0: 'top'|'bottom'|'center', 1: int},
     *     width: ?int, height: ?int, opacity: float, loop: bool, edits: list<Transformation>}
     */
    public function settings(): array
    {
        $default = fn (array $pinned, string $edge): array => $pinned !== [] ? [array_key_first($pinned), reset($pinned)]
            : ($this->centered ? ['center', 0] : [$edge, 0]);

        return [
            'horizontal' => $default($this->horizontal, 'right'),
            'vertical' => $default($this->vertical, 'bottom'),
            'width' => $this->width,
            'height' => $this->height,
            'opacity' => $this->opacity,
            'loop' => $this->loop,
            'edits' => $this->edits,
        ];
    }

    protected function pin(string $axis, string $edge, string $opposite, int $margin): self
    {
        if ($margin < 0) {
            throw new InvalidArgumentException('Watermark margins must be nonnegative.');
        }

        if (isset($this->{$axis}[$opposite])) {
            throw new InvalidArgumentException('A watermark cannot be pinned to both the '.$opposite.' and the '.$edge.' edge.');
        }

        $this->{$axis} = [$edge => $margin];

        return $this;
    }

    protected function amount(int $amount): int
    {
        if ($amount < 0 || $amount > 100) {
            throw new InvalidArgumentException('Watermark blur and sharpen amounts must be between 0 and 100.');
        }

        return $amount;
    }

    protected function edit(Transformation $transformation): self
    {
        if (! class_exists(ImageManager::class)) {
            throw new InvalidArgumentException('Watermark image edits require intervention/image ^4.2.');
        }

        $this->edits[] = $transformation;

        return $this;
    }
}

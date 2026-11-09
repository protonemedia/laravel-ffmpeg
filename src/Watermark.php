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
 * Configure one watermark inside the watermark() callback.
 *
 * Placement, size(), opacity() and loop() change how FFmpeg draws the mark, in output pixels. Image edits
 * change the image itself before FFmpeg reads it, with Laravel's image manipulation. They use the same
 * names and arguments as Illuminate\Image\Image and run in call order.
 */
class Watermark
{
    use Conditionable, Macroable;

    /**
     * The horizontal edge the mark is pinned to, with its margin.
     *
     * @var array{left?: int, right?: int}
     */
    protected array $horizontal = [];

    /**
     * The vertical edge the mark is pinned to, with its margin.
     *
     * @var array{top?: int, bottom?: int}
     */
    protected array $vertical = [];

    /**
     * Indicates if the mark is centered on every axis that no edge pins.
     */
    protected bool $centered = false;

    /**
     * The drawn width of the mark in output pixels.
     */
    protected ?int $width = null;

    /**
     * The drawn height of the mark in output pixels.
     */
    protected ?int $height = null;

    /**
     * The opacity the mark is drawn with.
     */
    protected float $opacity = 1;

    /**
     * Indicates if an animated GIF mark repeats for the whole video.
     */
    protected bool $loop = false;

    /**
     * The edits applied to the image before FFmpeg reads it.
     *
     * @var list<Transformation>
     */
    protected array $edits = [];

    /**
     * Keep the mark the given number of output pixels from the left edge.
     */
    public function left(int $margin = 0): self
    {
        return $this->pin('horizontal', 'left', 'right', $margin);
    }

    /**
     * Keep the mark the given number of output pixels from the right edge.
     */
    public function right(int $margin = 0): self
    {
        return $this->pin('horizontal', 'right', 'left', $margin);
    }

    /**
     * Keep the mark the given number of output pixels from the top edge.
     */
    public function top(int $margin = 0): self
    {
        return $this->pin('vertical', 'top', 'bottom', $margin);
    }

    /**
     * Keep the mark the given number of output pixels from the bottom edge.
     */
    public function bottom(int $margin = 0): self
    {
        return $this->pin('vertical', 'bottom', 'top', $margin);
    }

    /**
     * Center the mark on every axis that no edge method pins.
     *
     * For example, center()->bottom(40) places the mark at the bottom center.
     */
    public function center(): self
    {
        $this->centered = true;

        return $this;
    }

    /**
     * Draw the mark at the given size in output pixels.
     *
     * One dimension keeps the aspect ratio, while two dimensions stretch the mark.
     */
    public function size(?int $width = null, ?int $height = null): self
    {
        if ($width === null && $height === null) {
            throw new InvalidArgumentException('A watermark size requires a width, a height, or both.');
        }

        if (($width ?? 1) < 1 || ($height ?? 1) < 1) {
            throw new InvalidArgumentException(
                'The watermark size ['.($width ?? 'auto').'x'.($height ?? 'auto').'] must be positive.',
            );
        }

        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    /**
     * Draw the mark with the given opacity, from 0 for invisible to 1 for opaque.
     */
    public function opacity(float $opacity): self
    {
        if (! is_finite($opacity) || $opacity < 0 || $opacity > 1) {
            throw new InvalidArgumentException("The watermark opacity [{$opacity}] must be between 0 and 1.");
        }

        $this->opacity = $opacity;

        return $this;
    }

    /**
     * Repeat an animated GIF for the whole video instead of playing it once.
     *
     * The repetition ignores any loop count the GIF embeds.
     */
    public function loop(bool $loop = true): self
    {
        $this->loop = $loop;

        return $this;
    }

    /**
     * Convert the image to grayscale.
     */
    public function grayscale(): self
    {
        return $this->edit(new Grayscale);
    }

    /**
     * Blur the image by the given amount, from 0 to 100.
     */
    public function blur(int $amount = 5): self
    {
        return $this->edit(new Blur($this->amount($amount)));
    }

    /**
     * Sharpen the image by the given amount, from 0 to 100.
     */
    public function sharpen(int $amount = 10): self
    {
        return $this->edit(new Sharpen($this->amount($amount)));
    }

    /**
     * Rotate the image clockwise by any angle, filling the corners with the background color.
     */
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
     * The offset is required because a video crop() without offsets centers, while Laravel's
     * image crop starts at the top-left corner.
     */
    public function crop(int $width, int $height, int $x, int $y): self
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException("The watermark crop dimensions [{$width}x{$height}] must be positive.");
        }

        if ($x < 0 || $y < 0) {
            throw new InvalidArgumentException("The watermark crop offset [{$x}, {$y}] must not be negative.");
        }

        return $this->edit(new Crop($width, $height, $x, $y));
    }

    /**
     * Flip the image horizontally.
     */
    public function flipHorizontally(): self
    {
        return $this->edit(new FlipHorizontally);
    }

    /**
     * Flip the image vertically.
     */
    public function flipVertically(): self
    {
        return $this->edit(new FlipVertically);
    }

    /**
     * Get the placement, size, opacity, loop and image edits of the watermark.
     *
     * @internal
     *
     * @return array{
     *     horizontal: array{0: 'left'|'right'|'center', 1: int},
     *     vertical: array{0: 'top'|'bottom'|'center', 1: int},
     *     width: ?int, height: ?int, opacity: float, loop: bool, edits: list<Transformation>
     * }
     */
    public function settings(): array
    {
        $default = fn (array $pinned, string $edge): array => $pinned !== []
            ? [array_key_first($pinned), reset($pinned)]
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

    /**
     * Pin the mark to the given edge of the given axis.
     */
    protected function pin(string $axis, string $edge, string $opposite, int $margin): self
    {
        if ($margin < 0) {
            throw new InvalidArgumentException('Watermark margins must be nonnegative.');
        }

        if (isset($this->{$axis}[$opposite])) {
            throw new InvalidArgumentException(
                'A watermark cannot be pinned to both the '.$opposite.' and the '.$edge.' edge.',
            );
        }

        $this->{$axis} = [$edge => $margin];

        return $this;
    }

    /**
     * Ensure the given blur or sharpen amount is between 0 and 100.
     */
    protected function amount(int $amount): int
    {
        if ($amount < 0 || $amount > 100) {
            throw new InvalidArgumentException('Watermark blur and sharpen amounts must be between 0 and 100.');
        }

        return $amount;
    }

    /**
     * Add the given edit to the edits applied to the image.
     */
    protected function edit(Transformation $transformation): self
    {
        if (! class_exists(ImageManager::class)) {
            throw new InvalidArgumentException('Watermark image edits require intervention/image ^4.2.');
        }

        $this->edits[] = $transformation;

        return $this;
    }
}

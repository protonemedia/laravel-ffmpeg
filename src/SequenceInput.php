<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use League\Flysystem\WhitespacePathNormalizer;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\InputSource;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/** @internal */
readonly class SequenceInput implements InputSource
{
    protected array $images;

    protected string $rate;

    protected string $suffix;

    public function __construct(string|Filesystem $disk, string $pattern, int $count, float|string $framerate, int $start)
    {
        if ($count < 1 || $start < 0 || $start > PHP_INT_MAX - ($count - 1)) {
            throw new InvalidArgumentException('An image sequence requires a positive count and a nonnegative integer range.');
        }

        if (preg_match('/^(?:[\\\\\/]|[a-z][a-z0-9+.-]*:)/i', $pattern)) {
            throw new InvalidArgumentException('Image sequences require a disk-relative pattern.');
        }

        $pattern = (new WhitespacePathNormalizer)->normalizePath($pattern);
        $suffix = strtolower(pathinfo($pattern, PATHINFO_EXTENSION));
        if (! preg_match('/\A[^%]*%(?:0[1-9][0-9]?)?d[^%]*\z/', $pattern)
            || str_contains(dirname($pattern), '%') || ! in_array($suffix, ['png', 'jpg', 'jpeg'], true)) {
            throw new InvalidArgumentException('Use one %d or %0Nd placeholder in a PNG or JPEG filename.');
        }

        $rate = (string) $framerate;
        if (! preg_match('/\A[0-9]+(?:\.[0-9]+|\/[1-9][0-9]*)?\z/', $rate) || (float) $rate <= 0) {
            throw new InvalidArgumentException('The input frame rate must be a positive decimal or rational rate.');
        }

        $images = [];
        for ($offset = 0; $offset < $count; $offset++) {
            $images[] = new Media($disk, sprintf($pattern, $start + $offset));
        }

        $this->images = $images;
        $this->rate = $rate;
        $this->suffix = $suffix;
    }

    public function identity(): string
    {
        return hash('sha256', serialize([$this->rate, array_map(fn ($image): string => $image->identity(), $this->images)]));
    }

    public function extension(): string
    {
        return $this->suffix;
    }

    public function label(): string
    {
        return '[image sequence]';
    }

    public function protocols(): string
    {
        return 'file';
    }

    public function options(): array
    {
        return ['-f', 'image2', '-pattern_type', 'sequence', '-framerate', $this->rate, '-start_number', '0', '-start_number_range', '1'];
    }

    public function redactions(): array
    {
        return [];
    }

    public function stage(Workspace $workspace, string $name, bool $resource): string
    {
        if ($resource) {
            throw new MediaException('An image sequence cannot be a filter resource.', 'staging', 'resource');
        }

        $dimensions = null;
        $type = $this->suffix === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
        foreach ($this->images as $index => $image) {
            $source = $workspace->stage($image);
            $size = @getimagesize($source);
            if ($size === false || $size[2] !== $type || ($dimensions !== null && $dimensions !== [$size[0], $size[1]])) {
                throw new MediaException('Sequence image '.$index.' requires the declared format and matching dimensions.', 'staging', 'sequence');
            }

            $dimensions = [$size[0], $size[1]];
            $target = $workspace->path.'/'.$name.'-'.sprintf('%08d', $index).'.'.$this->suffix;
            if (! @symlink($source, $target) && (! @copy($source, $target) || ! chmod($target, 0600))) {
                throw new MediaException('Cannot prepare sequence image '.$index.'.', 'staging', 'filesystem');
            }
        }

        return $workspace->path.'/'.$name.'-%08d.'.$this->suffix;
    }
}

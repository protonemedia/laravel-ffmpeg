<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use League\Flysystem\WhitespacePathNormalizer;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\InputSource;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/**
 * A numbered sequence of PNG or JPEG images read as one video input.
 *
 * @internal
 */
class SequenceInput implements InputSource
{
    /**
     * The media of each image in the sequence.
     */
    protected readonly array $images;

    /**
     * The input frame rate, as a decimal or rational string.
     */
    protected readonly string $rate;

    /**
     * The lowercase image extension of the sequence.
     */
    protected readonly string $suffix;

    /**
     * Create a new image sequence input instance.
     */
    public function __construct(
        string|Filesystem $disk,
        string $pattern,
        int $count,
        float|string $framerate,
        int $start,
    ) {
        if ($count < 1) {
            throw new InvalidArgumentException("The image sequence count [{$count}] must be positive.");
        }

        if ($start < 0) {
            throw new InvalidArgumentException(
                "The image sequence start number [{$start}] must not be negative."
            );
        }

        if ($start > PHP_INT_MAX - ($count - 1)) {
            throw new InvalidArgumentException('The image sequence range must not exceed the maximum integer.');
        }

        if (preg_match('/^(?:[\\\\\/]|[a-z][a-z0-9+.-]*:)/i', $pattern)) {
            throw new InvalidArgumentException('Image sequences require a disk-relative pattern.');
        }

        $pattern = (new WhitespacePathNormalizer)->normalizePath($pattern);
        $suffix = strtolower(pathinfo($pattern, PATHINFO_EXTENSION));
        if (! preg_match('/\A[^%]*%(?:0[1-9][0-9]?)?d[^%]*\z/', $pattern) || str_contains(dirname($pattern), '%')) {
            throw new InvalidArgumentException(
                'The image sequence pattern must contain one %d or %0Nd placeholder in its filename.'
            );
        }

        if (! in_array($suffix, ['png', 'jpg', 'jpeg'], true)) {
            throw new InvalidArgumentException('The image sequence pattern must end in a PNG or JPEG extension.');
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

    /**
     * Get a stable hash that identifies the frame rate and every image.
     */
    public function identity(): string
    {
        return hash('sha256', serialize([
            $this->rate,
            array_map(fn ($image): string => $image->identity(), $this->images),
        ]));
    }

    /**
     * Get the image extension of the sequence.
     */
    public function extension(): string
    {
        return $this->suffix;
    }

    /**
     * Get the label that stands in for the sequence in metadata.
     */
    public function label(): string
    {
        return '[image sequence]';
    }

    /**
     * Get the FFmpeg protocol whitelist for the staged sequence.
     */
    public function protocols(): string
    {
        return 'file';
    }

    /**
     * Get the FFmpeg image2 demuxer options for the staged sequence.
     */
    public function options(): array
    {
        return [
            '-f', 'image2', '-pattern_type', 'sequence', '-framerate', $this->rate,
            '-start_number', '0', '-start_number_range', '1',
        ];
    }

    /**
     * Get the secret values to replace in commands and diagnostics.
     */
    public function redactions(): array
    {
        return [];
    }

    /**
     * Stage every image under a contiguous zero-based name in the workspace.
     */
    public function stage(Workspace $workspace, string $name, bool $resource): string
    {
        if ($resource) {
            throw MediaException::staging('An image sequence cannot be a filter resource.', 'resource');
        }

        $dimensions = null;
        $type = $this->suffix === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
        foreach ($this->images as $index => $image) {
            $source = $workspace->stage($image);
            $size = @getimagesize($source);
            if ($size === false || $size[2] !== $type) {
                throw MediaException::staging(
                    "Sequence image [{$index}] does not match the declared image format.",
                    'sequence',
                );
            }

            if ($dimensions !== null && $dimensions !== [$size[0], $size[1]]) {
                throw MediaException::staging(
                    "Sequence image [{$index}] dimensions do not match the first image.",
                    'sequence',
                );
            }

            $dimensions = [$size[0], $size[1]];
            $target = "{$workspace->path}/{$name}-".sprintf('%08d', $index).".{$this->suffix}";
            if (! @symlink($source, $target) && (! @copy($source, $target) || ! chmod($target, 0600))) {
                throw MediaException::staging("Cannot prepare sequence image {$index}.", 'filesystem');
            }
        }

        return "{$workspace->path}/{$name}-%08d.{$this->suffix}";
    }
}

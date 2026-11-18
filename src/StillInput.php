<?php

namespace ProtoneMedia\LaravelFFMpeg;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\InputSource;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/**
 * A validated single raster image held for a bounded video output.
 *
 * @internal
 */
class StillInput implements InputSource
{
    /**
     * Create a new still image input instance.
     */
    public function __construct(protected readonly Media $image, protected readonly int $frameRate)
    {
        if ($image->inputOptions() !== [] || $image->transportOptions() !== []) {
            throw new InvalidArgumentException('The still video image must not have input options.');
        }

        if ($image->protocolWhitelist() !== 'file') {
            throw new InvalidArgumentException('The still video image must be a local or downloaded file.');
        }
    }

    /**
     * Get a stable hash that identifies the image and frame rate.
     */
    public function identity(): string
    {
        return hash('sha256', serialize([$this->image->identity(), $this->frameRate]));
    }

    /**
     * Get the file extension of the image.
     */
    public function extension(): string
    {
        return $this->image->extension();
    }

    /**
     * Get the label that stands in for the image in metadata.
     */
    public function label(): string
    {
        return '[still image]';
    }

    /**
     * Get the FFmpeg protocol whitelist for the staged image.
     */
    public function protocols(): string
    {
        return 'file';
    }

    /**
     * Get the FFmpeg options that loop the image at the frame rate.
     */
    public function options(): array
    {
        return ['-loop', '1', '-framerate', (string) $this->frameRate];
    }

    /**
     * Get the redactions of the image.
     */
    public function redactions(): array
    {
        return $this->image->redactions();
    }

    /**
     * Stage the image and ensure it is a PNG or JPEG with even dimensions.
     */
    public function stage(Workspace $workspace, string $name, bool $resource): string
    {
        if ($resource) {
            throw MediaException::staging('Still video cannot be used as a filter resource.', 'resource');
        }

        $path = $workspace->stage($this->image, resource: true);
        $size = @getimagesize($path);

        if ($size === false || ! in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw MediaException::staging('The still video image must be a PNG or JPEG image.', 'image');
        }

        if ($size[0] < 2 || $size[1] < 2 || $size[0] % 2 || $size[1] % 2) {
            throw MediaException::staging(
                "The still video image dimensions [{$size[0]}x{$size[1]}] must be positive and even.",
                'image',
            );
        }

        return $path;
    }
}

<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\InputSource;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/** @internal A validated single raster image held for a bounded output. */
readonly class StillInput implements InputSource
{
    public function __construct(protected Media $image, protected int $frameRate)
    {
        if ($image->inputOptions() !== [] || $image->transportOptions() !== [] || $image->protocolWhitelist() !== 'file') {
            throw new InvalidArgumentException('Still video requires a local or downloaded image without input options.');
        }
    }

    public function identity(): string
    {
        return hash('sha256', serialize([$this->image->identity(), $this->frameRate]));
    }

    public function extension(): string
    {
        return $this->image->extension();
    }

    public function label(): string
    {
        return '[still image]';
    }

    public function protocols(): string
    {
        return 'file';
    }

    public function options(): array
    {
        return ['-loop', '1', '-framerate', (string) $this->frameRate];
    }

    public function redactions(): array
    {
        return $this->image->redactions();
    }

    public function stage(Workspace $workspace, string $name, bool $resource): string
    {
        if ($resource) {
            throw new MediaException('Still video cannot be used as a filter resource.', 'staging', 'resource');
        }

        $path = $workspace->stage($this->image, resource: true);
        $size = @getimagesize($path);
        if ($size === false || ! in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
            || $size[0] < 2 || $size[1] < 2 || $size[0] % 2 || $size[1] % 2) {
            throw new MediaException('Still video requires a PNG/JPEG image with positive even dimensions.', 'staging', 'image');
        }

        return $path;
    }
}

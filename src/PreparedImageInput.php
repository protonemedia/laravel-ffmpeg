<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Image\Transformation;
use Illuminate\Image\Image;
use Illuminate\Support\Facades\Image as Images;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\InputSource;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use Throwable;

/**
 * A private copy of an image with Laravel image transformations applied.
 *
 * @internal
 */
class PreparedImageInput implements InputSource
{
    /**
     * Create a new prepared image input instance.
     *
     * @param  list<Transformation>  $edits
     */
    public function __construct(protected readonly Media $image, protected readonly array $edits)
    {
        if ($image->inputOptions() !== []
            || ($image->protocolWhitelist() === 'file' && $image->transportOptions() !== [])) {
            throw new InvalidArgumentException('Image preparation requires a standalone image without input options.');
        }
    }

    /**
     * Get a stable hash that identifies the image and its edits.
     */
    public function identity(): string
    {
        return hash('sha256', serialize([$this->image->identity(), $this->edits]));
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
        return '[prepared image]';
    }

    /**
     * Get the FFmpeg protocol whitelist for the prepared copy.
     */
    public function protocols(): string
    {
        return 'file';
    }

    /**
     * Get the FFmpeg transport options for the prepared copy.
     */
    public function options(): array
    {
        return [];
    }

    /**
     * Get the redactions of the image.
     */
    public function redactions(): array
    {
        return $this->image->redactions();
    }

    /**
     * Stage a private copy of the image and apply the edits to it.
     */
    public function stage(Workspace $workspace, string $name, bool $resource): string
    {
        // URL images are always downloaded through Http, even when their descriptor
        // selected direct input, and the edits never touch an original file.
        $source = $workspace->stage($this->image, resource: true);

        $this->validate($source);

        $target = "{$workspace->path}/{$name}";

        if (! @copy($source, $target) || ! chmod($target, 0600)) {
            throw MediaException::staging('Cannot prepare a private image copy.', 'filesystem');
        }

        try {
            // Transformations run lazily, so decoding, editing and encoding all happen
            // inside toBytes() and any driver failure surfaces within this block.
            $bytes = array_reduce(
                $this->edits,
                fn (Image $image, Transformation $edit): Image => $image->transform($edit),
                Images::fromPath($target),
            )->toBytes();
        } catch (Throwable) {
            // Image drivers can include private paths and URL credentials in their
            // errors, so we do not retain their exception chain.
            throw MediaException::staging('Cannot edit the watermark image.', 'resource');
        }

        if (@file_put_contents($target, $bytes) !== strlen($bytes)) {
            throw MediaException::staging('Cannot write the prepared image.', 'filesystem');
        }

        clearstatcache(true, $target);
        $this->validate($target);

        if (! chmod($target, 0600)) {
            throw MediaException::staging('Cannot protect the prepared image.', 'filesystem');
        }

        return $target;
    }

    /**
     * Ensure the file at the given path is readable raster image data.
     */
    protected function validate(string $path): void
    {
        $size = @getimagesize($path);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw MediaException::staging('Image preparation requires readable raster image data.', 'resource');
        }
    }
}

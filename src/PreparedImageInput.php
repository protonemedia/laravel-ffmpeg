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

/** @internal */
readonly class PreparedImageInput implements InputSource
{
    /** @param  list<Transformation>  $edits */
    public function __construct(private Media $image, private array $edits)
    {
        if ($image->inputOptions() !== [] || ($image->protocolWhitelist() === 'file' && $image->transportOptions() !== [])) {
            throw new InvalidArgumentException('Image preparation requires a standalone image without input options.');
        }
    }

    public function identity(): string
    {
        return hash('sha256', serialize([$this->image->identity(), $this->edits]));
    }

    public function extension(): string
    {
        return $this->image->extension();
    }

    public function label(): string
    {
        return '[prepared image]';
    }

    public function protocols(): string
    {
        return 'file';
    }

    public function options(): array
    {
        return [];
    }

    public function redactions(): array
    {
        return $this->image->redactions();
    }

    public function stage(Workspace $workspace, string $name, bool $resource): string
    {
        // URL images always download through Http, even if their descriptor
        // selected direct input. Edits never touch an original file.
        $source = $workspace->stage($this->image, resource: true);
        $this->validate($source);
        $target = $workspace->path.'/'.$name;
        if (! @copy($source, $target) || ! chmod($target, 0600)) {
            throw new MediaException('Cannot prepare a private image copy.', 'staging', 'filesystem');
        }

        try {
            // Transformations run lazily: decoding, editing and encoding all happen in toBytes().
            $bytes = array_reduce($this->edits, fn (Image $image, Transformation $edit): Image => $image->transform($edit), Images::fromPath($target))->toBytes();
        } catch (Throwable) {
            // Image drivers can include private paths and URL credentials in
            // their errors. Do not retain their exception chain.
            throw new MediaException('Cannot edit the watermark image.', 'staging', 'resource');
        }

        if (@file_put_contents($target, $bytes) !== strlen($bytes)) {
            throw new MediaException('Cannot write the prepared image.', 'staging', 'filesystem');
        }

        clearstatcache(true, $target);
        $this->validate($target);
        if (! chmod($target, 0600)) {
            throw new MediaException('Cannot protect the prepared image.', 'staging', 'filesystem');
        }

        return $target;
    }

    private function validate(string $path): void
    {
        $size = @getimagesize($path);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new MediaException('Image preparation requires readable raster image data.', 'staging', 'resource');
        }
    }
}

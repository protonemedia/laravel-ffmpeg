<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
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
    public function __construct(private Media $image, private Closure $prepare)
    {
        if ($image->inputOptions() !== [] || ($image->protocolWhitelist() === 'file' && $image->transportOptions() !== [])) {
            throw new InvalidArgumentException('Image preparation requires a standalone image without input options.');
        }
    }

    public function identity(): string
    {
        return hash('sha256', serialize([$this->image->identity(), spl_object_id($this->prepare)]));
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
        // selected direct input. Callbacks never receive an original file.
        $source = $workspace->stage($this->image, resource: true);
        $this->validate($source);
        $target = $workspace->path.'/'.$name;
        if (! @copy($source, $target) || ! chmod($target, 0600)) {
            throw new MediaException('Cannot prepare a private image copy.', 'staging', 'filesystem');
        }

        try {
            $prepared = ($this->prepare)(Images::fromPath($target));
            // Transformations run lazily, so encoding failures surface here too.
            $bytes = $prepared instanceof Image ? $prepared->toBytes() : null;
        } catch (Throwable) {
            // Image drivers can include private paths and URL credentials in
            // their errors. Do not retain their exception chain.
            throw new MediaException('The image preparation callback failed.', 'staging', 'resource');
        }

        if ($bytes === null) {
            throw new MediaException('The image preparation callback must return an Illuminate\\Image\\Image.', 'staging', 'resource');
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

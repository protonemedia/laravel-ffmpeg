<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use League\Flysystem\WhitespacePathNormalizer;
use LogicException;

/**
 * Configure one output of an export inside its output() callback.
 */
class ExportOutput
{
    use Conditionable;
    use ConfiguresOutput;

    /**
     * The disk-relative destination of the output.
     */
    protected string $path;

    /**
     * Indicates if the output settings are frozen because the export has started.
     */
    protected bool $frozen = false;

    /**
     * Create a new export output instance.
     */
    public function __construct(string $path, string|Filesystem $disk)
    {
        if ($path === '') {
            throw new InvalidArgumentException('The destination must not be empty.');
        }

        if (preg_match('/^(?:[\\\\\/]|[a-z][a-z0-9+.-]*:)/i', $path)) {
            throw new InvalidArgumentException('The destination must be a disk-relative key.');
        }

        $path = (new WhitespacePathNormalizer)->normalizePath($path);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($path === '') {
            throw new InvalidArgumentException('The destination must name a file.');
        }

        if ($extension !== '' && ! preg_match('/^[a-zA-Z0-9]{1,16}$/D', $extension)) {
            throw new InvalidArgumentException('The destination extension must be 1 to 16 letters or numbers.');
        }

        $this->path = $path;
        $this->disk = $disk;
    }

    /**
     * Import the trusted settings produced by outputConfiguration().
     *
     * @internal
     */
    public function configure(array $configuration): static
    {
        $this->assertOutputMutable();
        $properties = array_keys($this->outputConfiguration());

        if (array_diff(array_keys($configuration), $properties) !== []
            || array_diff($properties, array_keys($configuration)) !== []) {
            throw new InvalidArgumentException('Invalid output configuration.');
        }

        foreach ($properties as $property) {
            $this->{$property} = $configuration[$property];
        }

        return $this;
    }

    /**
     * Ensure the output settings may still be changed.
     */
    protected function assertOutputMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('Output settings cannot be changed after the export has started.');
        }
    }

    /**
     * Freeze the output settings once the export starts.
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    /**
     * Get the disk name and path of the destination.
     */
    public function destination(): array
    {
        return ['disk' => is_string($this->disk) ? $this->disk : null, 'path' => $this->path];
    }
}

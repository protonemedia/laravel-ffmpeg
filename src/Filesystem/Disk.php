<?php

namespace ProtoneMedia\LaravelFFMpeg\Filesystem;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\ForwardsCalls;
use League\Flysystem\Filesystem as LeagueFilesystem;
use League\Flysystem\FilesystemAdapter as FlysystemFilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * @mixin \Illuminate\Filesystem\FilesystemAdapter
 */
class Disk
{
    use ForwardsCalls;

    /**
     * @var string|\Illuminate\Contracts\Filesystem\Filesystem
     */
    private $disk;

    /**
     * @var string|null
     */
    private $temporaryDirectory;

    /**
     * @var \Illuminate\Filesystem\FilesystemAdapter|null
     */
    private $filesystemAdapter;

    public function __construct($disk)
    {
        $this->disk = $disk;
    }

    /**
     * Little helper method to instantiate this class.
     */
    public static function make($disk): self
    {
        if ($disk instanceof self) {
            return $disk;
        }

        return new static($disk);
    }

    public static function makeTemporaryDisk(): self
    {
        $filesystemAdapter = app('filesystem')->createLocalDriver([
            'root' => app(TemporaryDirectories::class)->create(),
        ]);

        return new static($filesystemAdapter);
    }

    /**
     * Creates a fresh instance, mostly used to force a new TemporaryDirectory.
     */
    public function cloneDisk(): self
    {
        return new Disk($this->disk);
    }

    /**
     * Creates a new TemporaryDirectory instance if none is set, otherwise
     * it returns the current one.
     */
    public function getTemporaryDirectory(): string
    {
        if ($this->temporaryDirectory) {
            return $this->temporaryDirectory;
        }

        return $this->temporaryDirectory = app(TemporaryDirectories::class)->create();
    }

    /**
     * Creates a Media instance for the given path.
     */
    public function makeMedia(string $path): Media
    {
        return Media::make($this, $path);
    }

    /**
     * Returns the name of the disk. It generates a name if the disk
     * is an instance of Flysystem.
     */
    public function getName(): string
    {
        if (is_string($this->disk)) {
            return $this->disk;
        }

        return get_class($this->getFlysystemAdapter()) . '_' . md5(spl_object_id($this->getFlysystemAdapter()));
    }

    /**
     * Returns the Laravel FilesystemAdapter, initializing if not already set.
     */
    public function getFilesystemAdapter(): FilesystemAdapter
    {
        if ($this->filesystemAdapter) {
            return $this->filesystemAdapter;
        }

        if ($this->disk instanceof Filesystem) {
            return $this->filesystemAdapter = $this->disk;
        }

        return $this->filesystemAdapter = Storage::disk($this->disk);
    }

    /**
     * Returns the underlying Flysystem driver.
     */
    private function getFlysystemDriver(): LeagueFilesystem
    {
        return $this->getFilesystemAdapter()->getDriver();
    }

    /**
     * Returns the Flysystem adapter (e.g., Local, S3).
     */
    private function getFlysystemAdapter(): FlysystemFilesystemAdapter
    {
        return $this->getFilesystemAdapter()->getAdapter();
    }

    /**
     * Returns true if the disk is using a LocalFilesystemAdapter.
     */
    public function isLocalDisk(): bool
    {
        return $this->getFlysystemAdapter() instanceof LocalFilesystemAdapter;
    }

    /**
     * Replaces backward slashes into forward slashes.
     */
    public static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Get the full path for the file at the given "short" path.
     */
    public function path(string $path): string
    {
        $path = $this->getFilesystemAdapter()->path($path);

        return $this->isLocalDisk() ? static::normalizePath($path) : $path;
    }

    /**
     * Check if the file exists on the disk.
     */
    public function fileExists(string $path): bool
    {
        return $this->getFilesystemAdapter()->exists($path);
    }

    /**
     * Deletes the file if it exists.
     */
    public function deleteIfExists(string $path): bool
    {
        return $this->fileExists($path) ? $this->getFilesystemAdapter()->delete($path) : false;
    }

    /**
     * Forwards all calls to Laravel's FilesystemAdapter which will pass
     * dynamic method calls onto Flysystem.
     */
    public function __call($method, $parameters)
    {
        return $this->forwardCallTo($this->getFilesystemAdapter(), $method, $parameters);
    }
}

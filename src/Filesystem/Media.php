<?php

namespace ProtoneMedia\LaravelFFMpeg\Filesystem;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Str;

class Media
{
    use HasInputOptions;

    /**
     * @var \ProtoneMedia\LaravelFFMpeg\Filesystem\Disk
     */
    private $disk;

    /**
     * @var string
     */
    private $path;

    /**
     * @var string
     */
    private $temporaryDirectory;

    public function __construct(Disk $disk, string $path)
    {
        $this->disk = $disk;
        $this->path = $this->resolveTemporaryPath($path);
        $this->makeDirectory();
    }

    /**
     * Resolve and validate temporary paths to prevent incorrect path generation.
     *
     * @param string $path
     * @return string
     */
    private function resolveTemporaryPath(string $path): string
    {
        if (Str::startsWith($path, sys_get_temp_dir())) {
            $resolvedPath = realpath($path);
            if ($resolvedPath === false) {
                throw new \InvalidArgumentException("Invalid temporary path: {$path}");
            }
            return $resolvedPath;
        }
        return $path;
    }

    public static function make($disk, string $path): self
    {
        return new static(Disk::make($disk), $path);
    }

    public function getDisk(): Disk
    {
        return $this->disk;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getDirectory(): ?string
    {
        $directory = rtrim(pathinfo($this->getPath())['dirname'], DIRECTORY_SEPARATOR);

        if ($directory === '.') {
            $directory = '';
        }

        if ($directory) {
            $directory .= DIRECTORY_SEPARATOR;
        }

        return $directory;
    }

    private function makeDirectory(): void
    {
        $disk = $this->getDisk();

        if (! $disk->isLocalDisk()) {
            $disk = $this->temporaryDirectoryDisk();
        }

        $directory = $this->getDirectory();

        if ($disk->has($directory)) {
            return;
        }

        $disk->makeDirectory($directory);
    }

    public function getFilenameWithoutExtension(): string
    {
        return pathinfo($this->getPath())['filename'];
    }

    public function getFilename(): string
    {
        return pathinfo($this->getPath())['basename'];
    }

    private function temporaryDirectoryDisk(): Disk
    {
        return Disk::make($this->temporaryDirectoryAdapter());
    }

    private function temporaryDirectoryAdapter(): FilesystemAdapter
    {
        if (! $this->temporaryDirectory) {
            $this->temporaryDirectory = $this->getDisk()->getTemporaryDirectory();
        }

        return app('filesystem')->createLocalDriver(
            ['root' => $this->temporaryDirectory]
        );
    }

    public function getLocalPath(): string
    {
        $disk = $this->getDisk();
        $path = $this->getPath();

        if ($disk->isLocalDisk()) {
            return $disk->path($path);
        }

        $temporaryDirectoryDisk = $this->temporaryDirectoryDisk();

        if ($disk->exists($path) && ! $temporaryDirectoryDisk->exists($path)) {
            try {
                $temporaryDirectoryDisk->writeStream($path, $disk->readStream($path));
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to copy file to temporary directory: {$e->getMessage()}");
            }
        } elseif (! $disk->exists($path)) {
            throw new \InvalidArgumentException("File does not exist: {$path}");
        }

        return $temporaryDirectoryDisk->path($path);
    }

    public function copyAllFromTemporaryDirectory(?string $visibility = null)
    {
        if (! $this->temporaryDirectory) {
            return $this;
        }

        $temporaryDirectoryDisk = $this->temporaryDirectoryDisk();
        $destinationAdapter = $this->getDisk()->getFilesystemAdapter();

        foreach ($temporaryDirectoryDisk->allFiles() as $path) {
            try {
                $destinationAdapter->writeStream($path, $temporaryDirectoryDisk->readStream($path));

                if ($visibility) {
                    $destinationAdapter->setVisibility($path, $visibility);
                }
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to copy file {$path} from temporary directory: {$e->getMessage()}");
            }
        }

        return $this;
    }

    /**
     * Set the visibility of the media file.
     *
     * @param string $visibility
     * @return $this
     */
    public function setVisibility(string $visibility)
    {
        $this->getDisk()->setVisibility($this->getPath(), $visibility);
        return $this;
    }
}
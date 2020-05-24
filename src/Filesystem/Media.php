<?php

namespace Pbmedia\LaravelFFMpeg\Filesystem;

use Illuminate\Filesystem\FilesystemAdapter;

class Media
{
    /**
     * @var \Pbmedia\LaravelFFMpeg\Filesystem\Disk
     */
    private $disk;

    /**
     * @var string
     */
    private $path;

    /**
     * @var \Spatie\TemporaryDirectory\TemporaryDirectory
     */
    private $temporaryDirectory;

    public function __construct(Disk $disk, string $path)
    {
        $this->disk = $disk;
        $this->path = $path;
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

    public function makeDirectory(): self
    {
        $directory = $this->getDirectory();

        if ($directory === './') {
            return $this;
        }

        $adapter = $this->getDisk()->isLocalDisk()
            ? $this->getDisk()->getFilesystemAdapter()
            : $this->temporaryDirectoryAdapter();

        if (!$adapter->has($directory)) {
            $adapter->makeDirectory($directory);
        }

        return $this;
    }

    public function getFilenameWithoutExtension(): string
    {
        return pathinfo($this->getPath())['filename'];
    }

    public function getFilename(): string
    {
        return pathinfo($this->getPath())['basename'];
    }

    private function temporaryDirectoryAdapter(): FilesystemAdapter
    {
        $this->makeTemporaryDirectory();

        return app('filesystem')->createLocalDriver(
            ['root' => $this->temporaryDirectory->path()]
        );
    }

    private function makeTemporaryDirectory(): self
    {
        if (!$this->temporaryDirectory) {
            $this->temporaryDirectory = $this->getDisk()->getTemporaryDirectory();
        }

        return $this;
    }

    public function getLocalPath(): string
    {
        $disk = $this->getDisk();
        $path = $this->getPath();

        if ($disk->isLocalDisk()) {
            return $disk->path($path);
        }

        if (!$this->temporaryDirectory) {
            $this->makeTemporaryDirectory();

            if ($disk->exists($path)) {
                $this->temporaryDirectoryAdapter()->writeStream($path, $disk->readStream($path));
            }
        }

        return $this->temporaryDirectoryAdapter()->path($path);
    }

    public function copyAllFromTemporaryDirectory(string $visibility = null)
    {
        if (!$this->temporaryDirectory) {
            return $this;
        }

        $temporaryDirectoryAdapter = $this->temporaryDirectoryAdapter();

        $destinationAdapater = $this->getDisk()->getFilesystemAdapter();

        foreach ($temporaryDirectoryAdapter->allFiles() as $path) {
            $destinationAdapater->writeStream($path, $temporaryDirectoryAdapter->readStream($path));

            if ($visibility) {
                $destinationAdapater->setVisibility($path, $visibility);
            }
        }

        return $this;
    }

    public function setVisibility(string $visibility = null)
    {
        $disk = $this->getDisk();

        if ($visibility && $disk->isLocalDisk()) {
            $disk->setVisibility($visibility);
        }

        return $this;
    }
}

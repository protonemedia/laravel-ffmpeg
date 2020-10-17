<?php

namespace ProtoneMedia\LaravelFFMpeg\Filesystem;

use Illuminate\Filesystem\FilesystemAdapter;

class Media
{
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
        $this->path = $path;

        $this->makeDirectory();
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
        $directory = $this->getDirectory();

        $adapter = $this->getDisk()->isLocalDisk()
            ? $this->getDisk()->getFilesystemAdapter()
            : $this->temporaryDirectoryAdapter();

        if ($adapter->has($directory)) {
            return;
        }

        $adapter->makeDirectory($directory);
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
        if (!$this->temporaryDirectory) {
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

        $temporaryDirectoryAdapter = $this->temporaryDirectoryAdapter();

        if ($disk->exists($path) && !$temporaryDirectoryAdapter->exists($path)) {
            $temporaryDirectoryAdapter->writeStream($path, $disk->readStream($path));
        }

        return $temporaryDirectoryAdapter->path($path);
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

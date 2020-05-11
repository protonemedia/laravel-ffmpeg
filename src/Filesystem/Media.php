<?php

namespace Pbmedia\LaravelFFMpeg\Filesystem;

use Illuminate\Filesystem\FilesystemAdapter;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class Media
{
    private Disk $disk;
    private $path;
    private ?TemporaryDirectory $temporaryDirectory = null;

    public function __construct(Disk $disk, $path)
    {
        $this->disk = $disk;
        $this->path = $path;
    }

    public static function make($disk, $path): self
    {
        return new static(Disk::make($disk), $path);
    }

    public function isSingleFile(): bool
    {
        return is_string($this->path);
    }

    public function getDisk(): Disk
    {
        return $this->disk;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    private function temporaryDirectoryAdapter(): FilesystemAdapter
    {
        return app('filesystem')->createLocalDriver(
            ['root' => $this->temporaryDirectory->path()]
        );
    }

    public function getLocalPath(): string
    {
        $disk = $this->getDisk();
        $path = $this->getPath();

        if ($disk->isLocalDisk()) {
            return $disk->path($path);
        }

        if (!$this->temporaryDirectory) {
            $this->temporaryDirectory = (new TemporaryDirectory)->create();

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

    public function cleanup()
    {
        if ($this->temporaryDirectory) {
            $this->temporaryDirectory->delete();
            $this->temporaryDirectory = null;
        }
    }
}

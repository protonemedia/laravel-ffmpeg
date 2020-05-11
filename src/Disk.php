<?php

namespace Pbmedia\LaravelFFMpeg;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Adapter\Local;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class Disk
{
    private $disk;
    private ?TemporaryDirectory $temporaryDirectory = null;

    public function __construct($disk)
    {
        $this->disk = $disk;
    }

    public static function create($disk): self
    {
        if (is_string($disk)) {
            return new static($disk);
        }
    }

    public function getName(): string
    {
        return $this->disk;
    }

    private function adapter(): FilesystemAdapter
    {
        return Storage::disk($this->disk);
    }

    public function setVisibility(string $path, string $visibility): self
    {
        $this->adapter()->setVisibility($path, $visibility);

        return $this;
    }

    public function isLocalDisk(): bool
    {
        return $this->adapter()->getDriver()->getAdapter() instanceof Local;
    }

    public function getLocalPath(string $path): string
    {
        if ($this->isLocalDisk()) {
            return $this->adapter()->path($path);
        }

        if (!$this->temporaryDirectory) {
            $this->temporaryDirectory = (new TemporaryDirectory)->create();

            if ($this->adapter()->exists($path)) {
                $this->temporaryDirectoryAdapter()->writeStream($path, $this->adapter()->readStream($path));
            }
        }

        return $this->temporaryDirectoryAdapter()->path($path);
    }

    public function copyAllFromTemporaryDirectory(string $visibility = null)
    {
        $temporaryDirectoryAdapter = $this->temporaryDirectoryAdapter();

        $destinationAdapater = $this->adapter();

        foreach ($temporaryDirectoryAdapter->allFiles() as $path) {
            $destinationAdapater->writeStream($path, $temporaryDirectoryAdapter->readStream($path));

            if ($visibility) {
                $destinationAdapater->setVisibility($path, $visibility);
            }
        }
    }

    private function temporaryDirectoryAdapter(): FilesystemAdapter
    {
        return app('filesystem')->createLocalDriver(
            ['root' => $this->temporaryDirectory->path()]
        );
    }

    public function __destruct()
    {
        if ($this->temporaryDirectory) {
            $this->temporaryDirectory->delete();
        }
    }
}

<?php

namespace Pbmedia\LaravelFFMpeg\Filesystem;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\ForwardsCalls;
use League\Flysystem\Adapter\Local;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Filesystem;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * @mixin \Illuminate\Filesystem\FilesystemAdapter
 */
class Disk
{
    use ForwardsCalls;

    private $disk;
    private ?TemporaryDirectory $temporaryDirectory = null;
    private ?FilesystemAdapter $filesystemAdapter   = null;

    public function __construct($disk)
    {
        $this->disk = $disk;
    }

    public static function make($disk): self
    {
        if ($disk instanceof self) {
            return $disk;
        }

        if (is_string($disk)) {
            return new static($disk);
        }
    }

    public function getName(): string
    {
        return $this->disk;
    }

    public function getFilesystemAdapter(): FilesystemAdapter
    {
        if ($this->filesystemAdapter) {
            return $this->filesystemAdapter;
        }

        return $this->filesystemAdapter = Storage::disk($this->disk);
    }

    private function getFlysystemDriver(): Filesystem
    {
        return $this->getFilesystemAdapter()->getDriver();
    }

    private function getFlysystemAdapter(): AdapterInterface
    {
        return $this->getFlysystemDriver()->getAdapter();
    }

    public function isLocalDisk(): bool
    {
        return $this->getFlysystemAdapter() instanceof Local;
    }

    public function __call($method, $parameters)
    {
        return $this->forwardCallTo($this->getFilesystemAdapter(), $method, $parameters);
    }
}

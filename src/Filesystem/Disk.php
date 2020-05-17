<?php

namespace Pbmedia\LaravelFFMpeg\Filesystem;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\ForwardsCalls;
use League\Flysystem\Adapter\Local;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Filesystem as LeagueFilesystem;
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

        return new static($disk);
    }

    public function clone(): self
    {
        return new Disk($this->disk);
    }

    public function getTemporaryDirectory(): TemporaryDirectory
    {
        if ($this->temporaryDirectory) {
            return $this->temporaryDirectory;
        }

        return  $this->temporaryDirectory = TemporaryDirectories::create();
    }

    public function makeMedia(string $path): Media
    {
        return Media::make($this, $path);
    }

    public function getName(): string
    {
        if (is_string($this->disk)) {
            return $this->disk;
        }

        return get_class($this->getFlysystemAdapter()) . "_" . md5(json_encode(serialize($this->getFlysystemAdapter())));
    }

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

    private function getFlysystemDriver(): LeagueFilesystem
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

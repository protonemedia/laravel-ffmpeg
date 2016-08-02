<?php

namespace Pbmedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;

class Disk
{
    protected $disk;

    public function __construct(Filesystem $disk)
    {
        $this->disk = $disk;
    }

    public static function fromName(string $name): self
    {
        $adapter = app(Filesystems::class)->disk($name);

        return new static($adapter);
    }

    public function newFile(string $path): File
    {
        return new File($this, $path);
    }

    public function getPath(): string
    {
        return $this->disk->getDriver()->getAdapter()->getPathPrefix();
    }
}

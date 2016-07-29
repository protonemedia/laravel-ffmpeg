<?php

namespace Pbmedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;

class Disk
{
    protected $disk;

    public function __construct(Filesystem $disk)
    {
        $this->disk = $disk;
    }

    public function getFile(string $path): File
    {
        return new File($this, $path);
    }

    public function getPath(): string
    {
        return $this->disk->getDriver()->getAdapter()->getPathPrefix();
    }
}

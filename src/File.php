<?php

namespace Pbmedia\LaravelFFMpeg;

class File
{
    protected $disk;

    protected $path;

    public function __construct(Disk $disk, string $path)
    {
        $this->disk = $disk;
        $this->path = $path;
    }

    public function getDisk(): Disk
    {
        return $this->disk;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getFullPath(): string
    {
        return $this->getDisk()->getPath() . $this->path;
    }
}

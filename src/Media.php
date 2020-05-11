<?php

namespace Pbmedia\LaravelFFMpeg;

class Media
{
    private Disk $disk;
    private $path;

    public function __construct(Disk $disk, $path)
    {
        $this->disk = $disk;
        $this->path = $path;
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

    public function getLocalPath(): string
    {
        return $this->disk->getLocalPath($this->getPath());
    }
}

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

    public function getExtension(): string
    {
        return pathinfo($this->getPath())['extension'];
    }

    public function getFullPath(): string
    {
        return $this->getDisk()->getPath() . $this->getPath();
    }

    public function put($content): bool
    {
        return $this->getDisk()->put($this->getPath(), $content);
    }
}

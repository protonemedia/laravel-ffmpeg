<?php

namespace Pbmedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;

class Disk
{
    protected $disk;

    protected static $filesystems;

    public function __construct(Filesystem $disk)
    {
        $this->disk = $disk;
    }

    public static function fromName(string $name): self
    {
        $adapter = FFMpeg::getFilesystems()->disk($name);

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

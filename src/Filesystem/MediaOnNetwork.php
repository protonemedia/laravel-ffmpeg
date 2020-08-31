<?php

namespace ProtoneMedia\LaravelFFMpeg\Filesystem;

class MediaOnNetwork
{
    /**
     * @var string
     */
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public static function make(string $path): self
    {
        return new static($path);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getDisk(): Disk
    {
        return Disk::make(config('filesystems.default'));
    }

    public function getLocalPath(): string
    {
        return $this->path;
    }

    public function getFilenameWithoutExtension(): string
    {
        return pathinfo($this->getPath())['filename'];
    }

    public function getFilename(): string
    {
        return pathinfo($this->getPath())['basename'];
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Filesystem;

use ProtoneMedia\LaravelFFMpeg\FFMpeg\InteractsWithHttpHeaders;

class MediaOnNetwork
{
    use InteractsWithHttpHeaders;

    /**
     * @var string
     */
    private $path;

    public function __construct(string $path, array $headers = [])
    {
        $this->path    = $path;
        $this->headers = $headers;
    }

    public static function make(string $path, array $headers = []): self
    {
        return new static($path, $headers);
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

    public function getCompiledHeaders(): array
    {
        return static::compileHeaders($this->getHeaders());
    }
}

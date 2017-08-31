<?php

namespace Pbmedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use League\Flysystem\Adapter\Local as LocalAdapater;

/**
 * @method bool put($path, $contents, $visibility = null)
 * @method array|false read($path)
 * @method void setVisibility($path, $visibility)
 */
class Disk
{
    protected $disk;

    protected static $filesystems;

    public function __construct(Filesystem $disk)
    {
        $this->disk = $disk;
    }

    public static function fromName(string $name): Disk
    {
        $adapter = FFMpeg::getFilesystems()->disk($name);

        return new static($adapter);
    }

    public function isLocal(): bool
    {
        $adapter = $this->disk->getDriver()->getAdapter();

        return $adapter instanceof LocalAdapater;
    }

    public function newFile(string $path): File
    {
        return new File($this, $path);
    }

    public function getPath(): string
    {
        return $this->disk->getDriver()->getAdapter()->getPathPrefix();
    }

    public function createDirectory(string $path)
    {
        return $this->disk->makeDirectory($path);
    }

    public function __call($method, $parameters)
    {
        return call_user_func_array([$this->disk, $method], $parameters);
    }
}

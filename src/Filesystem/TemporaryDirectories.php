<?php

namespace Pbmedia\LaravelFFMpeg\Filesystem;

use Spatie\TemporaryDirectory\TemporaryDirectory;

class TemporaryDirectories
{
    public static $directories = [];

    public static function create()
    {
        return static::$directories[] = (new TemporaryDirectory)->create();
    }

    public static function deleteAll()
    {
        foreach (static::$directories as $directory) {
            $directory->delete();
        }

        static::$directories = [];
    }
}

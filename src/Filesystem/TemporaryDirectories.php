<?php

namespace ProtoneMedia\LaravelFFMpeg\Filesystem;

use Spatie\TemporaryDirectory\TemporaryDirectory;

class TemporaryDirectories
{
    public static $directories = [];

    public static function create(): TemporaryDirectory
    {
        return static::$directories[] = (new TemporaryDirectory)->create();
    }

    /**
     * Loop through all directories and delete them.
     */
    public static function deleteAll(): void
    {
        foreach (static::$directories as $directory) {
            $directory->delete();
        }

        static::$directories = [];
    }
}

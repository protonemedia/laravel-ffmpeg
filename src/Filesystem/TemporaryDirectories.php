<?php

namespace ProtoneMedia\LaravelFFMpeg\Filesystem;

use Neutron\TemporaryFilesystem\Manager;

class TemporaryDirectories
{
    public static $manager;

    private static function manager(): Manager
    {
        if (!static::$manager) {
            static::$manager = Manager::create();
        }

        return static::$manager;
    }

    public static function create(): string
    {
        return static::manager()->createTemporaryDirectory();
    }

    /**
     * Loop through all directories and delete them.
     */
    public static function deleteAll(): void
    {
        static::manager()->clean();
    }
}

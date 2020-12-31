<?php

namespace ProtoneMedia\LaravelFFMpeg\Filesystem;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class TemporaryDirectories
{
    private static $directories = [];

    public static function create(): string
    {
        $directory = static::$directories[] = Str::random();

        return storage_path("ffmpeg_temp/{$directory}");
    }

    /**
     * Loop through all directories and delete them.
     */
    public static function deleteAll(): void
    {
        foreach (static::$directories as $directory) {
            File::deleteDirectory(storage_path("ffmpeg_temp/{$directory}"));
        }

        static::$directories = [];
    }
}

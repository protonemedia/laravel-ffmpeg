<?php

namespace ProtoneMedia\LaravelFFMpeg\Filters;

use FFMpeg\Filters\Video\WatermarkFilter as FFMpegWatermarkFilter;

/**
 * Partly based on this PR:
 * https://github.com/PHP-FFMpeg/PHP-FFMpeg/pull/754/files
 */
class WatermarkFilter extends FFMpegWatermarkFilter
{
    protected $path;

    public function __construct($watermarkPath, array $coordinates = [], $priority = 0)
    {
        parent::__construct($watermarkPath, $coordinates, $priority);

        $this->path = $watermarkPath;
    }

    protected function getCommands()
    {
        $commands = parent::getCommands();

        if (!windows_os()) {
            return $commands;
        }

        $commands[1] = str_replace($this->path, static::windowsPath($this->path), $commands[1]);

        return $commands;
    }

    private static function windowsPath(string $path): string
    {
        return '"' . str_replace('/', '\\', $path) . '"';
    }
}

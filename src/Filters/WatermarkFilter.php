<?php

namespace ProtoneMedia\LaravelFFMpeg\Filters;

use FFMpeg\Filters\Video\WatermarkFilter as FFMpegWatermarkFilter;

class WatermarkFilter extends FFMpegWatermarkFilter
{
    protected $path;

    public function __construct($watermarkPath, array $coordinates = [], $priority = 0)
    {
        parent::__construct($watermarkPath, $coordinates, $priority);

        $this->path = $watermarkPath;
    }

    /**
     * Gets the commands from the base filter and normalizes the path.
     *
     * @return array
     */
    protected function getCommands()
    {
        $commands = parent::getCommands();

        $commands[1] = str_replace($this->path, static::normalizePath($this->path), $commands[1]);

        return $commands;
    }

    /**
     * Normalizes the path when running on Windows.
     *
     * @param string $path
     * @return string
     */
    public static function normalizePath(string $path): string
    {
        $path = windows_os() ? static::normalizeWindowsPath($path) : $path;

        return "'{$path}'";
    }

    /**
     * Replaces the slashes and escapes the colon. For some
     * reason, this filter doesn't work on Windows with
     * absolute paths that contain forward slashes.
     *
     * @param string $path
     * @return string
     */
    public static function normalizeWindowsPath(string $path): string
    {
        $path = str_replace('/', '\\', $path);
        $path = str_replace('\\', '\\\\', $path);
        $path = str_replace(':', '\\:', $path);

        return $path;
    }
}

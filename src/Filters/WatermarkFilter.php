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
    protected $wrapInParentheses = false;

    public function __construct($watermarkPath, array $coordinates = [], $priority = 0)
    {
        parent::__construct($watermarkPath, $coordinates, $priority);

        $this->path = $watermarkPath;
    }

    public function wrapInParentheses(): self
    {
        $this->wrapInParentheses = true;

        return $this;
    }

    /**
     * Gets the commands from the base filter and normalizes the path.
     *
     * @return array
     */
    protected function getCommands()
    {
        $commands = parent::getCommands();

        $replace = static::normalizePath($this->path);

        if ($this->wrapInParentheses) {
            $replace = "'{$replace}'";
        }

        $commands[1] = str_replace($this->path, $replace, $commands[1]);

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
        return windows_os() ? static::normalizeWindowsPath($path) : $path;
    }

    /**
     * Replaces the slashes and escapes the colon.
     *
     * @param string $path
     * @return string
     */
    public static function normalizeWindowsPath(string $path): string
    {
        $path = str_replace('/', '\\\\', $path);
        $path = str_replace(':', '\\:', $path);

        return $path;
    }
}

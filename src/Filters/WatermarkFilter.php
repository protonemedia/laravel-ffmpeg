<?php

namespace ProtoneMedia\LaravelFFMpeg\Filters;

use FFMpeg\Filters\Video\WatermarkFilter as FFMpegWatermarkFilter;

/**
 * Partly based on this PR:
 * https://github.com/PHP-FFMpeg/PHP-FFMpeg/pull/754/files
 */
class WatermarkFilter extends FFMpegWatermarkFilter
{
    public function __construct($watermarkPath, array $coordinates = [], $priority = 0)
    {
        parent::__construct($watermarkPath, $coordinates, $priority);

        if (windows_os()) {
            $this->watermarkPath = static::windowsPath($watermarkPath);
        }
    }

    private static function windowsPath(string $path): string
    {
        return '"' . str_replace('/', '\\', $path) . '"';
    }
}

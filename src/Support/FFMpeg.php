<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ProtoneMedia\LaravelFFMpeg\Http\DynamicHLSPlaylist dynamicHLSPlaylist($disk)
 * @method static \ProtoneMedia\LaravelFFMpeg\MediaOpener fromDisk($disk)
 * @method static \ProtoneMedia\LaravelFFMpeg\MediaOpener fromFilesystem(\Illuminate\Contracts\Filesystem\Filesystem $filesystem)
 * @method static \ProtoneMedia\LaravelFFMpeg\MediaOpener open($path)
 * @method static \ProtoneMedia\LaravelFFMpeg\MediaOpener openUrl($path, array $headers = [])
 * @method static \ProtoneMedia\LaravelFFMpeg\MediaOpener cleanupTemporaryFiles()
 *
 * @see \ProtoneMedia\LaravelFFMpeg\MediaOpener
 */
class FFMpeg extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'laravel-ffmpeg';
    }
}

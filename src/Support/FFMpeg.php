<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ProtoneMedia\LaravelFFMpeg\Http\DynamicHLSPlaylist dynamicHLSPlaylist($disk)
 * @method static \ProtoneMedia\LaravelFFMpeg\MediaOpener fromDisk($disk)
 * @method static \ProtoneMedia\LaravelFFMpeg\MediaOpener fromFilesystem(\Illuminate\Contracts\Filesystem\Filesystem $filesystem)
 * @method static \ProtoneMedia\LaravelFFMpeg\MediaOpener open($path)
 * @method static \ProtoneMedia\LaravelFFMpeg\MediaOpener openUrl($path, array $headers = [])
 * @method static \ProtoneMedia\LaravelFFMpeg\MediaOpener openWithInputOptions(string $path, array $options = [])
 * @method static \ProtoneMedia\LaravelFFMpeg\Exporters\MediaExporter export()
 * @method static \ProtoneMedia\LaravelFFMpeg\Exporters\HLSExporter exportForHLS()
 * @method static \ProtoneMedia\LaravelFFMpeg\Exporters\MediaExporter exportTile(callable $withTileFactory)
 * @method static \ProtoneMedia\LaravelFFMpeg\Exporters\MediaExporter exportFramesByAmount(int $amount, ?int $width = null, ?int $height = null, ?int $quality = null)
 * @method static \ProtoneMedia\LaravelFFMpeg\Exporters\MediaExporter exportFramesByInterval(float $interval, ?int $width = null, ?int $height = null, ?int $quality = null)
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

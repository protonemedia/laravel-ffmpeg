<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ProtoneMedia\LaravelFFMpeg\InputSelector disk(string|\Illuminate\Contracts\Filesystem\Filesystem|null $disk = null)
 * @method static \ProtoneMedia\LaravelFFMpeg\Media open(string|\Illuminate\Http\File|\Illuminate\Http\UploadedFile $path, array $inputOptions = [])
 * @method static \ProtoneMedia\LaravelFFMpeg\Media openUrl(string $url, array $headers = [], bool $download = false, array $inputOptions = [])
 *
 * @see MediaOpenerFactory
 */
class FFMpeg extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'laravel-ffmpeg';
    }
}

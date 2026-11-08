<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ProtoneMedia\LaravelFFMpeg\InputSelector disk(string|\Illuminate\Contracts\Filesystem\Filesystem|null $disk = null)
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

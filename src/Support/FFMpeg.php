<?php

namespace Pbmedia\LaravelFFMpeg\Support;

use Illuminate\Support\Facades\Facade;

class FFMpeg extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'laravel-ffmpeg';
    }
}

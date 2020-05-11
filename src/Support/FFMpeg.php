<?php

namespace Pbmedia\LaravelFFMpeg\Support;

use Illuminate\Support\Facades\Facade;

class FFMpeg extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'laravel-ffmpeg';
    }
}

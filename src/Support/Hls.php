<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use Illuminate\Support\Facades\Facade;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPlayback;

/**
 * The facade for protected HLS playback.
 *
 * @method static HlsPlayback define(string $name, callable $resolve, callable $authorize)
 * @method static \Illuminate\Routing\Route routes(string $uri, string $name, string $playback, array $middleware = ['web', 'auth'])
 * @method static string url(string $route, array $parameters = [], ?\DateTimeInterface $expires = null)
 */
class Hls extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return HlsPlayback::class;
    }
}

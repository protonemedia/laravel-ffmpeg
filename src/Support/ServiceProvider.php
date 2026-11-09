<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use ProtoneMedia\LaravelFFMpeg\Console\DoctorCommand;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPlayback;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/config.php' => config_path('laravel-ffmpeg.php'),
            ], 'config');
            $this->commands([DoctorCommand::class]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'laravel-ffmpeg');

        $this->app->singleton('laravel-ffmpeg', fn (): MediaOpenerFactory => new MediaOpenerFactory);
        $this->app->singleton(HlsPlayback::class);
    }
}

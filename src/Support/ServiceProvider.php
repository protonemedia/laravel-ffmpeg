<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/config.php' => config_path('laravel-ffmpeg.php'),
            ], 'config');
            $this->commands([\ProtoneMedia\LaravelFFMpeg\Console\DoctorCommand::class]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'laravel-ffmpeg');

        $this->app->singleton('laravel-ffmpeg', fn (): \ProtoneMedia\LaravelFFMpeg\Support\MediaOpenerFactory => new MediaOpenerFactory);
        $this->app->singleton(\ProtoneMedia\LaravelFFMpeg\Http\HlsPlayback::class);
    }
}

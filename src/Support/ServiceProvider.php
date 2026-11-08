<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/config.php' => config_path('laravel-ffmpeg.php'),
            ], 'config');
            $this->commands([\ProtoneMedia\LaravelFFMpeg\Console\DoctorCommand::class]);
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'laravel-ffmpeg');

        $this->app->singleton('laravel-ffmpeg', fn () => new MediaOpenerFactory);
        $this->app->singleton(\ProtoneMedia\LaravelFFMpeg\Http\HlsPlayback::class);
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use ProtoneMedia\LaravelFFMpeg\Console\DoctorCommand;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPlayback;

/**
 * Register the FFMpeg services, configuration and console commands.
 */
class ServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/config.php' => config_path('laravel-ffmpeg.php'),
            ], 'config');
            $this->commands([DoctorCommand::class]);
        }
    }

    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'laravel-ffmpeg');

        $this->app->singleton('laravel-ffmpeg', fn (): MediaOpenerFactory => new MediaOpenerFactory);
        $this->app->singleton(HlsPlayback::class);
    }
}

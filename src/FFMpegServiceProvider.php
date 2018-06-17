<?php

namespace Pbmedia\LaravelFFMpeg;

use Illuminate\Support\ServiceProvider;
use Pbmedia\LaravelFFMpeg\FFMpeg;

class FFMpegServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = true;
    
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/laravel-ffmpeg.php' => config_path('laravel-ffmpeg.php'),
        ], 'config');
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laravel-ffmpeg.php', 'laravel-ffmpeg');

        $this->app->singleton('laravel-ffmpeg', function ($app) {
            return $app->make(FFMpeg::class);
        });
    }
    
    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['laravel-ffmpeg'];
    }
}

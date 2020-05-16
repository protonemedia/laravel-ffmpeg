<?php

namespace Pbmedia\LaravelFFMpeg\Support;

use FFMpeg\FFMpeg;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Pbmedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use Pbmedia\LaravelFFMpeg\MediaOpener;
use Psr\Log\LoggerInterface;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        /*
         * Optional methods to load your package assets
         */
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'laravel-ffmpeg');
        // $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-ffmpeg');
        // $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/config.php' => config_path('laravel-ffmpeg.php'),
            ], 'config');
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__ . '/../../config/config.php', 'laravel-ffmpeg');

        $this->app->bind(PHPFFMpeg::class, function () {
            $config = $this->app['config'];

            $logger = $config->get('laravel-ffmpeg.enable_logging', true)
                ?app(LoggerInterface::class)
                 :null;

            return new PHPFFMpeg(FFMpeg::create([
                'ffmpeg.binaries'  => $config->get('laravel-ffmpeg.ffmpeg.binaries'),
                'ffmpeg.threads'   => $config->get('laravel-ffmpeg.ffmpeg.threads'),
                'ffprobe.binaries' =>  $config->get('laravel-ffmpeg.ffprobe.threads'),
                'timeout'          =>  $config->get('laravel-ffmpeg.timeout'),
            ], $logger));
        });

        // Register the main class to use with the facade
        $this->app->bind('laravel-ffmpeg', function () {
            return new MediaOpener(
                $this->app['config']->get('filesystems.default'),
                $this->app->make(PHPFFMpeg::class)
            );
        });
    }
}

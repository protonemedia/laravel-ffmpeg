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

            // Publishing the views.
            /*$this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/laravel-ffmpeg'),
            ], 'views');*/

            // Publishing assets.
            /*$this->publishes([
                __DIR__.'/../resources/assets' => public_path('vendor/laravel-ffmpeg'),
            ], 'assets');*/

            // Publishing the translation files.
            /*$this->publishes([
                __DIR__.'/../resources/lang' => resource_path('lang/vendor/laravel-ffmpeg'),
            ], 'lang');*/

            // Registering package commands.
            // $this->commands([]);
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
            $config = [];

            $logger = app(LoggerInterface::class);

            return new PHPFFMpeg(FFMpeg::create($config, $logger));
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

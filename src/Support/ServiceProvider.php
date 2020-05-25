<?php

namespace Pbmedia\LaravelFFMpeg\Support;

use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
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

        $this->app->singleton('laravel-ffmpeg-logger', function () {
            return $this->app['config']->get('laravel-ffmpeg.enable_logging', true)
                ? app(LoggerInterface::class)
                : null;
        });

        $this->app->singleton('laravel-ffmpeg-configuration', function () {
            $config = $this->app['config'];

            return [
                'ffmpeg.binaries'  => $config->get('laravel-ffmpeg.ffmpeg.binaries'),
                'ffmpeg.threads'   => $config->get('laravel-ffmpeg.ffmpeg.threads', 12),
                'ffprobe.binaries' => $config->get('laravel-ffmpeg.ffprobe.binaries'),
                'timeout'          => $config->get('laravel-ffmpeg.timeout'),
            ];
        });

        $this->app->bind(FFProbe::class, function () {
            return FFProbe::create(
                $this->app->make('laravel-ffmpeg-configuration'),
                $this->app->make('laravel-ffmpeg-logger')
            );
        });

        $this->app->bind(FFMpegDriver::class, function () {
            return FFMpegDriver::create(
                $this->app->make('laravel-ffmpeg-logger'),
                $this->app->make('laravel-ffmpeg-configuration')
            );
        });

        $this->app->bind(FFMpeg::class, function () {
            return new FFMpeg(
                $this->app->make(FFMpegDriver::class),
                $this->app->make(FFProbe::class)
            );
        });

        $this->app->bind(PHPFFMpeg::class, function () {
            return new PHPFFMpeg($this->app->make(FFMpeg::class));
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

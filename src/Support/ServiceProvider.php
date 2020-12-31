<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use ProtoneMedia\LaravelFFMpeg\Filesystem\TemporaryDirectories;
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

        $this->app->singleton(FFProbe::class, function () {
            return FFProbe::create(
                $this->app->make('laravel-ffmpeg-configuration'),
                $this->app->make('laravel-ffmpeg-logger')
            );
        });

        $this->app->singleton(FFMpegDriver::class, function () {
            return FFMpegDriver::create(
                $this->app->make('laravel-ffmpeg-logger'),
                $this->app->make('laravel-ffmpeg-configuration')
            );
        });

        $this->app->singleton(FFMpeg::class, function () {
            return new FFMpeg(
                $this->app->make(FFMpegDriver::class),
                $this->app->make(FFProbe::class)
            );
        });

        $this->app->singleton(PHPFFMpeg::class, function () {
            return new PHPFFMpeg($this->app->make(FFMpeg::class));
        });

        $this->app->singleton(TemporaryDirectories::class, function () {
            return new TemporaryDirectories(
                $this->app['config']->get('laravel-ffmpeg.temporary_files_root', sys_get_temp_dir()),
            );
        });

        // Register the main class to use with the facade
        $this->app->singleton('laravel-ffmpeg', function () {
            return new MediaOpenerFactory(
                $this->app['config']->get('filesystems.default'),
                $this->app->make(PHPFFMpeg::class)
            );
        });
    }
}

<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\Memory\MemoryAdapter;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Pbmedia\LaravelFFMpeg\Support\ServiceProvider;
use Twistor\Flysystem\Http\HttpAdapter;

abstract class TestCase extends BaseTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('filesystems.default', 'local');

        $app['config']->set('filesystems.disks', array_merge($app['config']->get('filesystems.disks'), [
            'http' => [
                'driver' => 'http',
            ],
            'memory' => [
                'driver' => 'memory',
            ],
        ]));

        Storage::extend('http', function ($app, $config) {
            return new FilesystemAdapter(
                new FlysystemFilesystem(
                    new HttpAdapter('https://raw.githubusercontent.com/pascalbaljetmedia/laravel-ffmpeg/master/tests/src/')
                )
            );
        });

        Storage::extend('memory', function ($app, $config) {
            return new FilesystemAdapter(
                new FlysystemFilesystem(
                    new MemoryAdapter
                )
            );
        });
    }

    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function fakeLocalVideoFile()
    {
        Storage::fake('local');

        Storage::disk('local')->put('video.mp4', file_get_contents(__DIR__ . '/src/video.mp4'));
    }

    protected function fakeLocalVideoFiles()
    {
        $this->fakeLocalVideoFile();

        Storage::disk('local')->put('video2.mp4', file_get_contents(__DIR__ . '/src/video2.mp4'));
    }
}

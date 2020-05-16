<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\Memory\MemoryAdapter;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Pbmedia\LaravelFFMpeg\Filesystem\TemporaryDirectories;
use Pbmedia\LaravelFFMpeg\Support\ServiceProvider;
use Twistor\Flysystem\Http\HttpAdapter;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TemporaryDirectories::deleteAll();
    }

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

    protected function fakeLocalImageFiles()
    {
        Storage::fake('local');

        $disk = Storage::disk('local');
        $disk->put('feature_0001.png', file_get_contents(__DIR__ . '/src/feature_0001.png'));
        $disk->put('feature_0002.png', file_get_contents(__DIR__ . '/src/feature_0002.png'));
        $disk->put('feature_0003.png', file_get_contents(__DIR__ . '/src/feature_0003.png'));
        $disk->put('feature_0004.png', file_get_contents(__DIR__ . '/src/feature_0001.png'));
        $disk->put('feature_0005.png', file_get_contents(__DIR__ . '/src/feature_0002.png'));
        $disk->put('feature_0006.png', file_get_contents(__DIR__ . '/src/feature_0003.png'));
        $disk->put('feature_0007.png', file_get_contents(__DIR__ . '/src/feature_0001.png'));
        $disk->put('feature_0008.png', file_get_contents(__DIR__ . '/src/feature_0002.png'));
        $disk->put('feature_0009.png', file_get_contents(__DIR__ . '/src/feature_0003.png'));
    }

    protected function fakeLocalVideoFiles()
    {
        $this->fakeLocalVideoFile();

        Storage::disk('local')->put('video2.mp4', file_get_contents(__DIR__ . '/src/video2.mp4'));
    }
}

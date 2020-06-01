<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use FFMpeg\Format\Video\X264;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\Memory\MemoryAdapter;
use Orchestra\Testbench\TestCase as BaseTestCase;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider;
use Twistor\Flysystem\Http\HttpAdapter;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FFMpeg::cleanupTemporaryFiles();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        FFMpeg::cleanupTemporaryFiles();
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

    protected function x264(): X264
    {
        return new X264('libmp3lame');
    }

    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function fakeLocalDisk()
    {
        Storage::fake('local');

        return Storage::disk('local');
    }

    protected function fakeLocalAudioFile()
    {
        $this->fakeLocalDisk();
        $this->addTestFile('guitar.m4a');
    }

    protected function fakeLocalVideoFile()
    {
        $this->fakeLocalDisk();
        $this->addTestFile('video.mp4');
    }

    protected function addTestFile($file)
    {
        Storage::disk('local')->put($file, file_get_contents(__DIR__ . "/src/{$file}"));
    }

    protected function fakeLocalImageFiles()
    {
        $disk = $this->fakeLocalDisk();

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
        $this->addTestFile('video2.mp4');
    }
}

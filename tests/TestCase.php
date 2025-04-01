<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use FFMpeg\Format\Audio\Mp3;
use FFMpeg\Format\Video\X264;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Orchestra\Testbench\TestCase as BaseTestCase;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\StdListener;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FFMpeg::cleanupTemporaryFiles();
    }

    protected function tearDown(): void
    {
        FFMpeg::cleanupTemporaryFiles();

        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('filesystems.default', 'local');

        $app['config']->set('laravel-ffmpeg.ffmpeg.threads', 1);

        $app['config']->set('filesystems.disks', array_merge($app['config']->get('filesystems.disks'), [
            'memory' => [
                'driver' => 'memory',
            ],
        ]));

        Storage::extend('memory', function ($app, $config) {
            $adapter = new InMemoryFilesystemAdapter();

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }

    protected function x264(): X264
    {
        return new X264('libmp3lame');
    }

    protected function Mp3(): Mp3
    {
        return new Mp3('libmp3lame');
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

    protected function fakeLocalLogoFile()
    {
        $this->fakeLocalDisk();
        $this->addTestFile('logo.png');
    }

    protected function addTestFile($file, $disk = 'local')
    {
        Storage::disk($disk)->put($file, file_get_contents(__DIR__ . "/src/{$file}"));
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

    protected function fakeLongLocalVideoFile()
    {
        $this->fakeLocalDisk();
        $this->addTestFile('video3.mp4');
    }

    protected function assertPlaylistPattern(string $playlist, array $patternLines, StdListener $listener = null): self
    {
        $playlist = preg_replace('/\n|\r\n?/', "\n", $playlist);

        $pattern = '/' . implode("\n", $patternLines) . '/';

        $assertMethod = method_exists($this, 'assertMatchesRegularExpression')
            ? 'assertMatchesRegularExpression'
            : 'assertRegExp';

        $this->{$assertMethod}($pattern, $playlist, implode(PHP_EOL . PHP_EOL, [
            "Playlist does not match pattern",
            $listener ? implode(PHP_EOL, $listener->get()->all()) : null,
        ]));

        return $this;
    }
}

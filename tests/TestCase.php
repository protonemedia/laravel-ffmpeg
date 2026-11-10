<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Orchestra\Testbench\TestCase as BaseTestCase;
use ProtoneMedia\LaravelFFMpeg\ProcessResult as MediaProcessResult;
use ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * The temporary directory that is created for each test.
     */
    protected string $root;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Every test gets its own private directory, with the media workspaces in a
        // "workspaces" subdirectory. Tests may keep fixtures and disks next to it,
        // so asserting the workspaces directory is empty proves the cleanup.
        $this->root = sys_get_temp_dir().'/laravel-ffmpeg-tests-'.bin2hex(random_bytes(8));

        mkdir($this->root, 0700);
        mkdir($this->root.'/workspaces', 0700);

        config(['laravel-ffmpeg.temporary_files_root' => $this->root.'/workspaces']);
    }

    /**
     * Clean up the testing environment before the next test.
     */
    protected function tearDown(): void
    {
        if (isset($this->root)) {
            (new Filesystem)->deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    /**
     * Define the environment setup.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('filesystems.default', 'local');

        $app['config']->set('laravel-ffmpeg.ffmpeg.threads', 1);

        $app['config']->set('filesystems.disks', array_merge($app['config']->get('filesystems.disks'), [
            'memory' => [
                'driver' => 'memory',
            ],
        ]));

        Storage::extend('memory', function ($app, $config) {
            $adapter = new InMemoryFilesystemAdapter;

            return new FilesystemAdapter(
                new Flysystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }

    /**
     * Get the package providers.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    /**
     * Point the package at the real FFmpeg 9 binaries and get the FFmpeg path.
     */
    protected function useRealFFMpeg(): string
    {
        // Most tests fake the processes, but some run the real binaries to prove the
        // exact arguments work. Those tests are skipped unless FFMPEG_9_FFPROBE
        // holds the path of an FFprobe 9 binary that has FFmpeg beside it.
        if (! ($ffprobe = getenv('FFMPEG_9_FFPROBE'))) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE to run the tests against real FFmpeg 9.0.x binaries.');
        }

        $ffmpeg = dirname($ffprobe).'/ffmpeg';

        config([
            'laravel-ffmpeg.ffmpeg.binaries' => $ffmpeg,
            'laravel-ffmpeg.ffprobe.binaries' => $ffprobe,
        ]);

        return $ffmpeg;
    }

    /**
     * Replace the local disk with a fake disk.
     */
    protected function fakeLocalDisk(): FilesystemAdapter
    {
        Storage::fake('local');

        return Storage::disk('local');
    }

    /**
     * Copy the given test file to the given disk.
     */
    protected function addTestFile(string $file, string $disk = 'local'): void
    {
        Storage::disk($disk)->put($file, file_get_contents(__DIR__."/src/{$file}"));
    }

    /**
     * Assert that every media workspace has been removed from the temporary directory.
     */
    protected function assertWorkspacesAreCleanedUp(): void
    {
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    /**
     * Assert that the given process finished successfully.
     */
    protected function assertProcessSucceeded(ProcessResult|MediaProcessResult $process): void
    {
        // The error output of FFmpeg explains why it failed, so we use it as the
        // failure message instead of PHPUnit's bare "false is not true".
        $this->assertTrue($process->successful(), $process->errorOutput());
    }
}

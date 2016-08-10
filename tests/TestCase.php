<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Log\Writer;
use League\Flysystem\Adapter\Ftp;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem as Flysystem;
use Mockery;
use Monolog\Logger;
use Pbmedia\LaravelFFMpeg\FFMpeg;

class TestCase extends \PHPUnit_Framework_TestCase
{
    public $srcDir;

    public $tmpDir;

    public $remoteFilesystem;

    public function setUp()
    {
        $this->srcDir = __DIR__ . '/src';
        $this->tmpDir = __DIR__ . '/tmp';

        @mkdir($this->tmpDir);

        $this->remoteFilesystem = false;
    }

    public function tearDown()
    {
        @unlink($this->tmpDir);
    }

    public function getDefaultConfig()
    {
        if (php_uname('s') === "Darwin") {
            return require __DIR__ . '/../config/laravel-ffmpeg-mac-brew.php';
        }

        return require __DIR__ . '/../config/laravel-ffmpeg-ubuntu.php';
    }

    public function getLocalAdapter(): FilesystemAdapter
    {
        $flysystem = new Flysystem(new Local($this->srcDir));

        return new FilesystemAdapter($flysystem);
    }

    public function getFtpAdapter(): FilesystemAdapter
    {
        $flysystem = new Flysystem(new Ftp([]));

        return new FilesystemAdapter($flysystem);
    }

    public function getFilesystems(): Filesystems
    {
        $filesystems = Mockery::mock(Filesystems::class);

        if ($this->remoteFilesystem) {
            $filesystems->shouldReceive('disk')->once()->with('s3')->andReturn($this->getFtpAdapter());

        } else {
            $filesystems->shouldReceive('disk')->once()->with('local')->andReturn($this->getLocalAdapter());
        }

        return $filesystems;
    }

    public function getService(): FFMpeg
    {
        $filesystems = $this->getFilesystems();

        $logger = new Writer(new Logger('ffmpeg'));
        $config = Mockery::mock(ConfigRepository::class);

        $filesystems->shouldReceive('disk')->once()->with('local')->andReturn($this->getLocalAdapter());
        $config->shouldReceive('get')->once()->with('laravel-ffmpeg')->andReturn($this->getDefaultConfig());
        $config->shouldReceive('get')->once()->with('filesystems.default')->andReturn('local');

        return new FFMpeg($filesystems, $config, $logger);
    }

    public function getGuitarMedia()
    {
        $service = $this->getService();
        return $service->open('guitar.m4a');
    }

    public function getVideoMedia()
    {
        $service = $this->getService();
        return $service->open('video.mp4');
    }
}

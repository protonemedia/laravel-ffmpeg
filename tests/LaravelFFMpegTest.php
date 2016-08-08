<?php

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Log\Writer;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem as Flysystem;
use Monolog\Logger;
use Pbmedia\LaravelFFMpeg\Disk;
use Pbmedia\LaravelFFMpeg\FFMpeg;
use Pbmedia\LaravelFFMpeg\File;
use Pbmedia\LaravelFFMpeg\Media;
use Pbmedia\LaravelFFMpeg\MediaExporter;

class LaravelFFMpegTest extends \PHPUnit_Framework_TestCase
{
    private $srcDir;
    private $tempDir;

    public function setUp()
    {
        $this->srcDir  = __DIR__ . '/src';
        $this->tempDir = __DIR__ . '/tmp';

        mkdir($this->tempDir);

        copy(
            $this->srcDir . '/guitar.m4a',
            $this->tempDir . '/guitar.m4a'
        );
    }

    private function getLocalAdapter(): FilesystemAdapter
    {
        $flysystem = new Flysystem(new Local($this->tempDir));

        return new FilesystemAdapter($flysystem);
    }

    public function tearDown()
    {
        if (file_exists($this->tempDir . '/guitar.m4a')) {
            unlink($this->tempDir . '/guitar.m4a');
        }

        if (file_exists($this->tempDir . '/guitar_aac.aac')) {
            @unlink($this->tempDir . '/guitar_aac.aac');
        }

        rmdir($this->tempDir);
    }

    private function getFilesystems(): Filesystems
    {
        $filesystems = Mockery::mock(Filesystems::class);
        $filesystems->shouldReceive('disk')->once()->with('local')->andReturn($this->getLocalAdapter());

        return $filesystems;
    }

    private function getService(): FFMpeg
    {
        $filesystems = $this->getFilesystems();

        $logger = new Writer(new Logger('ffmpeg'));
        $config = Mockery::mock(ConfigRepository::class);

        $defaultConfig = require __DIR__ . '/../config/laravel-ffmpeg-ubuntu.php';

        $filesystems->shouldReceive('disk')->once()->with('local')->andReturn($this->getLocalAdapter());
        $config->shouldReceive('get')->once()->with('laravel-ffmpeg')->andReturn($defaultConfig);
        $config->shouldReceive('get')->once()->with('filesystems.default')->andReturn('local');

        return new FFMpeg($filesystems, $config, $logger);
    }

    public function testInstantiationOfService()
    {
        $service = $this->getService();

        $this->assertInstanceOf(Filesystems::class, $service->getFilesystems());
    }

    private function getGuitarMedia()
    {
        $service = $this->getService();
        return $service->open('guitar.m4a');
    }

    public function testMediaClass()
    {
        $media = $this->getGuitarMedia();

        $this->assertInstanceOf(Media::class, $media);
        $this->assertFalse($media->isFrame());
    }

    public function testFileClass()
    {
        $media = $this->getGuitarMedia();
        $file  = $media->getFile();

        $this->assertInstanceOf(File::class, $file);
        $this->assertEquals($file->getPath(), 'guitar.m4a');
        $this->assertEquals($file->getFullPath(), $this->tempDir . '/guitar.m4a');
    }

    public function testDiskClass()
    {
        $media = $this->getGuitarMedia();
        $file  = $media->getFile();
        $disk  = $file->getDisk();

        $this->assertInstanceOf(Disk::class, $disk);
        $this->assertEquals($disk->getPath(), $this->tempDir . '/');
    }

    public function testExporter()
    {
        $media    = $this->getGuitarMedia();
        $exporter = $media->export();

        $this->assertInstanceOf(MediaExporter::class, $exporter);

        $exporter->inFormat(new \FFMpeg\Format\Audio\Aac)
            ->save('guitar_aac.aac');

        $this->assertTrue(file_exists($this->tempDir . '/guitar_aac.aac'));
        $this->assertEquals(
            file_get_contents($this->tempDir . '/guitar_aac.aac'),
            file_get_contents($this->srcDir . '/guitar_aac.aac')
        );
    }
}

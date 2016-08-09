<?php

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Log\Writer;
use League\Flysystem\Adapter\Ftp;
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

    private $remoteFilesystem;

    public function setUp()
    {
        $this->srcDir           = __DIR__ . '/src';
        $this->remoteFilesystem = false;
    }

    private function getDefaultConfig()
    {
        if (php_uname('s') === "Darwin") {
            return require __DIR__ . '/../config/laravel-ffmpeg-mac-brew.php';
        }

        return require __DIR__ . '/../config/laravel-ffmpeg-ubuntu.php';
    }

    private function getLocalAdapter(): FilesystemAdapter
    {
        $flysystem = new Flysystem(new Local($this->srcDir));

        return new FilesystemAdapter($flysystem);
    }

    private function getFtpAdapter(): FilesystemAdapter
    {
        $flysystem = new Flysystem(new Ftp([]));

        return new FilesystemAdapter($flysystem);
    }

    private function getFilesystems(): Filesystems
    {
        $filesystems = Mockery::mock(Filesystems::class);

        if ($this->remoteFilesystem) {
            $filesystems->shouldReceive('disk')->once()->with('s3')->andReturn($this->getFtpAdapter());

        } else {
            $filesystems->shouldReceive('disk')->once()->with('local')->andReturn($this->getLocalAdapter());
        }

        return $filesystems;
    }

    private function getService(): FFMpeg
    {
        $filesystems = $this->getFilesystems();

        $logger = new Writer(new Logger('ffmpeg'));
        $config = Mockery::mock(ConfigRepository::class);

        $filesystems->shouldReceive('disk')->once()->with('local')->andReturn($this->getLocalAdapter());
        $config->shouldReceive('get')->once()->with('laravel-ffmpeg')->andReturn($this->getDefaultConfig());
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
        $this->assertEquals($file->getFullPath(), $this->srcDir . '/guitar.m4a');
    }

    public function testDiskClass()
    {
        $media = $this->getGuitarMedia();
        $file  = $media->getFile();
        $disk  = $file->getDisk();

        $this->assertInstanceOf(Disk::class, $disk);
        $this->assertEquals($disk->getPath(), $this->srcDir . '/');
    }

    public function testExportingToLocalDisk()
    {
        $media    = $this->getGuitarMedia();
        $exporter = $media->export();

        $this->assertInstanceOf(MediaExporter::class, $exporter);

        $file = $media->getFile();

        $format = new \FFMpeg\Format\Audio\Aac;

        $media = Mockery::mock(Media::class);
        $media->shouldReceive('getFile')->once()->andReturn($file);
        $media->shouldReceive('isFrame')->once()->andReturn(false);

        $media->shouldReceive('save')->once()->withArgs([
            $format, $this->srcDir . '/guitar_aac.aac',
        ]);

        $exporter = new MediaExporter($media);
        $exporter->inFormat($format)->toDisk('local')->save('guitar_aac.aac');
    }

    public function testExportingToRemoteDisk()
    {
        $this->remoteFilesystem = true;

        $exporter = new MediaExporter($this->getGuitarMedia());

        $mockedRemoteDisk = Mockery::mock(Disk::class);
        $remoteFile       = new File($mockedRemoteDisk, 'guitar_aac.aac');

        $mockedRemoteDisk->shouldReceive('isLocal')->once()->andReturn(false);
        $mockedRemoteDisk->shouldReceive('newFile')->once()->withArgs(['guitar_aac.aac'])->andReturn($remoteFile);
        $mockedRemoteDisk->shouldReceive('put')->once()->withAnyArgs([
            $remoteFile->getPath(),
        ])->andReturn(true);

        $format = new \FFMpeg\Format\Audio\Aac;
        $exporter->inFormat($format)->toDisk($mockedRemoteDisk)->save('guitar_aac.aac');
    }

    public function testSettingTheAccuracy()
    {
        $media    = $this->getGuitarMedia();
        $exporter = $media->export();

        $exporter->accurate();
        $this->assertTrue($exporter->getAccuracy());

        $exporter->unaccurate();
        $this->assertFalse($exporter->getAccuracy());
    }
}

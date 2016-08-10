<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Mockery;
use Pbmedia\LaravelFFMpeg\Disk;
use Pbmedia\LaravelFFMpeg\FFMpeg;
use Pbmedia\LaravelFFMpeg\File;
use Pbmedia\LaravelFFMpeg\Media;
use Pbmedia\LaravelFFMpeg\MediaExporter;

class AudioVideoTest extends TestCase
{
    public function testInstantiationOfService()
    {
        $service = $this->getService();

        $this->assertInstanceOf(Filesystems::class, $service->getFilesystems());
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
        $exporter->setTempDir($this->tmpDir);

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
}

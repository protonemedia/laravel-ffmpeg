<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use FFMpeg\Coordinate\Dimension;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\Filters\Audio\SimpleFilter;
use FFMpeg\Filters\Video\ClipFilter;
use FFMpeg\Format\AudioInterface;
use FFMpeg\Media\Audio;
use FFMpeg\Media\Video;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Log\Writer;
use Illuminate\Support\Fluent;
use League\Flysystem\AdapterInterface;
use League\Flysystem\FilesystemInterface;
use Mockery;
use Monolog\Logger;
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

    public function testDurationInSeconds()
    {
        $media = $this->getGuitarMedia();

        $this->assertEquals(4, $media->getDurationInSeconds());
    }

    public function testDurationInMiliseconds()
    {
        $media = $this->getGuitarMedia();

        $this->assertEquals(4053.3330000000001, $media->getDurationInMiliseconds());
    }

    public function testDiskClass()
    {
        $media = $this->getGuitarMedia();
        $file  = $media->getFile();
        $disk  = $file->getDisk();

        $this->assertInstanceOf(Disk::class, $disk);
        $this->assertEquals($disk->getPath(), $this->srcDir . '/');
    }

    public function testAddingAFilterWithAClosure()
    {
        $media = $this->getVideoMedia();

        $this->assertCount(0, $media->getFiltersCollection());

        $media->addFilter(function ($filters) {
            $filters->resize(new Dimension(640, 480));
        });

        $this->assertCount(1, $media->getFiltersCollection());
    }

    public function testAddingAFilterWithAnObject()
    {
        $media = $this->getVideoMedia();

        $this->assertCount(0, $media->getFiltersCollection());

        $media->addFilter(new ClipFilter(TimeCode::fromSeconds(5)));

        $this->assertCount(1, $media->getFiltersCollection());
    }

    public function testAddingASimpleFilterWithStringArguments()
    {
        $media = $this->getVideoMedia();

        $this->assertCount(0, $media->getFiltersCollection());

        $media->addFilter('-i', '0');

        $this->assertCount(1, $media->getFiltersCollection());

        $filter = $media->getFiltersCollection()->getIterator()[0];

        $this->assertInstanceOf(SimpleFilter::class, $filter);

        $this->assertEquals(['-i', 0], $filter->apply(
            Mockery::mock(Audio::class),
            Mockery::mock(AudioInterface::class)
        ));
    }

    public function testAddingASimpleFilterWithArrayArgument()
    {
        $media = $this->getVideoMedia();

        $this->assertCount(0, $media->getFiltersCollection());

        $media->addFilter(['-i', '0']);

        $this->assertCount(1, $media->getFiltersCollection());

        $filter = $media->getFiltersCollection()->getIterator()[0];

        $this->assertInstanceOf(SimpleFilter::class, $filter);

        $this->assertEquals(['-i', 0], $filter->apply(
            Mockery::mock(Audio::class),
            Mockery::mock(AudioInterface::class)
        ));
    }

    public function testMultipleSimpleFiltersCanBeApplied()
    {
        $media = $this->getGuitarMedia()
            ->addFilter('-some_simple')
            ->addFilter('added_filter');

        $media->setFFMpegDriver($driver = Mockery::mock(FFMpegDriver::class));

        $driver->shouldReceive('getConfiguration')->andReturn(new Fluent);
        $driver->shouldReceive('command')->with([
            0  => '-y',
            1  => '-i',
            2  => $this->srcDir . '/guitar.m4a',
            3  => '-some_simple',
            4  => 'added_filter',
            5  => '-threads',
            6  => null,
            7  => '-acodec',
            8  => 'libfdk_aac',
            9  => '-b:a',
            10 => '128k',
            11 => 'example.aac',
        ], false, \Mockery::type('array'));

        $media->save(new \FFMpeg\Format\Audio\Aac, 'example.aac');
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

    public function testOpeningFromRemoteDisk()
    {
        $filesystems = Mockery::mock(Filesystems::class);
        $config      = Mockery::mock(ConfigRepository::class);
        $logger      = new Writer(new Logger('ffmpeg'));

        $adapter = Mockery::mock(AdapterInterface::class);

        $driver = Mockery::mock(FilesystemInterface::class);
        $driver->shouldReceive('getAdapter')->andReturn($adapter);

        $remoteDisk = Mockery::mock(FilesystemAdapter::class);
        $remoteDisk->shouldReceive('getDriver')->andReturn($driver);
        $remoteDisk->shouldReceive('read')->with('remote_guitar.m4a')->andReturn(
            $videoContents = file_get_contents(__DIR__ . '/src/guitar.m4a')
        );

        $localDisk = $this->getLocalAdapter();

        $filesystems->shouldReceive('disk')->once()->with('s3')->andReturn($remoteDisk);
        $filesystems->shouldReceive('disk')->once()->with('local')->andReturn($localDisk);

        $config->shouldReceive('get')->once()->with('laravel-ffmpeg')->andReturn(array_merge($this->getDefaultConfig(), ['default_disk' => 's3']));
        $config->shouldReceive('get')->once()->with('filesystems.default')->andReturn('s3');

        $service = new FFMpeg($filesystems, $config, $logger);
        $media   = $service->open('remote_guitar.m4a');

        $format = new \FFMpeg\Format\Audio\Aac;

        $media->export()
            ->inFormat($format)
            ->toDisk('local')
            ->save('local_guitar.m4a');

        $this->assertFileExists(__DIR__ . '/src/local_guitar.m4a');

        @unlink($this->srcDir . '/local_guitar.m4a');
    }

    public function testExportingToRemoteDisk()
    {
        $this->remoteFilesystem = true;

        $guitarFile = $this->getGuitarMedia()->getFile();

        $baseMedia = Mockery::mock(Video::class);
        $baseMedia->shouldReceive('save')->once();

        $media = new Media($guitarFile, $baseMedia);

        $exporter = new MediaExporter($media);

        $mockedRemoteDisk = Mockery::mock(Disk::class);
        $remoteFile       = new File($mockedRemoteDisk, 'guitar_aac.aac');

        $remoteFileMocked = Mockery::mock(File::class);
        $remoteFileMocked->shouldReceive('getPath')->once()->andReturn($remoteFile->getPath());
        $remoteFileMocked->shouldReceive('getDisk')->once()->andReturn($remoteFile->getDisk());
        $remoteFileMocked->shouldReceive('getExtension')->once()->andReturn($remoteFile->getExtension());
        $remoteFileMocked->shouldReceive('put')->once()->andReturn(true);

        $mockedRemoteDisk->shouldReceive('isLocal')->once()->andReturn(false);
        $mockedRemoteDisk->shouldReceive('newFile')->once()->withArgs(['guitar_aac.aac'])->andReturn($remoteFileMocked);

        $format = new \FFMpeg\Format\Audio\Aac;
        $exporter->inFormat($format)->toDisk($mockedRemoteDisk)->save('guitar_aac.aac');
    }

    public function testExportingToRemoteDiskWithPublicVisibility()
    {
        $this->remoteFilesystem = true;

        $guitarFile = $this->getGuitarMedia()->getFile();

        $baseMedia = Mockery::mock(Video::class);
        $baseMedia->shouldReceive('save')->once();

        $media = new Media($guitarFile, $baseMedia);

        $exporter = new MediaExporter($media);

        $mockedRemoteDisk = Mockery::mock(Disk::class);
        $remoteFile       = new File($mockedRemoteDisk, 'guitar_aac.aac');

        $remoteFileMocked = Mockery::mock(File::class);

        $remoteFileMocked->shouldReceive('getPath')->once()->andReturn($remoteFile->getPath());
        $remoteFileMocked->shouldReceive('getDisk')->once()->andReturn($remoteFile->getDisk());
        $remoteFileMocked->shouldReceive('getExtension')->once()->andReturn($remoteFile->getExtension());
        $remoteFileMocked->shouldReceive('put')->once()->andReturn(true);

        $mockedRemoteDisk->shouldReceive('isLocal')->once()->andReturn(false);
        $mockedRemoteDisk->shouldReceive('newFile')->once()->withArgs(['guitar_aac.aac'])->andReturn($remoteFileMocked);
        $mockedRemoteDisk->shouldReceive('setVisibility')->once()->withArgs(['guitar_aac.aac', Filesystem::VISIBILITY_PUBLIC]);

        $format = new \FFMpeg\Format\Audio\Aac;
        $exporter->inFormat($format)
            ->toDisk($mockedRemoteDisk)
            ->withVisibility(Filesystem::VISIBILITY_PUBLIC)
            ->save('guitar_aac.aac');
    }

    public function testCreatingAndUnlinkingOfTemporaryFiles()
    {
        $newTemporaryFile = FFMpeg::newTemporaryFile();
        file_put_contents($newTemporaryFile, 'test');

        $this->assertFileExists($newTemporaryFile);
        $this->assertEquals('test', file_get_contents($newTemporaryFile));

        $service = $this->getService()->cleanupTemporaryFiles();
        $this->assertFileNotExists($newTemporaryFile);
    }
}

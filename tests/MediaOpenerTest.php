<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use FFMpeg\Media\Video;
use FFMpeg\Media\Waveform;
use Illuminate\Support\Facades\Storage;
use Pbmedia\LaravelFFMpeg\Filesystem\Disk;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;
use Pbmedia\LaravelFFMpeg\Filesystem\MediaCollection;
use Pbmedia\LaravelFFMpeg\MediaOpener;

class MediaOpenerTest extends TestCase
{
    /** @test */
    public function it_can_open_a_single_file_from_the_default_disk()
    {
        $mediaCollection = (new MediaOpener)->open('video.mp4')->get();

        $this->assertInstanceOf(MediaCollection::class, $mediaCollection);
        $this->assertEquals(1, $mediaCollection->count());

        $this->assertInstanceOf(Media::class, $media = $mediaCollection->first());
        $this->assertInstanceOf(Disk::class, $media->getDisk());

        $this->assertEquals('video.mp4', $media->getPath());
        $this->assertEquals('local', $media->getDisk()->getName());
    }

    /** @test */
    public function it_knows_the_duration_of_a_file()
    {
        $this->fakeLocalVideoFile();

        $media = (new MediaOpener)->open('video.mp4');

        $this->assertEquals(5, $media->getDurationInSeconds());
        $this->assertEquals(4720, $media->getDurationInMiliseconds());
    }

    /** @test */
    public function it_can_open_multiple_files_from_the_same_disk()
    {
        $mediaCollection = (new MediaOpener)->open(
            ['video1.mp4', 'video2.mp4']
        )->get();

        $this->assertEquals(2, $mediaCollection->count());

        $this->assertEquals('video1.mp4', $mediaCollection->first()->getPath());
        $this->assertEquals('video2.mp4', $mediaCollection->last()->getPath());

        $this->assertEquals('local', $mediaCollection->first()->getDisk()->getName());
        $this->assertEquals('local', $mediaCollection->last()->getDisk()->getName());
    }

    /** @test */
    public function it_can_open_multiple_files_from_different_disks()
    {
        $mediaCollection = (new MediaOpener)
            ->fromDisk('public')
            ->open('video1.mp4')
            ->fromDisk('local')
            ->open('video2.mp4')
            ->get();

        $this->assertEquals(2, $mediaCollection->count());

        $this->assertEquals('video1.mp4', $mediaCollection->first()->getPath());
        $this->assertEquals('video2.mp4', $mediaCollection->last()->getPath());

        $this->assertEquals('public', $mediaCollection->first()->getDisk()->getName());
        $this->assertEquals('local', $mediaCollection->last()->getDisk()->getName());
    }

    /** @test */
    public function it_downloads_a_remote_file_before_opening()
    {
        $mediaCollection = (new MediaOpener)
            ->fromDisk('http')
            ->open('guitar.m4a')
            ->getDriver()
            ->getMediaCollection();

        $this->assertInstanceOf(MediaCollection::class, $mediaCollection);
        $this->assertEquals(1, $mediaCollection->count());

        $this->assertInstanceOf(Media::class, $media = $mediaCollection->first());
        $this->assertInstanceOf(Disk::class, $media->getDisk());

        $this->assertEquals('guitar.m4a', $media->getPath());
        $this->assertEquals('http', $media->getDisk()->getName());
    }

    /** @test */
    public function it_can_open_a_file_from_a_filesystem_instance()
    {
        $this->fakeLocalVideoFile();

        $media = (new MediaOpener)
            ->fromFilesystem(Storage::disk('local'))
            ->open('video.mp4');

        $this->assertEquals(5, $media->getDurationInSeconds());
        $this->assertStringContainsString('League\Flysystem\Adapter\Local_', $media->get()->first()->getDisk()->getName());
    }

    /** @test */
    public function it_can_access_the_underlying_library_object()
    {
        $this->fakeLocalVideoFile();

        $media = (new MediaOpener)->open('video.mp4');

        $this->assertInstanceOf(Video::class, $media());
        $this->assertInstanceOf(Video::class, $media->getDriver()());

        $this->assertInstanceOf(Waveform::class, $media->waveform());
        $this->assertInstanceOf(Waveform::class, $media->getDriver()->waveform());
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use FFMpeg\Exception\RuntimeException;
use FFMpeg\Media\Video;
use FFMpeg\Media\Waveform;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Filesystem\MediaCollection;
use ProtoneMedia\LaravelFFMpeg\Filesystem\MediaOnNetwork;
use ProtoneMedia\LaravelFFMpeg\Filesystem\TemporaryDirectories;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class MediaOpenerTest extends TestCase
{
    /** @test */
    public function it_can_open_a_single_file_from_the_default_disk()
    {
        $mediaCollection = (new MediaOpener())->open('video.mp4')->get();

        $this->assertInstanceOf(MediaCollection::class, $mediaCollection);
        $this->assertEquals(1, $mediaCollection->count());

        $this->assertInstanceOf(Media::class, $media = $mediaCollection->first());
        $this->assertInstanceOf(Disk::class, $media->getDisk());

        $this->assertEquals('video.mp4', $media->getPath());
        $this->assertEquals('local', $media->getDisk()->getName());
    }

    /** @test */
    public function it_can_open_an_upload_file()
    {
        $file = UploadedFile::fake()->createWithContent('video.mp4', file_get_contents(__DIR__ . "/src/video.mp4"));

        $media = (new MediaOpener())->open($file);

        $this->assertEquals(5, $media->getDurationInSeconds());
    }

    /** @test */
    public function it_knows_the_duration_of_a_file()
    {
        $this->fakeLocalVideoFile();

        $media = (new MediaOpener())->open('video.mp4');

        $this->assertEquals(5, $media->getDurationInSeconds());
        $this->assertEquals(4720, $media->getDurationInMiliseconds());
    }

    /** @test */
    public function it_can_open_multiple_files_from_the_same_disk()
    {
        $mediaCollection = (new MediaOpener())->open(
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
        $mediaCollection = (new MediaOpener())
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
        Storage::disk('memory')->put('guitar.m4a', file_get_contents(__DIR__ . "/src/guitar.m4a"));

        $mediaCollection = (new MediaOpener())
            ->fromDisk('memory')
            ->open('guitar.m4a')
            ->getDriver()
            ->getMediaCollection();

        $this->assertInstanceOf(MediaCollection::class, $mediaCollection);
        $this->assertEquals(1, $mediaCollection->count());

        $this->assertInstanceOf(Media::class, $media = $mediaCollection->first());
        $this->assertInstanceOf(Disk::class, $media->getDisk());

        $this->assertEquals('guitar.m4a', $media->getPath());
        $this->assertEquals('memory', $media->getDisk()->getName());

        $this->assertFileExists($tempPath = $media->getLocalPath());

        app(TemporaryDirectories::class)->deleteAll();

        $this->assertFalse(file_exists($tempPath));
    }

    /** @test */
    public function it_can_open_a_remote_url_without_opening()
    {
        $mediaCollection = (new MediaOpener())
            ->openUrl($url = 'https://raw.githubusercontent.com/protonemedia/laravel-ffmpeg/master/tests/src/guitar.m4a')
            ->getDriver()
            ->getMediaCollection();

        $this->assertInstanceOf(MediaCollection::class, $mediaCollection);
        $this->assertEquals(1, $mediaCollection->count());

        $this->assertInstanceOf(MediaOnNetwork::class, $media = $mediaCollection->first());
        $this->assertEquals($url, $media->getPath());
    }

    /** @test */
    public function it_can_transform_a_media_on_network_object_to_a_media_object()
    {
        $mediaOnNetwerk = MediaOnNetwork::make('https://ffmpeg.protone.media/logo.png', [
            'Authorization' => 'Basic YWRtaW46MTIzNA==',
        ]);

        $media = $mediaOnNetwerk->toMedia(function ($ch) {
            $this->assertNotNull($ch);
        });

        [$width] = getimagesize($media->getLocalPath());

        $this->assertEquals(400, $width);
    }

    /** @test */
    public function it_can_open_a_remote_url_with_headers()
    {
        try {
            (new MediaOpener())->openUrl('https://ffmpeg.protone.media/video.mp4')->getDriver();

            $this->fail('Should have thrown 401 exception');
        } catch (RuntimeException $exception) {
            $this->assertTrue(
                Str::contains($exception->getPrevious()->getMessage(), "HTTP error 401 Unauthorized"),
                $exception->getPrevious()->getMessage()
            );
        }

        $driver = FFMpeg::openUrl('https://ffmpeg.protone.media/video.mp4', [
            'Authorization' => 'Basic YWRtaW46MTIzNA==',
        ])->getDriver();

        $this->assertEquals(5, $driver->getDurationInSeconds());
    }

    /** @test */
    public function it_can_open_a_file_from_a_filesystem_instance()
    {
        $this->fakeLocalVideoFile();

        $media = (new MediaOpener())
            ->fromFilesystem(Storage::disk('local'))
            ->open('video.mp4');

        $this->assertEquals(5, $media->getDurationInSeconds());
        $this->assertStringContainsString('League\Flysystem\Local\LocalFilesystemAdapter_', $media->get()->first()->getDisk()->getName());
    }

    /** @test */
    public function it_can_access_the_underlying_library_object()
    {
        $this->fakeLocalVideoFile();

        $media = (new MediaOpener())->open('video.mp4');

        $this->assertInstanceOf(Video::class, $media());
        $this->assertInstanceOf(Video::class, $media->getDriver()());

        $this->assertInstanceOf(Waveform::class, $media->waveform());
        $this->assertInstanceOf(Waveform::class, $media->getDriver()->waveform());
    }

    /** @test */
    public function it_can_use_the_facade_to_do_two_independant_exports()
    {
        $this->fakeLocalVideoFile();
        $this->addTestFile('guitar.m4a');

        $mediaA = FFMpeg::open('video.mp4');
        $mediaB = FFMpeg::open('guitar.m4a');

        $this->assertCount(1, $mediaA->getDriver()->getMediaCollection()->collection());
        $this->assertCount(1, $mediaB->getDriver()->getMediaCollection()->collection());
    }
}

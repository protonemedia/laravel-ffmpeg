<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use FFMpeg\Format\Video\WMV;
use FFMpeg\Format\Video\X264;
use Illuminate\Support\Facades\Storage;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;
use Pbmedia\LaravelFFMpeg\MediaOpener;
use Pbmedia\LaravelFFMpeg\Support\FFMpeg;

class ExportTest extends TestCase
{
    /** @test */
    public function it_can_export_a_single_media_file()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener)
            ->open('video.mp4')
            ->export()
            ->inFormat(new X264)
            ->save('new_video.mp4');

        $this->assertTrue(Storage::disk('local')->has('new_video.mp4'));
    }

    /** @test */
    public function it_can_chain_multiple_exports()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener)
            ->open('video.mp4')
            ->export()
            ->inFormat(new X264)
            ->save('new_video1.mp4')
            ->export()
            ->inFormat(new X264)
            ->save('new_video2.mp4');

        $this->assertTrue(Storage::disk('local')->has('new_video1.mp4'));
        $this->assertTrue(Storage::disk('local')->has('new_video2.mp4'));
    }

    /** @test */
    public function it_can_export_two_files_into_a_single_file_with_filters()
    {
        $this->fakeLocalVideoFiles();

        FFMpeg::fromDisk('local')
            ->open(['video.mp4', 'video2.mp4'])
            ->export()
            ->addFormatOutputMapping(new X264, Media::make('local', 'new_video1.mp4'), ['0:v', '1:v'])
            ->addFormatOutputMapping(new WMV, Media::make('memory', 'new_video2.wmv'), ['0:v', '1:v'])
            ->save();

        $this->assertTrue(Storage::disk('local')->has('new_video1.mp4'));
        $this->assertTrue(Storage::disk('memory')->has('new_video2.wmv'));
    }

    /** @test */
    public function it_can_export_a_single_media_file_to_an_external_location()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener)
            ->open('video.mp4')
            ->export()
            ->inFormat(new X264)
            ->toDisk('memory')
            ->save('new_video.mp4');

        $this->assertTrue(Storage::disk('memory')->has('new_video.mp4'));
    }
}

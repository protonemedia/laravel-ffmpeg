<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use FFMpeg\Format\Video\X264;
use Illuminate\Support\Facades\Storage;
use Pbmedia\LaravelFFMpeg\MediaOpener;

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

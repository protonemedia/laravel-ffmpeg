<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;

class FrameTest extends TestCase
{
    /** @test */
    public function it_can_only_export_a_frame_from_a_video_file()
    {
        $this->fakeLocalAudioFile();

        try {
            (new MediaOpener)
                ->open('guitar.m4a')
                ->getFrameFromSeconds(2)
                ->export();
        } catch (\Exception $e) {
            return $this->assertTrue(true);
        }

        $this->fail('Should have thrown an exception');
    }
    /** @test */
    public function it_can_export_a_frame_using_seconds()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener)
            ->open('video.mp4')
            ->getFrameFromSeconds(2)
            ->export()
            ->accurate()
            ->save('thumb.png');

        $this->assertTrue(Storage::disk('local')->has('thumb.png'));
    }

    /** @test */
    public function it_can_export_a_frame_as_base64()
    {
        $this->fakeLocalVideoFile();

        $contents = (new MediaOpener)
            ->open('video.mp4')
            ->getFrameFromSeconds(2)
            ->export()
            ->getFrameContents();

        $this->assertIsString($contents);
    }

    /** @test */
    public function it_can_export_a_frame_using_a_string()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener)
            ->open('video.mp4')
            ->getFrameFromString('00:00:03.14')
            ->export()
            ->unaccurate()
            ->save('thumb.png');

        $this->assertTrue(Storage::disk('local')->has('thumb.png'));
    }

    /** @test */
    public function it_can_export_a_frame_using_a_timecode()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener)
            ->open('video.mp4')
            ->getFrameFromTimecode(
                \FFMpeg\Coordinate\TimeCode::fromString('00:00:03.14')
            )
            ->export()
            ->save('thumb.png');

        $this->assertTrue(Storage::disk('local')->has('thumb.png'));
    }
}

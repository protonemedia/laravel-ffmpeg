<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class FrameTest extends TestCase
{
    /** @test */
    public function it_can_only_export_a_frame_from_a_video_file()
    {
        $this->fakeLocalAudioFile();

        try {
            (new MediaOpener())
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

        (new MediaOpener())
            ->open('video.mp4')
            ->getFrameFromSeconds(2)
            ->export()
            ->accurate()
            ->save('thumb.png');

        $this->assertTrue(Storage::disk('local')->has('thumb.png'));
    }

    /** @test */
    public function it_can_loop_through_the_exporter()
    {
        $this->fakeLocalVideoFile();

        $ffmpeg = FFMpeg::open('video.mp4');

        foreach ([1,2,3] as $key => $frame) {
            $ffmpeg = $ffmpeg->getFrameFromSeconds($frame)
                ->export()
                ->save("thumb_{$key}.png");
        }

        $this->assertTrue(Storage::disk('local')->has('thumb_0.png'));
        $this->assertTrue(Storage::disk('local')->has('thumb_1.png'));
        $this->assertTrue(Storage::disk('local')->has('thumb_2.png'));
    }

    /** @test */
    public function it_can_loop_through_the_exporter_with_the_foreach_method()
    {
        $this->fakeLocalVideoFile();

        FFMpeg::open('video.mp4')->each([1, 2, 3], function ($ffmpeg, $timestamp, $key) {
            $ffmpeg->getFrameFromSeconds($timestamp)->export()->save("thumb_{$timestamp}.png");
        });

        $this->assertTrue(Storage::disk('local')->has('thumb_1.png'));
        $this->assertTrue(Storage::disk('local')->has('thumb_2.png'));
        $this->assertTrue(Storage::disk('local')->has('thumb_3.png'));
    }

    /** @test */
    public function it_can_export_a_frame_as_base64()
    {
        $this->fakeLocalVideoFile();

        $contents = (new MediaOpener())
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

        (new MediaOpener())
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

        (new MediaOpener())
            ->open('video.mp4')
            ->getFrameFromTimecode(
                \FFMpeg\Coordinate\TimeCode::fromString('00:00:03.14')
            )
            ->export()
            ->save('thumb.png');

        $this->assertTrue(Storage::disk('local')->has('thumb.png'));
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class BeforeSavingTest extends TestCase
{
    #[Test]
    /** @test */
    public function it_can_edit_the_commands_before_the_underlying_save_method_is_hit()
    {
        $this->fakeLocalVideoFile();

        FFMpeg::open('video.mp4')
            ->export()
            ->beforeSaving(function ($commands) {
                foreach ($commands[0] as $key => $command) {
                    $commands[0][$key] = str_replace('video_1', 'video_2', $command);
                }

                return $commands;
            })
            ->beforeSaving(function ($commands) {
                foreach ($commands[0] as $key => $command) {
                    $commands[0][$key] = str_replace('video_2', 'video_3', $command);
                }

                return $commands;
            })
            ->save('video_1.mp4');

        $this->assertFalse(Storage::disk('local')->has('video_1.mp4'));
        $this->assertFalse(Storage::disk('local')->has('video_2.mp4'));
        $this->assertTrue(Storage::disk('local')->has('video_3.mp4'));
    }

    #[Test]
    /** @test */
    public function it_can_edit_the_commands_before_the_underlying_save_method_is_hit_with_hls()
    {
        $this->fakeLocalVideoFiles();

        $called = 0;

        FFMpeg::open('video.mp4')
            ->exportForHLS()
            ->addFormat($this->x264()->setKiloBitrate(500))
            ->addFormat($this->x264()->setKiloBitrate(1000))
            ->beforeSaving(function ($commands) use (&$called) {
                $called++;

                foreach ($commands as $key => $command) {
                    $commands[$key] = str_replace('video.mp4', 'video_2.mp4', $command);
                }

                return $commands;
            })
            ->beforeSaving(function ($commands) use (&$called) {
                $called++;

                foreach ($commands as $key => $command) {
                    $commands[$key] = str_replace('video_2.mp4', 'video2.mp4', $command);
                }

                Storage::disk('local')->delete('video.mp4');

                return $commands;
            })
            ->save('output.m3u8');

        $this->assertEquals(2, $called);
        $this->assertTrue(Storage::disk('local')->has('output.m3u8'));
    }
}

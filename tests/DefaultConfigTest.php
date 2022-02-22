<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use FFMpeg\Format\Video\X264;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class DefaultConfigTest extends TestCase
{
    /** @test */
    public function it_has_a_default_of_1_threads()
    {
        $this->fakeLocalVideoFile();

        $command = FFMpeg::open('video.mp4')
            ->export()
            ->getCommand('test.mp4');

        $this->assertStringContainsString('-threads 1', $command[0]);
    }

    /** @test */
    public function it_passes_the_default_temporary_root_to_the_underlying_driver()
    {
        $this->fakeLocalVideoFile();

        $command = FFMpeg::open('video.mp4')
            ->export()
            ->inFormat(new X264)
            ->getCommand('test.mp4');

        $this->assertStringContainsString('-passlogfile ' . sys_get_temp_dir(), $command[0]);
    }
}

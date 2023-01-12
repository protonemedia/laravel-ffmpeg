<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use FFMpeg\Format\Video\X264;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class NoThreadsConfigTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('laravel-ffmpeg.ffmpeg.threads', false);
        $app['config']->set('laravel-ffmpeg.temporary_files_root', sys_get_temp_dir() . '/laravel-custom-temp');
    }

    /** @test */
    public function it_can_disable_the_threads_by_setting_it_to_false()
    {
        $this->fakeLocalVideoFile();

        $command = FFMpeg::open('video.mp4')
            ->export()
            ->getCommand('test.mp4');

        $this->assertStringNotContainsString('-threads', $command[0]);
    }

    /** @test */
    public function it_passes_the_default_temporary_root_to_the_underlying_driver()
    {
        $this->fakeLocalVideoFile();

        $command = FFMpeg::open('video.mp4')
            ->export()
            ->inFormat(new X264())
            ->getCommand('test.mp4');

        $this->assertStringContainsString('/laravel-custom-temp', $command[0]);
    }
}

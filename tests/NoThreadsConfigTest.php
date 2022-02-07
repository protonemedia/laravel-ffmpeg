<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class NoThreadsConfigTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('laravel-ffmpeg.ffmpeg.threads', false);
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
}

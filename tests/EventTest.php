<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Support\Facades\Event;
use ProtoneMedia\LaravelFFMpeg\Events\MediaProcessingCompleted;
use ProtoneMedia\LaravelFFMpeg\Events\MediaProcessingStarted;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;

class EventTest extends TestCase
{
    /** @test */
    public function test_events_are_fired_during_media_processing()
    {
        $this->fakeLocalVideoFile();

        Event::fake();

        (new MediaOpener)
            ->open('video.mp4')
            ->export()
            ->inFormat($this->x264())
            ->save('new_video.mp4');

        Event::assertDispatched(MediaProcessingStarted::class, function ($event) {
            return $event->outputPath === 'new_video.mp4';
        });

        Event::assertDispatched(MediaProcessingCompleted::class, function ($event) {
            return $event->outputPath === 'new_video.mp4';
        });
    }

    /** @test */
    public function test_events_can_be_disabled_via_config()
    {
        $this->fakeLocalVideoFile();

        config(['laravel-ffmpeg.enable_events' => false]);

        Event::fake();

        (new MediaOpener)
            ->open('video.mp4')
            ->export()
            ->inFormat($this->x264())
            ->save('new_video.mp4');

        Event::assertNotDispatched(MediaProcessingStarted::class);
        Event::assertNotDispatched(MediaProcessingCompleted::class);
    }
}
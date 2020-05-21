<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Filters\Video\ClipFilter;
use FFMpeg\Format\Video\WMV;
use FFMpeg\Format\Video\X264;
use Illuminate\Support\Facades\Storage;
use Pbmedia\LaravelFFMpeg\FFMpeg\ProgressListenerDecorator;
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
    public function it_can_bind_a_progress_listener_to_the_format()
    {
        $this->fakeLocalVideoFile();

        $percentages = [];

        $format = new X264;
        $format->on('progress', function ($video, $format, $percentage) use (&$percentages) {
            $percentages[] = $percentage;
        });

        (new MediaOpener)
            ->open('video.mp4')
            ->export()
            ->inFormat($format)
            ->save('new_video.mp4');

        $this->assertNotEmpty($percentages);
    }

    /** @test */
    public function it_can_decorate_the_format_to_get_access_to_the_progress_listener()
    {
        $this->fakeLocalVideoFile();

        $times = [];

        $decoratedFormat = ProgressListenerDecorator::decorate(new X264);

        $decoratedFormat->on('progress', function () use ($decoratedFormat, &$times) {
            $listeners = $decoratedFormat->getListeners();
            $times[] = $listeners[0]->getCurrentTime();
        });

        (new MediaOpener)
            ->open('video.mp4')
            ->export()
            ->inFormat($decoratedFormat)
            ->save('new_video.mp4');

        $this->assertNotEmpty($times);
    }

    /** @test */
    public function it_can_bind_a_dedicated_progress_listener_to_the_exporter()
    {
        $this->fakeLocalVideoFile();

        $percentages = [];

        (new MediaOpener)
            ->open('video.mp4')
            ->export()
            ->onProgress(function ($percentage) use (&$percentages) {
                $percentages[] = $percentage;
            })
            ->inFormat(new X264)
            ->save('new_video.mp4');

        $this->assertNotEmpty($percentages);
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
    public function it_can_export_a_with_a_single_filter()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener)
            ->open('video.mp4')
            ->addFilter(new ClipFilter(TimeCode::fromSeconds(1), TimeCode::fromSeconds(2)))
            ->export()
            ->inFormat(new X264)
            ->save('new_video.mp4');

        $this->assertTrue(Storage::disk('local')->has('new_video.mp4'));
        $this->assertEquals(2, (new MediaOpener)->open('new_video.mp4')->getDurationInSeconds());
    }

    /** @test */
    public function it_can_add_the_filter_after_calling_the_export_method()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener)
            ->open('video.mp4')
            ->export()
            ->addFilter(new ClipFilter(TimeCode::fromSeconds(1), TimeCode::fromSeconds(2)))
            ->inFormat(new X264)
            ->save('new_video.mp4');

        $this->assertTrue(Storage::disk('local')->has('new_video.mp4'));
        $this->assertEquals(2, (new MediaOpener)->open('new_video.mp4')->getDurationInSeconds());
    }

    /** @test */
    public function it_doesnt_migrate_filters_from_a_previous_export()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener)
            ->open('video.mp4')
            ->addFilter(new ClipFilter(TimeCode::fromSeconds(1), TimeCode::fromSeconds(2)))
            ->export()
            ->inFormat(new X264)
            ->save('short_video.mp4')

            ->export()
            ->inFormat(new X264)
            ->save('long_video.mp4');

        $this->assertTrue(Storage::disk('local')->has('short_video.mp4'));
        $this->assertEquals(2, (new MediaOpener)->open('short_video.mp4')->getDurationInSeconds());

        $this->assertTrue(Storage::disk('local')->has('long_video.mp4'));
        $this->assertEquals(5, (new MediaOpener)->open('long_video.mp4')->getDurationInSeconds());
    }

    /** @test */
    public function it_can_export_two_files_into_a_two_files_with_filters_and_a_progress_listener()
    {
        $this->fakeLocalVideoFiles();

        $percentages = [];

        FFMpeg::fromDisk('local')
            ->open(['video.mp4', 'video2.mp4'])
            ->export()
            ->onProgress(function ($percentage) use (&$percentages) {
                $percentages[] = $percentage;
            })
            ->addFormatOutputMapping(new X264, Media::make('local', 'new_video1.mp4'), ['0:v', '1:v'])
            ->addFormatOutputMapping(new WMV, Media::make('memory', 'new_video2.wmv'), ['0:v', '1:v'])
            ->save();

        $this->assertNotEmpty($percentages);

        $this->assertTrue(Storage::disk('local')->has('new_video1.mp4'));
        $this->assertTrue(Storage::disk('memory')->has('new_video2.wmv'));
    }

    /** @test */
    public function it_can_stack_two_videos_horizontally()
    {
        $this->fakeLocalVideoFiles();

        FFMpeg::fromDisk('local')
            ->open(['video.mp4', 'video2.mp4'])
            ->export()
            ->addFilter('[0:v][1:v]', 'hstack', '[v]')
            ->addFormatOutputMapping(new X264, Media::make('local', 'new_video.mp4'), ['[v]'])
            ->save();

        $this->assertTrue(Storage::disk('local')->has('new_video.mp4'));

        $this->assertEquals(
            3840,
            (new MediaOpener)->fromDisk('local')->open('new_video.mp4')->getStreams()[0]->get('width')
        );
    }

    /** @test */
    public function it_can_mix_audio_and_video_files()
    {
        $this->fakeLocalVideoFile();
        $this->addTestFile('guitar.m4a');

        FFMpeg::fromDisk('local')
            ->open(['video.mp4','guitar.m4a'])
            ->export()
            ->addFormatOutputMapping(new X264('libmp3lame'), Media::make('local', 'new_video.mp4'), ['0:v', '1:a'])
            ->save();

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

    /** @test */
    public function it_can_create_a_timelapse_from_images()
    {
        $this->fakeLocalImageFiles();

        (new MediaOpener)
            ->open('feature_%04d.png')
            ->export()
            ->asTimelapseWithFramerate(1)
            ->inFormat(new X264)
            ->save('timelapse.mp4');

        $this->assertTrue(Storage::disk('local')->has('timelapse.mp4'));

        $this->assertEquals(
            9,
            (new MediaOpener)->fromDisk('local')->open('timelapse.mp4')->getDurationInSeconds()
        );
    }

    /** @test */
    public function it_can_concatenate_two_videos_using_the_concat_method()
    {
        $this->fakeLocalVideoFiles();

        FFMpeg::fromDisk('local')
            ->open(['video.mp4', 'video2.mp4'])
            ->export()
            ->concatWithoutTranscoding()
            ->save('concat.mp4');

        $this->assertTrue(Storage::disk('local')->has('concat.mp4'));

        $media = (new MediaOpener)->fromDisk('local')->open('concat.mp4');

        $this->assertEquals(
            7,
            $media->getDurationInSeconds()
        );

        $this->assertEquals(
            1920,
            $media->getStreams()[0]->get('width')
        );
    }

    /** @test */
    public function it_can_concatenate_two_videos_using_a_complex_filter()
    {
        $this->fakeLocalVideoFiles();

        FFMpeg::fromDisk('local')
            ->open(['video.mp4', 'video2.mp4'])
            ->export()
            ->concatWithTranscoding(true, false)
            ->inFormat(new X264)
            ->save('concat.mp4');

        $this->assertTrue(Storage::disk('local')->has('concat.mp4'));

        $media = (new MediaOpener)->fromDisk('local')->open('concat.mp4');

        $this->assertEquals(
            7,
            $media->getDurationInSeconds()
        );

        $this->assertEquals(
            1920,
            $media->getStreams()[0]->get('width')
        );
    }
}

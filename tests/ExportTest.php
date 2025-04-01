<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Exception\RuntimeException ;
use FFMpeg\Filters\Video\ClipFilter;
use FFMpeg\Format\Audio\Mp3;
use FFMpeg\Format\Video\WMV;
use FFMpeg\Format\Video\X264;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Exporters\EncodingException;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\ProgressListenerDecorator;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class ExportTest extends TestCase
{
    /** @test */
    public function it_can_export_a_single_video_file()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener())
            ->open('video.mp4')
            ->export()
            ->inFormat($this->x264())
            ->save('new_video.mp4');

        $this->assertTrue(Storage::disk('local')->has('new_video.mp4'));
    }

    /** @test */
    public function it_can_export_a_single_remote_video_file()
    {
        $this->fakeLocalVideoFile();

        FFMpeg::openUrl('https://ffmpeg.protone.media/video.mp4', [
            'Authorization' => 'Basic YWRtaW46MTIzNA==',
        ])
            ->export()
            ->inFormat($this->x264())
            ->save('new_video.mp4');

        $this->assertTrue(Storage::disk('local')->has('new_video.mp4'));
    }

    /** @test */
    public function it_can_export_a_single_audio_file()
    {
        $this->fakeLocalAudioFile();

        (new MediaOpener())
            ->open('guitar.m4a')
            ->export()
            ->inFormat(new Mp3())
            ->save('guitar.mp3');

        $this->assertTrue(Storage::disk('local')->has('guitar.mp3'));
    }

    /** @test */
    public function it_decorates_the_original_exception()
    {
        $this->fakeLocalVideoFile();

        config(['laravel-ffmpeg.set_command_and_error_output_on_exception' => false]);

        try {
            (new MediaOpener())
                ->open('video.mp4')
                ->export()
                ->inFormat(new X264('libfaac'))
                ->save('new_video.mp4');
        } catch (EncodingException $exception) {
            $this->assertNotEmpty($exception->getCommand());
            $this->assertNotEmpty($exception->getErrorOutput());

            $this->assertEquals('Encoding failed', $exception->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $exception);

            //

            config(['laravel-ffmpeg.set_command_and_error_output_on_exception' => true]);

            $exception = EncodingException::decorate($exception);
            $this->assertStringContainsString('failed to execute command', $exception->getMessage());

            return;
        }

        $this->fail('Should have thrown exception.');
    }

    /** @test */
    public function it_can_bind_a_progress_listener_to_the_format()
    {
        $this->fakeLocalVideoFile();

        $percentages = [];

        $format = $this->x264();
        $format->on('progress', function ($video, $format, $percentage) use (&$percentages) {
            $percentages[] = $percentage;
        });

        (new MediaOpener())
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

        $decoratedFormat = ProgressListenerDecorator::decorate($this->x264());

        (new MediaOpener())
            ->open('video.mp4')
            ->export()
            ->inFormat($decoratedFormat)
            ->onProgress(function () use ($decoratedFormat, &$times) {
                $listeners = $decoratedFormat->getListeners();
                $times[] = $listeners[0]->getCurrentTime();
            })
            ->save('new_video.mp4');

        $this->assertNotEmpty($times);
    }

    /** @test */
    public function it_can_bind_a_dedicated_progress_listener_to_the_exporter()
    {
        $this->fakeLocalVideoFile();

        $percentages = [];
        $remainings  = [];
        $rates       = [];

        (new MediaOpener())
            ->open('video.mp4')
            ->export()
            ->onProgress(function ($percentage, $remaining, $rate) use (&$percentages, &$remainings, &$rates) {
                $percentages[] = $percentage;
                $remainings[] = $remaining;
                $rates[] = $rate;
            })
            ->inFormat($this->x264())
            ->save('new_video.mp4');

        $this->assertNotEmpty($percentages);
        $this->assertNotEmpty($remainings);
        $this->assertNotEmpty($rates);
    }

    /** @test */
    public function it_can_only_bind_one_progress_listener_to_the_exporter()
    {
        $this->fakeLocalVideoFile();

        $percentages = [];

        $format = $this->x264();

        foreach ([0,1] as $i) {
            (new MediaOpener())
                ->open('video.mp4')
                ->export()
                ->onProgress(function ($percentage) use (&$percentages, $i) {
                    $percentages[$i][] = $percentage;
                })
                ->inFormat($format)
                ->save('new_video.mp4');
        }

        $firstListener = $percentages[0];

        $completeKey = array_search(100, $firstListener);

        $this->assertEquals(count($firstListener), $completeKey + 1);
    }

    /** @test */
    public function it_can_chain_multiple_exports()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener())
            ->open('video.mp4')
            ->export()
            ->inFormat($this->x264())
            ->save('new_video1.mp4')
            ->export()
            ->inFormat($this->x264())
            ->save('new_video2.mp4');

        $this->assertTrue(Storage::disk('local')->has('new_video1.mp4'));
        $this->assertTrue(Storage::disk('local')->has('new_video2.mp4'));
    }

    /** @test */
    public function it_can_chain_multiple_exports_using_the_each_method()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener())
            ->open('video.mp4')
            ->each(['new_video1.mp4', 'new_video2.mp4'], function ($ffmpeg, $filename) {
                $ffmpeg->export()->inFormat($this->x264())->save($filename);
            });

        $this->assertTrue(Storage::disk('local')->has('new_video1.mp4'));
        $this->assertTrue(Storage::disk('local')->has('new_video2.mp4'));
    }

    /** @test */
    public function it_can_chain_multiple_exports_using_the_each_method_on_an_external_disk()
    {
        $this->addTestFile('video.mp4', 'memory');

        (new MediaOpener())
            ->fromDisk('memory')
            ->open('video.mp4')
            ->each([1, 2, 3], function (MediaOpener $ffmpeg, $seconds, $key) {
                $ffmpeg->getFrameFromSeconds($seconds)->export()->save("thumb_{$key}.png");
            });

        $this->assertTrue(Storage::disk('memory')->has('thumb_0.png'));
        $this->assertTrue(Storage::disk('memory')->has('thumb_1.png'));
        $this->assertTrue(Storage::disk('memory')->has('thumb_2.png'));
    }

    /** @test */
    public function it_can_export_a_with_a_single_filter()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener())
            ->open('video.mp4')
            ->addFilter(new ClipFilter(TimeCode::fromSeconds(1), TimeCode::fromSeconds(2)))
            ->export()
            ->inFormat($this->x264())
            ->save('new_video.mp4');

        $this->assertTrue(Storage::disk('local')->has('new_video.mp4'));
        $this->assertEquals(2, (new MediaOpener())->open('new_video.mp4')->getDurationInSeconds());
    }

    /** @test */
    public function it_can_add_the_filter_after_calling_the_export_method()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener())
            ->open('video.mp4')
            ->export()
            ->addFilter(new ClipFilter(TimeCode::fromSeconds(1), TimeCode::fromSeconds(2)))
            ->inFormat($this->x264())
            ->save('new_video.mp4');

        $this->assertTrue(Storage::disk('local')->has('new_video.mp4'));
        $this->assertEquals(2, (new MediaOpener())->open('new_video.mp4')->getDurationInSeconds());
    }

    /** @test */
    public function it_doesnt_migrate_filters_from_a_previous_export()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener())
            ->open('video.mp4')
            ->addFilter(new ClipFilter(TimeCode::fromSeconds(1), TimeCode::fromSeconds(2)))
            ->export()
            ->inFormat($this->x264())
            ->save('short_video.mp4')

            ->export()
            ->inFormat($this->x264())
            ->save('long_video.mp4');

        $this->assertTrue(Storage::disk('local')->has('short_video.mp4'));
        $this->assertEquals(2, (new MediaOpener())->open('short_video.mp4')->getDurationInSeconds());

        $this->assertTrue(Storage::disk('local')->has('long_video.mp4'));
        $this->assertEquals(5, (new MediaOpener())->open('long_video.mp4')->getDurationInSeconds());
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
            ->addFormatOutputMapping($this->x264(), Media::make('local', 'new_video1.mp4'), ['0:v', '1:v'])
            ->addFormatOutputMapping(new WMV(), Media::make('memory', 'new_video2.wmv'), ['0:v', '1:v'])
            ->save();

        $this->assertNotEmpty($percentages);

        $this->assertTrue(Storage::disk('local')->has('new_video1.mp4'));
        $this->assertTrue(Storage::disk('memory')->has('new_video2.wmv'));
    }

    /** @test */
    public function it_can_merge_two_remote_video_files_with_the_same_headers()
    {
        $this->fakeLocalVideoFile();

        FFMpeg::openUrl([
            'https://ffmpeg.protone.media/video.mp4',
            'https://ffmpeg.protone.media/video.mp4',
        ], [
            'Authorization' => 'Basic YWRtaW46MTIzNA==',
        ])
            ->export()
            ->addFilter('[0:v][1:v]', 'hstack', '[v]')
            ->addFormatOutputMapping($this->x264(), Media::make('local', 'new_video.mp4'), ['[v]'])
            ->save();

        $this->assertTrue(Storage::disk('local')->has('new_video.mp4'));

        $this->assertEquals(
            3840,
            (new MediaOpener())->fromDisk('local')->open('new_video.mp4')->getStreams()[0]->get('width')
        );
    }

    /** @test */
    public function it_can_merge_two_remote_video_files_with_different_headers()
    {
        $this->fakeLocalVideoFile();

        FFMpeg::new()
            ->openUrl('https://ffmpeg.protone.media/video.mp4', [
                'Authorization' => 'Basic YWRtaW46MTIzNA==',
            ])->openUrl('https://ffmpeg.protone.media/video2.mp4', [
                'Authorization' => 'Basic YWRtaW46NDMyMQ==',
            ])
            ->export()
            ->addFilter('[0:v][1:v]', 'hstack', '[v]')
            ->addFormatOutputMapping($this->x264(), Media::make('local', 'new_video.mp4'), ['[v]'])
            ->save();

        $this->assertTrue(Storage::disk('local')->has('new_video.mp4'));

        $this->assertEquals(
            3840,
            (new MediaOpener())->fromDisk('local')->open('new_video.mp4')->getStreams()[0]->get('width')
        );
    }

    /** @test */
    public function it_can_stack_two_videos_horizontally()
    {
        $this->fakeLocalVideoFiles();

        FFMpeg::fromDisk('local')
            ->open(['video.mp4', 'video2.mp4'])
            ->export()
            ->addFilter('[0:v][1:v]', 'hstack', '[v]')
            ->addFormatOutputMapping($this->x264(), Media::make('local', 'new_video.mp4'), ['[v]'])
            ->save();

        $this->assertTrue(Storage::disk('local')->has('new_video.mp4'));

        $this->assertEquals(
            3840,
            (new MediaOpener())->fromDisk('local')->open('new_video.mp4')->getStreams()[0]->get('width')
        );
    }

    /** @test */
    public function it_can_mix_audio_and_video_files()
    {
        $this->fakeLocalVideoFile();
        $this->addTestFile('video_no_audio.mp4');
        $this->addTestFile('guitar.m4a');

        FFMpeg::fromDisk('local')
            ->open(['video.mp4','guitar.m4a'])
            ->export()
            ->addFormatOutputMapping($this->x264(), Media::make('local', 'new_video.mp4'), ['0:v', '1:a'])
            ->save();

        $this->assertTrue(Storage::disk('local')->has('new_video.mp4'));
    }

    /** @test */
    public function it_can_export_a_single_media_file_to_an_external_location()
    {
        $this->fakeLocalVideoFile();

        (new MediaOpener())
            ->open('video.mp4')
            ->export()
            ->inFormat($this->x264())
            ->toDisk('memory')
            ->save('new_video.mp4');

        $this->assertTrue(Storage::disk('memory')->has('new_video.mp4'));
    }

    /** @test */
    public function it_can_create_a_timelapse_from_images()
    {
        $this->fakeLocalImageFiles();

        (new MediaOpener())
            ->open('feature_%04d.png')
            ->export()
            ->asTimelapseWithFramerate(1)
            ->inFormat($this->x264())
            ->save('timelapse.mp4');

        $this->assertTrue(Storage::disk('local')->has('timelapse.mp4'));

        $this->assertEquals(
            9,
            (new MediaOpener())->fromDisk('local')->open('timelapse.mp4')->getDurationInSeconds()
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

        $media = (new MediaOpener())->fromDisk('local')->open('concat.mp4');

        $this->assertEquals(
            9,
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
            ->inFormat($this->x264())
            ->save('concat.mp4');

        $this->assertTrue(Storage::disk('local')->has('concat.mp4'));

        $media = (new MediaOpener())->fromDisk('local')->open('concat.mp4');

        $this->assertEquals(
            9,
            $media->getDurationInSeconds()
        );

        $this->assertEquals(
            1920,
            $media->getStreams()[0]->get('width')
        );
    }
}

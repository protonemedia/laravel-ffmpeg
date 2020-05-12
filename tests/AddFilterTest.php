<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use FFMpeg\Filters\AdvancedMedia\ComplexFilters;
use FFMpeg\Filters\Audio\SimpleFilter;
use FFMpeg\Filters\Video\VideoFilters;
use FFMpeg\Format\AudioInterface;
use FFMpeg\Format\Video\X264;
use FFMpeg\Media\Audio;
use Illuminate\Support\Arr;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;
use Pbmedia\LaravelFFMpeg\MediaOpener;

class AddFilter extends TestCase
{
    /** @test */
    public function it_can_add_a_filter_using_a_closure()
    {
        $this->fakeLocalVideoFile();

        $media = (new MediaOpener)->open('video.mp4');

        $this->assertCount(0, $media->getFilters());

        $media->addFilter(function ($filters) {
            $this->assertInstanceOf(VideoFilters::class, $filters);
            $filters->resize(new \FFMpeg\Coordinate\Dimension(640, 480));
        });

        $this->assertCount(1, $media->getFilters());
    }

    /** @test */
    public function it_can_add_a_filter_using_an_object()
    {
        $this->fakeLocalVideoFile();

        $media = (new MediaOpener)->open('video.mp4');

        $clipFilter = new \FFMpeg\Filters\Video\ClipFilter(
            \FFMpeg\Coordinate\TimeCode::fromSeconds(5)
        );

        $media->addFilter($clipFilter);

        $this->assertCount(1, $media->getFilters());
    }

    /** @test */
    public function it_can_add_a_custom_filter_with_an_array()
    {
        $this->fakeLocalVideoFile();

        $media = (new MediaOpener)->open('video.mp4')
            ->addFilter(['-itsoffset', 1]);

        $this->assertCount(1, $filters = $media->getFilters());

        $this->assertInstanceOf(SimpleFilter::class, $filter = Arr::first($filters));

        $parameters = $filter->apply(
            $this->mock(Audio::class),
            $this->mock(AudioInterface::class)
        );

        $this->assertEquals(['-itsoffset', 1], $parameters);
    }
    /** @test */
    public function it_can_add_a_custom_filter_using_arguments()
    {
        $this->fakeLocalVideoFile();

        $media = (new MediaOpener)->open('video.mp4')
            ->addFilter('-itsoffset', 1);

        $this->assertCount(1, $filters = $media->getFilters());

        $this->assertInstanceOf(SimpleFilter::class, $filter = Arr::first($filters));

        $parameters = $filter->apply(
            $this->mock(Audio::class),
            $this->mock(AudioInterface::class)
        );

        $this->assertEquals(['-itsoffset', 1], $parameters);
    }

    /** @test */
    public function it_can_use_complex_filters_as_callback_on_advanced_media_objects()
    {
        $this->fakeLocalVideoFiles();

        (new MediaOpener)->fromDisk('local')
            ->open(['video.mp4', 'video2.mp4'])
            ->addFilter(function ($filters) {
                $this->assertInstanceOf(ComplexFilters::class, $filters);
            });
    }

    /** @test */
    public function it_can_add_a_complex_filter_object_with_a_input_and_output()
    {
        $this->fakeLocalVideoFiles();

        $command = (new MediaOpener)->fromDisk('local')
            ->open(['video.mp4', 'video2.mp4'])
            ->addFilter('[0:v][1:v]', 'hstack', '[v]')
            ->export()
            ->addFormatOutputMapping(new X264, Media::make('local', 'output.mp4'), ['0:a', '[v]'])
            ->getCommand();

        $this->assertStringContainsString('-filter_complex [0:v][1:v]hstack[v] -map 0:a -map [v]', $command);
    }

    /** @test */
    public function it_can_add_a_basic_filter_to_an_advanced_media_object_using_a_filter_class()
    {
        $this->fakeLocalVideoFiles();

        $resizeFilter = new \FFMpeg\Filters\Video\ResizeFilter(
            new \FFMpeg\Coordinate\Dimension(640, 480)
        );

        (new MediaOpener)->fromDisk('local')
            ->open(['video.mp4', 'video2.mp4'])
            ->addBasicFilter('[0]', '[v0]', $resizeFilter)
            ->export()
            ->addFormatOutputMapping(new X264, Media::make('local', 'output.mp4'), ['[v0]'])
            ->save();

        $this->assertEquals(
            640,
            (new MediaOpener)->fromDisk('local')->open('output.mp4')->getWidth()
        );
    }

    /** @test */
    public function it_can_add_a_basic_filter_to_an_advanced_media_object_using_a_closure()
    {
        $this->fakeLocalVideoFiles();

        (new MediaOpener)->fromDisk('local')
            ->open(['video.mp4', 'video2.mp4'])
            ->addBasicFilter('[0]', '[v0]', function ($filters) {
                $filters->resize(new \FFMpeg\Coordinate\Dimension(1280, 960));
            })
            ->export()
            ->addFormatOutputMapping(new X264, Media::make('local', 'output.mp4'), ['[v0]'])
            ->save();

        $this->assertEquals(
            1280,
            (new MediaOpener)->fromDisk('local')->open('output.mp4')->getWidth()
        );
    }
}

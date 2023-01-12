<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use FFMpeg\Filters\AdvancedMedia\ComplexFilters;
use FFMpeg\Filters\Audio\SimpleFilter;
use FFMpeg\Filters\Video\ResizeFilter;
use FFMpeg\Filters\Video\VideoFilters;
use FFMpeg\Filters\Video\WatermarkFilter;
use FFMpeg\Format\AudioInterface;
use FFMpeg\Media\Audio;
use Illuminate\Support\Arr;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\LegacyFilterMapping;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFactory;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;

class AddFilter extends TestCase
{
    /** @test */
    public function it_can_add_a_filter_using_a_closure()
    {
        $this->fakeLocalVideoFile();

        $media = (new MediaOpener())->open('video.mp4');

        $this->assertCount(0, $media->getFilters());

        $media->addFilter(function ($filters) {
            $this->assertInstanceOf(VideoFilters::class, $filters);
            $filters->resize(new \FFMpeg\Coordinate\Dimension(640, 480));
        });

        $this->assertCount(1, $media->getFilters());
    }

    /** @test */
    public function it_can_resize_the_video_by_using_the_resize_method()
    {
        $this->fakeLocalVideoFile();

        $media = (new MediaOpener())->open('video.mp4')->resize(640, 360);

        $this->assertCount(1, $media->getFilters());
        $this->assertInstanceOf(ResizeFilter::class, $filter = $media->getFilters()[0]);
        $this->assertEquals(640, $filter->getDimension()->getWidth());
        $this->assertEquals(360, $filter->getDimension()->getHeight());
    }

    /** @test */
    public function it_can_add_a_watermark_with_the_factory_helper_and_manipulate_it()
    {
        $this->fakeLocalVideoFile();
        $this->addTestFile('logo.png');

        $media = (new MediaOpener())->open('video.mp4');

        $this->assertCount(0, $media->getFilters());

        $media->addWatermark(function (WatermarkFactory $watermark) {
            $watermark->open('logo.png')
                ->greyscale()
                ->width(100)
                ->bottom()
                ->left();
        });

        $this->assertCount(1, $media->getFilters());
        $this->assertInstanceOf(WatermarkFilter::class, $media->getFilters()[0]);
    }

    /** @test */
    public function it_can_add_a_filter_using_an_object()
    {
        $this->fakeLocalVideoFile();

        $media = (new MediaOpener())->open('video.mp4');

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

        $media = (new MediaOpener())->open('video.mp4')
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

        $media = (new MediaOpener())->open('video.mp4')
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
    public function it_can_parse_a_complex_filter_input_to_a_integer()
    {
        $mapping = new LegacyFilterMapping('0', '[out]', '');
        $this->assertEquals(0, $mapping->normalizeIn());

        $mapping = new LegacyFilterMapping('[0]', '[out]', '');
        $this->assertEquals(0, $mapping->normalizeIn());

        $mapping = new LegacyFilterMapping('[0v]', '[out]', '');
        $this->assertEquals(0, $mapping->normalizeIn());

        $mapping = new LegacyFilterMapping('2', '[out]', '');
        $this->assertEquals(2, $mapping->normalizeIn());

        $mapping = new LegacyFilterMapping('[2]', '[out]', '');
        $this->assertEquals(2, $mapping->normalizeIn());

        $mapping = new LegacyFilterMapping('[2v]', '[out]', '');
        $this->assertEquals(2, $mapping->normalizeIn());
    }

    /** @test */
    public function it_can_use_complex_filters_as_callback_on_advanced_media_objects()
    {
        $this->fakeLocalVideoFiles();

        (new MediaOpener())->fromDisk('local')
            ->open(['video.mp4', 'video2.mp4'])
            ->addFilter(function ($filters) {
                $this->assertInstanceOf(ComplexFilters::class, $filters);
            });
    }

    /** @test */
    public function it_can_add_a_complex_filter_object_with_a_input_and_output()
    {
        $this->fakeLocalVideoFiles();

        $command = (new MediaOpener())->fromDisk('local')
            ->open(['video.mp4', 'video2.mp4'])
            ->addFilter('[0:v][1:v]', 'hstack', '[v]')
            ->export()
            ->addFormatOutputMapping($this->x264(), Media::make('local', 'output.mp4'), ['0:a', '[v]'])
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

        (new MediaOpener())->fromDisk('local')
            ->open(['video.mp4', 'video2.mp4'])
            ->addFilterAsComplexFilter('[0]', '[v0]', $resizeFilter)
            ->export()
            ->addFormatOutputMapping($this->x264(), Media::make('local', 'output.mp4'), ['[v0]'])
            ->save();

        $this->assertEquals(
            640,
            (new MediaOpener())->fromDisk('local')->open('output.mp4')->getStreams()[0]->get('width')
        );
    }

    /** @test */
    public function it_can_add_a_basic_filter_to_an_advanced_media_object_using_a_closure()
    {
        $this->fakeLocalVideoFiles();

        (new MediaOpener())->fromDisk('local')
            ->open(['video.mp4', 'video2.mp4'])
            ->addFilterAsComplexFilter('[0]', '[v0]', function ($filters) {
                $filters->resize(new \FFMpeg\Coordinate\Dimension(1280, 960));
            })
            ->export()
            ->addFormatOutputMapping($this->x264(), Media::make('local', 'output.mp4'), ['[v0]'])
            ->save();

        $this->assertEquals(
            1280,
            (new MediaOpener())->fromDisk('local')->open('output.mp4')->getStreams()[0]->get('width')
        );
    }
}

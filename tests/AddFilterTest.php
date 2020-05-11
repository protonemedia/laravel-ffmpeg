<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use FFMpeg\Filters\Audio\SimpleFilter;
use FFMpeg\Format\AudioInterface;
use FFMpeg\Media\Audio;
use Illuminate\Support\Arr;
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
}

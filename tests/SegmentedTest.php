<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use Mockery;
use Pbmedia\LaravelFFMpeg\Media;
use Pbmedia\LaravelFFMpeg\SegmentedExporter;
use Pbmedia\LaravelFFMpeg\SegmentedFilter;

class SegmentedTest extends TestCase
{
    public function testFilter()
    {
        $media    = $this->getVideoMedia();
        $playlist = 'MyPlaylist.m3u8';
        $format   = new \FFMpeg\Format\Video\X264;

        $exporter = new SegmentedExporter($media);
        $exporter->setPlaylistPath($playlist);
        $exporter->inFormat($format);
        $exporter->setSegmentLength(20);

        $filter = $exporter->getFilter();

        $this->assertInstanceOf(SegmentedFilter::class, $filter);

        $this->assertEquals('MyPlaylist_1000.m3u8', $filter->getPlaylistPath());
        $this->assertEquals(20, $filter->getSegmentLength());

        $this->assertEquals($filter->apply($media(), $format), [
            '-map',
            '0',
            '-flags',
            '-global_header',
            '-f',
            'segment',
            '-segment_format',
            'mpeg_ts',
            '-segment_list',
            'MyPlaylist_1000.m3u8',
            '-segment_time',
            20,
        ]);
    }

    public function testExportingASegmented()
    {
        $file = $this->getVideoMedia()->getFile();

        $playlist = 'MyPlaylist.m3u8';
        $format   = new \FFMpeg\Format\Video\X264;

        $media = Mockery::mock(Media::class);

        $media->shouldReceive('getFile')->once()->andReturn($file);
        $media->shouldReceive('save')->once()->withArgs([
            $format, $this->srcDir . '/MyPlaylist_1000_%05d.ts',
        ]);

        $exporter = new SegmentedExporter($media);
        $exporter->setPlaylistPath($playlist);
        $exporter->inFormat($format);

        $media->shouldReceive('addFilter')->once()->withArgs([
            $exporter->getFilter(),
        ]);

        $exporter->inFormat($format)
            ->toDisk('local')
            ->save($playlist);
    }
}

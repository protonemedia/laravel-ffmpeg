<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use Mockery;
use Pbmedia\LaravelFFMpeg\HLSStreamExporter;
use Pbmedia\LaravelFFMpeg\HLSStreamFilter;
use Pbmedia\LaravelFFMpeg\Media;

class HLSStreamTest extends TestCase
{
    public function testFilter()
    {
        $media    = $this->getVideoMedia();
        $playlist = 'MyPlaylist.m3u8';
        $format   = new \FFMpeg\Format\Video\X264;

        $exporter = new HLSStreamExporter($media);
        $exporter->setSegmentLength(20);

        $filter = $exporter->getFilter($playlist);

        $this->assertInstanceOf(HLSStreamFilter::class, $filter);

        $this->assertEquals($playlist, $filter->getPlaylistPath());
        $this->assertEquals(20, $filter->getSegmentLength());

        $this->assertEquals($filter->apply($media(), $format), [
            '-map 0',
            '-flags',
            '-global_header',
            '-f segment',
            '-segment_format mpeg_ts',
            '-segment_list ' . $playlist,
            '-segment_time ' . 20,
        ]);
    }

    public function testExportingAHLSStream()
    {
        $file = $this->getVideoMedia()->getFile();

        $playlist = 'MyPlaylist.m3u8';
        $format   = new \FFMpeg\Format\Video\X264;

        $media = Mockery::mock(Media::class);

        $media->shouldReceive('getFile')->once()->andReturn($file);
        $media->shouldReceive('save')->once()->withArgs([
            $format, $this->srcDir . '/MyPlaylist_1000k_%05d.ts',
        ]);

        $exporter = new HLSStreamExporter($media);

        $media->shouldReceive('addFilter')->once()->withArgs([
            $exporter->getFilter($playlist),
        ]);

        $exporter->inFormat($format)
            ->toDisk('local')
            ->save($playlist);
    }
}

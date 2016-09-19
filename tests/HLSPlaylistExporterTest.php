<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use Mockery;
use Pbmedia\LaravelFFMpeg\HLSPlaylistExporter;
use Pbmedia\LaravelFFMpeg\Media;
use Pbmedia\LaravelFFMpeg\SegmentedExporter;

class HLSPlaylistExporterTest extends TestCase
{
    public function testSortingFormats()
    {
        $file = $this->getVideoMedia()->getFile();

        $media = Mockery::mock(Media::class);
        $media->shouldReceive('getFile')->once()->andReturn($file);

        $formatA = new \FFMpeg\Format\Video\X264;
        $formatB = new \FFMpeg\Format\Video\X264;
        $formatC = new \FFMpeg\Format\Video\X264;

        $formatA->setKiloBitrate(1024);
        $formatB->setKiloBitrate(64);
        $formatC->setKiloBitrate(256);

        $exporter = new HLSPlaylistExporter($media);

        $exporter->addFormat($formatA)
            ->addFormat($formatB)
            ->addFormat($formatC);

        $this->assertEquals($formatA, $exporter->getFormats()[2]);
        $this->assertEquals($formatB, $exporter->getFormats()[0]);
        $this->assertEquals($formatC, $exporter->getFormats()[1]);
    }

    public function testSegmentExporters()
    {
        $file = $this->getVideoMedia()->getFile();

        $media = Mockery::mock(Media::class);
        $media->shouldReceive('getFile')->once()->andReturn($file);

        $playlist = 'MyPlaylist.m3u8';

        $formatA = (new \FFMpeg\Format\Video\X264)->setKiloBitrate(384);
        $formatB = (new \FFMpeg\Format\Video\X264)->setKiloBitrate(512);

        $exporter = new HLSPlaylistExporter($media);

        $exporter->addFormat($formatA)
            ->addFormat($formatB)
            ->setPlaylistPath($playlist)
            ->setSegmentLength(15);

        $segmentedExporters = $exporter->getSegmentedExporters();

        $this->assertCount(2, $segmentedExporters);
        $this->assertInstanceOf(SegmentedExporter::class, $segmentedExporters[0]);
        $this->assertInstanceOf(SegmentedExporter::class, $segmentedExporters[1]);

        $this->assertEquals($formatA, $segmentedExporters[0]->getFormat());
        $this->assertEquals($formatB, $segmentedExporters[1]->getFormat());
        $this->assertEquals(15, $segmentedExporters[1]->getFilter()->getSegmentLength());
        $this->assertEquals(15, $segmentedExporters[1]->getFilter()->getSegmentLength());
    }

}

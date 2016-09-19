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

        $this->assertEquals($formatB, $exporter->getFormats()[0]); // 64
        $this->assertEquals($formatC, $exporter->getFormats()[1]); // 256
        $this->assertEquals($formatA, $exporter->getFormats()[2]); // 1024
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

    public function testCreationOfPlaylist()
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

        $media->shouldReceive('addFilter')->once();

        $media->shouldReceive('save')->once()->withArgs([
            $formatA, $this->srcDir . '/MyPlaylist_384_%05d.ts',
        ]);

        $media->shouldReceive('save')->once()->withArgs([
            $formatB, $this->srcDir . '/MyPlaylist_512_%05d.ts',
        ]);

        $exporter->toDisk('local')->save($playlist);

        $this->assertEquals(file_get_contents($this->srcDir . '/MyPlaylist.m3u8'),
            '#EXTM3U' . PHP_EOL .
            '#EXT-X-STREAM-INF:BANDWIDTH=384000' . PHP_EOL .
            'MyPlaylist_384.m3u8' . PHP_EOL .
            '#EXT-X-STREAM-INF:BANDWIDTH=512000' . PHP_EOL .
            'MyPlaylist_512.m3u8'
        );

        @unlink($this->srcDir . '/MyPlaylist.m3u8');
    }
}

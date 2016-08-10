<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use Mockery;
use Pbmedia\LaravelFFMpeg\Frame;
use Pbmedia\LaravelFFMpeg\FrameExporter;

class FrameTest extends TestCase
{
    public function testGettingAFrameFromAString()
    {
        $media = $this->getVideoMedia();
        $frame = $media->getFrameFromString('00:00:12.47');

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertEquals((string) $frame->getTimeCode(), '00:00:12.47');
    }

    public function testGettingAFrameFromSeconds()
    {
        $media = $this->getVideoMedia();
        $frame = $media->getFrameFromSeconds(5);

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertEquals((string) $frame->getTimeCode(), '00:00:05.00');
    }

    public function testSettingTheAccuracy()
    {
        $media    = $this->getVideoMedia();
        $frame    = $media->getFrameFromSeconds(5);
        $exporter = $frame->export();

        $this->assertInstanceOf(FrameExporter::class, $exporter);

        $exporter->accurate();
        $this->assertTrue($exporter->getAccuracy());

        $exporter->unaccurate();
        $this->assertFalse($exporter->getAccuracy());
    }

    public function testExportingAFrame()
    {
        $file = $this->getVideoMedia()->getFile();

        $media = Mockery::mock(Frame::class);
        $media->shouldReceive('getFile')->once()->andReturn($file);
        $media->shouldReceive('isFrame')->once()->andReturn(true);

        $media->shouldReceive('save')->once()->withArgs([
            $this->srcDir . '/FrameAtThreeSeconds.png', false,
        ]);

        $exporter = new FrameExporter($media);
        $exporter->save('FrameAtThreeSeconds.png');
    }
}

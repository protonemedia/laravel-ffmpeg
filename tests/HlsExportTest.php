<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

use FFMpeg\Filters\AdvancedMedia\ComplexFilters;
use FFMpeg\Filters\Video\VideoFilters;
use FFMpeg\Format\Video\X264;
use Illuminate\Support\Facades\Storage;
use Pbmedia\LaravelFFMpeg\MediaOpener;

class HlsExportTest extends TestCase
{
    /** @test */
    public function it_can_export_a_single_media_file_into_a_hls_export()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate  = (new X264)->setKiloBitrate(250);
        $midBitrate  = (new X264)->setKiloBitrate(1000);
        $highBitrate = (new X264)->setKiloBitrate(4000);

        (new MediaOpener)
            ->open('video.mp4')
            ->exportForHLS()
            ->addFormat($lowBitrate)
            ->addFormat($midBitrate)
            ->addFormat($highBitrate)
            ->toDisk('memory')
            ->save('adaptive.m3u8');

        $this->assertTrue(Storage::disk('memory')->has('adaptive.m3u8'));
        $this->assertTrue(Storage::disk('memory')->has('adaptive_0_250.m3u8'));
        $this->assertTrue(Storage::disk('memory')->has('adaptive_1_1000.m3u8'));
        $this->assertTrue(Storage::disk('memory')->has('adaptive_2_4000.m3u8'));

        $this->assertEquals(
            1920,
            (new MediaOpener)->fromDisk('memory')->open('adaptive_0_250_00000.ts')->getStreams()[0]->get('width')
        );

        $this->assertEquals(
            1920,
            (new MediaOpener)->fromDisk('memory')->open('adaptive_1_1000_00000.ts')->getStreams()[0]->get('width')
        );

        $this->assertEquals(
            1920,
            (new MediaOpener)->fromDisk('memory')->open('adaptive_2_4000_00000.ts')->getStreams()[0]->get('width')
        );

        $playlist = Storage::disk('memory')->get('adaptive.m3u8');

        $this->assertEquals(implode(PHP_EOL, [
            '#EXTM3U',
            '#EXT-X-STREAM-INF:BANDWIDTH=287416,RESOLUTION=1920x1080,FRAME-RATE=25.000',
            'adaptive_0_250.m3u8',
            '#EXT-X-STREAM-INF:BANDWIDTH=1141383,RESOLUTION=1920x1080,FRAME-RATE=25.000',
            'adaptive_1_1000.m3u8',
            '#EXT-X-STREAM-INF:BANDWIDTH=4185389,RESOLUTION=1920x1080,FRAME-RATE=25.000',
            'adaptive_2_4000.m3u8',
            '#EXT-X-ENDLIST',
        ]), $playlist);
    }

    /** @test */
    public function it_can_export_to_hls_with_legacy_filters_for_each_format()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate   = (new X264)->setKiloBitrate(250);
        $midBitrate   = (new X264)->setKiloBitrate(500);
        $highBitrate  = (new X264)->setKiloBitrate(1000);
        $superBitrate = (new X264)->setKiloBitrate(1500);

        (new MediaOpener)
            ->open('video.mp4')
            ->exportForHLS()
            ->addFormat($lowBitrate, function ($media) {
                $media->addLegacyFilter(function (VideoFilters $filters) {
                    $filters->resize(new \FFMpeg\Coordinate\Dimension(640, 480));
                });
            })
            ->addFormat($midBitrate, function ($media) {
                $media->addLegacyFilter(function ($filters) {
                    $filters->resize(new \FFMpeg\Coordinate\Dimension(1280, 960));
                });
            })
            ->addFormat($highBitrate)
            ->addFormat($superBitrate, function ($media) {
            })
            ->toDisk('local')
            ->save('adaptive.m3u8');

        $this->assertTrue(Storage::disk('local')->has('adaptive.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_0_250.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_1_500.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_2_1000.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_3_1500.m3u8'));

        $this->assertEquals(
            640,
            (new MediaOpener)->fromDisk('local')->open('adaptive_0_250_00000.ts')->getStreams()[0]->get('width')
        );

        $this->assertEquals(
            1280,
            (new MediaOpener)->fromDisk('local')->open('adaptive_1_500_00000.ts')->getStreams()[0]->get('width')
        );

        $this->assertEquals(
            1920,
            (new MediaOpener)->fromDisk('local')->open('adaptive_2_1000_00000.ts')->getStreams()[0]->get('width')
        );

        $this->assertEquals(
            1920,
            (new MediaOpener)->fromDisk('local')->open('adaptive_3_1500_00000.ts')->getStreams()[0]->get('width')
        );
    }

    /** @test */
    public function it_can_export_to_hls_with_complex_filters_for_each_format()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate   = (new X264)->setKiloBitrate(250);
        $midBitrate   = (new X264)->setKiloBitrate(500);
        $highBitrate  = (new X264)->setKiloBitrate(1000);
        $superBitrate = (new X264)->setKiloBitrate(1500);

        (new MediaOpener)
            ->open('video.mp4')
            ->exportForHLS()
            ->addFormat($lowBitrate, function ($media) {
                $media->scale(640, 360);
            })
            ->addFormat($midBitrate, function ($media) {
                $media->addFilter('scale=1280:960');
            })
            ->addFormat($highBitrate)
            ->addFormat($superBitrate, function ($media) {
                $media->addFilter(function (ComplexFilters $filters, $in, $out) {
                    $filters->custom($in, 'scale=2560:1920', $out);
                });
            })
            ->toDisk('local')
            ->save('complex.m3u8');

        $this->assertTrue(Storage::disk('local')->has('complex.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('complex_0_250.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('complex_1_500.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('complex_2_1000.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('complex_3_1500.m3u8'));

        $this->assertEquals(
            640,
            (new MediaOpener)->fromDisk('local')->open('complex_0_250_00000.ts')->getStreams()[0]->get('width')
        );

        $this->assertEquals(
            1280,
            (new MediaOpener)->fromDisk('local')->open('complex_1_500_00000.ts')->getStreams()[0]->get('width')
        );

        $this->assertEquals(
            1920,
            (new MediaOpener)->fromDisk('local')->open('complex_2_1000_00000.ts')->getStreams()[0]->get('width')
        );

        $this->assertEquals(
            2560,
            (new MediaOpener)->fromDisk('local')->open('complex_3_1500_00000.ts')->getStreams()[0]->get('width')
        );
    }
}

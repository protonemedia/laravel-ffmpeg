<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

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

        $this->assertTrue(Storage::disk('memory')->has('adaptive_0_250.m3u8'));
        $this->assertTrue(Storage::disk('memory')->has('adaptive_1_1000.m3u8'));
        $this->assertTrue(Storage::disk('memory')->has('adaptive_2_4000.m3u8'));
    }

    /** @test */
    public function it_cna_export_to_hls_with_seperate_filters_for_each_format()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate  = (new X264)->setKiloBitrate(250);
        $midBitrate  = (new X264)->setKiloBitrate(500);
        $highBitrate = (new X264)->setKiloBitrate(1000);

        (new MediaOpener)
            ->open('video.mp4')
            ->exportForHLS()
            ->addFormat($lowBitrate, function ($media) {
                $media->addFilter(function (VideoFilters $filters) {
                    $filters->resize(new \FFMpeg\Coordinate\Dimension(640, 480));
                });
            })
            ->addFormat($midBitrate, function ($media) {
                $media->addFilter(function ($filters) {
                    $filters->resize(new \FFMpeg\Coordinate\Dimension(1280, 960));
                });
            })
            ->addFormat($highBitrate)
            ->toDisk('local')
            ->save('adaptive.m3u8');

        $this->assertTrue(Storage::disk('local')->has('adaptive_0_250.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_1_500.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_2_1000.m3u8'));
    }
}

<?php

namespace Pbmedia\LaravelFFMpeg\Tests;

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
}

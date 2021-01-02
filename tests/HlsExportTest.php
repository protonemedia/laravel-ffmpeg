<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use FFMpeg\Filters\AdvancedMedia\ComplexFilters;
use FFMpeg\Filters\Video\VideoFilters;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSPlaylistGenerator;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSVideoFilters;
use ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFactory;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;

class HlsExportTest extends TestCase
{
    public static function streamInfoPattern($resolution): string
    {
        return '#EXT-X-STREAM-INF:BANDWIDTH=[0-9]{4,},RESOLUTION=' . $resolution . ',CODECS="[a-zA-Z0-9,.]+",FRAME-RATE=25.000';
    }

    /** @test */
    public function it_can_export_a_single_media_file_into_a_hls_export()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate  = $this->x264()->setKiloBitrate(250);
        $midBitrate  = $this->x264()->setKiloBitrate(1000);
        $highBitrate = $this->x264()->setKiloBitrate(4000);

        (new MediaOpener)
            ->open('video.mp4')
            ->exportForHLS()
            ->addFormat($lowBitrate)
            ->addFormat($midBitrate)
            ->addFormat($highBitrate)
            ->toDisk('local')
            ->save('adaptive.m3u8');

        $this->assertTrue(Storage::disk('local')->has('adaptive.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_0_250.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_1_1000.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_2_4000.m3u8'));

        $media = (new MediaOpener)->fromDisk('local')->open('adaptive_0_250_00000.ts');
        $this->assertEquals(1920, $media->getVideoStream()->get('width'));
        $this->assertNotNull($media->getAudioStream());

        $media = (new MediaOpener)->fromDisk('local')->open('adaptive_1_1000_00000.ts');
        $this->assertEquals(1920, $media->getVideoStream()->get('width'));
        $this->assertNotNull($media->getAudioStream());

        $media = (new MediaOpener)->fromDisk('local')->open('adaptive_2_4000_00000.ts');
        $this->assertEquals(1920, $media->getVideoStream()->get('width'));
        $this->assertNotNull($media->getAudioStream());

        $this->assertPlaylistPattern(Storage::disk('local')->get('adaptive.m3u8'), [
            '#EXTM3U',
            static::streamInfoPattern('1920x1080'),
            'adaptive_0_250.m3u8',
            static::streamInfoPattern('1920x1080'),
            'adaptive_1_1000.m3u8',
            static::streamInfoPattern('1920x1080'),
            'adaptive_2_4000.m3u8',
            '#EXT-X-ENDLIST',
        ]);
    }

    /** @test */
    public function it_can_export_a_single_media_file_into_a_subdirectory()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate = $this->x264()->setKiloBitrate(250);
        $midBitrate = $this->x264()->setKiloBitrate(1000);

        (new MediaOpener)
            ->open('video.mp4')
            ->exportForHLS()
            ->addFormat($lowBitrate)
            ->addFormat($midBitrate)
            ->save('sub/dir/adaptive.m3u8');

        $this->assertTrue(Storage::disk('local')->has('sub/dir/adaptive.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('sub/dir/adaptive_0_250.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('sub/dir/adaptive_1_1000.m3u8'));

        $masterPlaylist = Storage::disk('local')->get('sub/dir/adaptive.m3u8');
        $lowPlaylist    = Storage::disk('local')->get('sub/dir/adaptive_0_250.m3u8');

        $this->assertStringNotContainsString('sub/dir', $masterPlaylist);
        $this->assertStringNotContainsString('sub/dir', $lowPlaylist);
    }

    /** @test */
    public function it_can_export_a_single_media_file_into_a_subdirectory_on_a_remote_disk()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate = $this->x264()->setKiloBitrate(250);
        $midBitrate = $this->x264()->setKiloBitrate(1000);

        (new MediaOpener)
            ->open('video.mp4')
            ->exportForHLS()
            ->addFormat($lowBitrate)
            ->addFormat($midBitrate)
            ->toDisk('local')
            ->save('sub/dir/adaptive.m3u8');

        $this->assertTrue(Storage::disk('local')->has('sub/dir/adaptive.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('sub/dir/adaptive_0_250.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('sub/dir/adaptive_1_1000.m3u8'));
        $this->assertFalse(Storage::disk('local')->has('sub/dir/master_playlist_guide_0.m3u8'));

        $masterPlaylist = Storage::disk('local')->get('sub/dir/adaptive.m3u8');
        $lowPlaylist    = Storage::disk('local')->get('sub/dir/adaptive_0_250.m3u8');

        $this->assertStringNotContainsString('sub/dir', $masterPlaylist);
        $this->assertStringNotContainsString('sub/dir', $lowPlaylist);
    }

    /** @test */
    public function it_can_use_a_custom_format_for_the_segment_naming()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate = $this->x264()->setKiloBitrate(250);
        $midBitrate = $this->x264()->setKiloBitrate(1000);

        (new MediaOpener)
            ->open('video.mp4')
            ->exportForHLS()
            ->addFormat($lowBitrate)
            ->addFormat($midBitrate)
            ->setSegmentLength(5)
            ->setKeyFrameInterval(24)
            ->withPlaylistGenerator(new HLSPlaylistGenerator)
            ->useSegmentFilenameGenerator(function ($name, $format, $key, callable $segments, callable $playlist) {
                $segments("N{$name}B{$format->getKiloBitrate()}K{$key}_%02d.ts");
                $playlist("N{$name}B{$format->getKiloBitrate()}K{$key}.m3u8");
            })
            ->toDisk('local')
            ->save('adaptive.m3u8');

        $this->assertTrue(Storage::disk('local')->has('adaptive.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('NadaptiveB250K0.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('NadaptiveB250K0_00.ts'));
        $this->assertTrue(Storage::disk('local')->has('NadaptiveB1000K1.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('NadaptiveB1000K1_00.ts'));

        $playlist = Storage::disk('local')->get('adaptive.m3u8');
        $playlist = preg_replace('/\n|\r\n?/', "\n", $playlist);

        $pattern = '/' . implode("\n", [
            '#EXTM3U',
            static::streamInfoPattern('1920x1080'),
            'NadaptiveB250K0.m3u8',
            static::streamInfoPattern('1920x1080'),
            'NadaptiveB1000K1.m3u8',
            '#EXT-X-ENDLIST',
        ]) . '/';

        $this->assertEquals(1, preg_match($pattern, $playlist), "Playlist mismatch:" . PHP_EOL . $playlist);
    }

    /** @test */
    public function it_can_export_to_hls_with_legacy_filters_for_each_format()
    {
        $this->fakeLocalVideoFile();
        $this->addTestFile('logo.png');

        $lowBitrate   = $this->x264()->setKiloBitrate(250);
        $midBitrate   = $this->x264()->setKiloBitrate(500);
        $highBitrate  = $this->x264()->setKiloBitrate(1000);
        $superBitrate = $this->x264()->setKiloBitrate(1500);

        (new MediaOpener)
            ->open('video.mp4')
            ->exportForHLS()
            ->addFormat($lowBitrate, function (HLSVideoFilters $media) {
                $media->scale(640, 360);
            })
            ->addFormat($midBitrate, function ($media) {
                $media->addWatermark(function (WatermarkFactory $factory) {
                    $factory->open("logo.png");
                })->resize(1280, 960);
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

        $media = (new MediaOpener)->fromDisk('local')->open('adaptive_0_250_00000.ts');
        $this->assertEquals(640, $media->getVideoStream()->get('width'));
        $this->assertNotNull($media->getAudioStream());

        $media = (new MediaOpener)->fromDisk('local')->open('adaptive_1_500_00000.ts');
        $this->assertEquals(1280, $media->getVideoStream()->get('width'));
        $this->assertNotNull($media->getAudioStream());

        $media = (new MediaOpener)->fromDisk('local')->open('adaptive_2_1000_00000.ts');
        $this->assertEquals(1920, $media->getVideoStream()->get('width'));
        $this->assertNotNull($media->getAudioStream());

        $media = (new MediaOpener)->fromDisk('local')->open('adaptive_3_1500_00000.ts');
        $this->assertEquals(1920, $media->getVideoStream()->get('width'));
        $this->assertNotNull($media->getAudioStream());

        $playlist = Storage::disk('local')->get('adaptive.m3u8');

        $this->assertStringContainsString('RESOLUTION=640x360', $playlist);
        $this->assertStringContainsString('RESOLUTION=1920x1080', $playlist);
    }

    /** @test */
    public function it_can_export_to_hls_with_legacy_filters_without_audio()
    {
        $this->fakeLocalVideoFile();
        $this->addTestFile('video_no_audio.mp4');

        $lowBitrate   = $this->x264()->setKiloBitrate(250);
        $midBitrate   = $this->x264()->setKiloBitrate(500);
        $highBitrate  = $this->x264()->setKiloBitrate(1000);
        $superBitrate = $this->x264()->setKiloBitrate(1500);

        (new MediaOpener)
            ->open('video_no_audio.mp4')
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

        $media = (new MediaOpener)->fromDisk('local')->open('adaptive_0_250_00000.ts');
        $this->assertEquals(640, $media->getVideoStream()->get('width'));
        $this->assertNull($media->getAudioStream());

        $media = (new MediaOpener)->fromDisk('local')->open('adaptive_1_500_00000.ts');
        $this->assertEquals(1280, $media->getVideoStream()->get('width'));
        $this->assertNull($media->getAudioStream());

        $media = (new MediaOpener)->fromDisk('local')->open('adaptive_2_1000_00000.ts');
        $this->assertEquals(1920, $media->getVideoStream()->get('width'));
        $this->assertNull($media->getAudioStream());

        $media = (new MediaOpener)->fromDisk('local')->open('adaptive_3_1500_00000.ts');
        $this->assertEquals(1920, $media->getVideoStream()->get('width'));
        $this->assertNull($media->getAudioStream());
    }

    /** @test */
    public function it_can_export_to_hls_with_complex_filters_for_each_format()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate   = $this->x264()->setKiloBitrate(250);
        $midBitrate   = $this->x264()->setKiloBitrate(500);
        $highBitrate  = $this->x264()->setKiloBitrate(1000);
        $superBitrate = $this->x264()->setKiloBitrate(1500);

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
            (new MediaOpener)->fromDisk('local')->open('complex_0_250_00000.ts')->getVideoStream()->get('width')
        );

        $this->assertEquals(
            1280,
            (new MediaOpener)->fromDisk('local')->open('complex_1_500_00000.ts')->getVideoStream()->get('width')
        );

        $this->assertEquals(
            1920,
            (new MediaOpener)->fromDisk('local')->open('complex_2_1000_00000.ts')->getVideoStream()->get('width')
        );

        $this->assertEquals(
            2560,
            (new MediaOpener)->fromDisk('local')->open('complex_3_1500_00000.ts')->getVideoStream()->get('width')
        );
    }
}

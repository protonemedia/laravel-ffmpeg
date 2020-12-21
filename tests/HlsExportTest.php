<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use FFMpeg\Filters\AdvancedMedia\ComplexFilters;
use FFMpeg\Filters\Video\VideoFilters;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSExporter;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSPlaylistGenerator;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSVideoFilters;
use ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFactory;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class HlsExportTest extends TestCase
{
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

        $playlist = Storage::disk('local')->get('adaptive.m3u8');

        $pattern = '/' . implode("\n", [
            '#EXTM3U',
            '#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+,RESOLUTION=1920x1080,FRAME-RATE=25.000',
            'adaptive_0_250.m3u8',
            '#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+,RESOLUTION=1920x1080,FRAME-RATE=25.000',
            'adaptive_1_1000.m3u8',
            '#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+,RESOLUTION=1920x1080,FRAME-RATE=25.000',
            'adaptive_2_4000.m3u8',
            '#EXT-X-ENDLIST',
        ]) . '/';

        $this->assertEquals(1, preg_match($pattern, $playlist), "Playlist mismatch:" . PHP_EOL . $playlist);
    }

    /** @test */
    public function it_can_export_a_single_media_file_into_an_encryped_hls_export()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate = $this->x264()->setKiloBitrate(250);

        FFMpeg::open('video.mp4')
            ->exportForHLS()
            ->withEncryptionKey(HLSExporter::generateEncryptionKey())
            ->addFormat($lowBitrate)
            ->save('adaptive.m3u8');

        $this->assertTrue(Storage::disk('local')->has('adaptive.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_0_250.m3u8'));

        $playlist = Storage::disk('local')->get('adaptive.m3u8');

        $pattern = '/' . implode("\n", [
            '#EXTM3U',
            '#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+,RESOLUTION=1920x1080,FRAME-RATE=25.000',
            'adaptive_0_250.m3u8',
            '#EXT-X-ENDLIST',
        ]) . '/';

        $this->assertEquals(1, preg_match($pattern, $playlist));

        $encryptedPlaylist = Storage::disk('local')->get('adaptive_0_250.m3u8');

        $pattern = '/' . implode("\n", [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-TARGETDURATION:5',
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-PLAYLIST-TYPE:VOD',
            '#EXT-X-KEY:METHOD=AES-128,URI="[a-zA-Z0-9-_\/]+.key",IV=[a-z0-9]+',
            '#EXTINF:4.720000,',
            'adaptive_0_250_00000.ts',
            '#EXT-X-ENDLIST',
        ]) . '/';

        $this->assertEquals(1, preg_match($pattern, $encryptedPlaylist), "Playlist mismatch:" . PHP_EOL . $encryptedPlaylist);
    }

    /** @test */
    public function it_can_export_a_single_media_file_into_an_encryped_hls_export_with_rotating_keys()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate = $this->x264()->setKiloBitrate(250);

        $keys = [];

        FFMpeg::open('video.mp4')
            ->exportForHLS()
            ->setKeyFrameInterval(1)
            ->setSegmentLength(1)
            ->addFormat($lowBitrate)
            ->withRotatingEncryptionKey(function ($filename, $contents) use (&$keys) {
                $keys[$filename] = $contents;
            })
            ->save('adaptive.m3u8');

        $this->assertCount(6, $keys);

        $this->assertTrue(Storage::disk('local')->has('adaptive.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_0_250.m3u8'));

        $encryptedPlaylist = Storage::disk('local')->get('adaptive_0_250.m3u8');

        $pattern = "/" . implode("\n", [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-TARGETDURATION:[0-9]+',
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-PLAYLIST-TYPE:VOD',
            '#EXT-X-KEY:METHOD=AES-128,URI="[a-zA-Z0-9-_\/]+.key",IV=[a-z0-9]+',
            '#EXTINF:1.000000,',
            'adaptive_0_250_00000.ts',
            '#EXT-X-KEY:METHOD=AES-128,URI="[a-zA-Z0-9-_\/]+.key",IV=[a-z0-9]+',
            '#EXTINF:1.000000,',
            'adaptive_0_250_00001.ts',
            '#EXT-X-KEY:METHOD=AES-128,URI="[a-zA-Z0-9-_\/]+.key",IV=[a-z0-9]+',
            '#EXTINF:1.000000,',
            'adaptive_0_250_00002.ts',
            '#EXT-X-KEY:METHOD=AES-128,URI="[a-zA-Z0-9-_\/]+.key",IV=[a-z0-9]+',
            '#EXTINF:1.000000,',
            'adaptive_0_250_00003.ts',
            '#EXT-X-KEY:METHOD=AES-128,URI="[a-zA-Z0-9-_\/]+.key",IV=[a-z0-9]+',
            '#EXTINF:0.720000,',
            'adaptive_0_250_00004.ts',
            '#EXT-X-ENDLIST',
        ]) . "/";

        $this->assertEquals(1, preg_match($pattern, $encryptedPlaylist), "Playlist mismatch:" . PHP_EOL . $encryptedPlaylist);
    }

    /** @test */
    public function it_can_set_the_numbers_of_segments_per_key()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate = $this->x264()->setKiloBitrate(250);

        $keys = [];

        FFMpeg::open('video.mp4')
            ->exportForHLS()
            ->setKeyFrameInterval(1)
            ->setSegmentLength(1)
            ->addFormat($lowBitrate)
            ->withRotatingEncryptionKey(function ($filename, $contents) use (&$keys) {
                $keys[$filename] = $contents;
            }, 2)
            ->save('adaptive.m3u8');

        $this->assertCount(3, $keys);

        $this->assertTrue(Storage::disk('local')->has('adaptive.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_0_250.m3u8'));

        $encryptedPlaylist = Storage::disk('local')->get('adaptive_0_250.m3u8');

        $pattern = "/" . implode("\n", [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-TARGETDURATION:[0-9]+',
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-PLAYLIST-TYPE:VOD',
            '#EXT-X-KEY:METHOD=AES-128,URI="[a-zA-Z0-9-_\/]+.key",IV=[a-z0-9]+',
            '#EXTINF:1.000000,',
            'adaptive_0_250_00000.ts',
            '#EXTINF:1.000000,',
            'adaptive_0_250_00001.ts',
            '#EXT-X-KEY:METHOD=AES-128,URI="[a-zA-Z0-9-_\/]+.key",IV=[a-z0-9]+',
            '#EXTINF:1.000000,',
            'adaptive_0_250_00002.ts',
            '#EXTINF:1.000000,',
            'adaptive_0_250_00003.ts',
            '#EXT-X-KEY:METHOD=AES-128,URI="[a-zA-Z0-9-_\/]+.key",IV=[a-z0-9]+',
            '#EXTINF:0.720000,',
            'adaptive_0_250_00004.ts',
            '#EXT-X-ENDLIST',
        ]) . "/";

        $this->assertEquals(1, preg_match($pattern, $encryptedPlaylist), "Playlist mismatch:" . PHP_EOL . $encryptedPlaylist);
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

        $pattern = '/' . implode("\n", [
            '#EXTM3U',
            '#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+,RESOLUTION=1920x1080,FRAME-RATE=25.000',
            'NadaptiveB250K0.m3u8',
            '#EXT-X-STREAM-INF:BANDWIDTH=[0-9]+,RESOLUTION=1920x1080,FRAME-RATE=25.000',
            'NadaptiveB1000K1.m3u8',
            '#EXT-X-ENDLIST',
        ]) . '/';

        $this->assertEquals(1, preg_match($pattern, $playlist));
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

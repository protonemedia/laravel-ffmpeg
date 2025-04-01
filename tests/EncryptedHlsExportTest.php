<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSExporter;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\StdListener;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class EncryptedHlsExportTest extends TestCase
{
    public static function keyLinePattern($key = null)
    {
        return '#EXT-X-KEY:METHOD=AES-128,URI="' . ($key ?: '[~a-zA-Z0-9-_\/:]+.key') . '",IV=[a-z0-9]+';
    }

    /**
     * @test
     */
    public function it_can_export_a_single_media_file_into_an_encryped_hls_export()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate  = $this->x264()->setKiloBitrate(250);
        $highBitrate = $this->x264()->setKiloBitrate(500);

        FFMpeg::open('video.mp4')
            ->exportForHLS()
            ->withEncryptionKey(HLSExporter::generateEncryptionKey())
            ->addFormat($lowBitrate)
            ->addFormat($highBitrate)
            ->addListener($listener = new StdListener())
            ->save('adaptive.m3u8');

        $this->assertTrue(Storage::disk('local')->has('adaptive.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_0_250.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_1_500.m3u8'));

        $this->assertPlaylistPattern(Storage::disk('local')->get('adaptive.m3u8'), [
            '#EXTM3U',
            HlsExportTest::streamInfoPattern('1920x1080'),
            'adaptive_0_250.m3u8',
            HlsExportTest::streamInfoPattern('1920x1080'),
            'adaptive_1_500.m3u8',
            '#EXT-X-ENDLIST',
        ], $listener);

        $this->assertPlaylistPattern(Storage::disk('local')->get('adaptive_0_250.m3u8'), [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-TARGETDURATION:5',
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-PLAYLIST-TYPE:VOD',
            static::keyLinePattern('secret.key'),
            '#EXTINF:4.720000,',
            'adaptive_0_250_00000.ts',
            '#EXT-X-ENDLIST',
        ], $listener);
    }

    /**
     * @test
     */
    public function it_can_export_a_single_media_file_twice_into_an_encryped_hls_export()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate = $this->x264()->setKiloBitrate(250);

        $keys = [];

        foreach (range(1, 3) as $i) {
            FFMpeg::open('video.mp4')
                ->exportForHLS()
                ->withRotatingEncryptionKey(function ($filename, $contents) use (&$keys, $i) {
                    $keys[] = $filename;
                })
                ->addFormat($lowBitrate)
                ->save("adaptive{$i}.m3u8");
        }

        $this->assertTrue(count($keys) < 7);

        $this->assertTrue(Storage::disk('local')->has('adaptive1.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive2.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive3.m3u8'));
    }

    /**
     * @test
     */
    public function it_can_export_a_single_media_file_into_an_encryped_hls_export_with_rotating_keys()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate = $this->x264()->setKiloBitrate(250);

        $keys     = [];
        $listener = null;

        FFMpeg::open('video.mp4')
            ->exportForHLS()
            ->setKeyFrameInterval(2)
            ->setSegmentLength(2)
            ->addFormat($lowBitrate)
            ->withRotatingEncryptionKey(function ($filename, $contents, $stdListener) use (&$keys, &$listener) {
                $keys[$filename] = $contents;
                $listener = $listener ?: $stdListener;
            }, 2)
            ->save('adaptive.m3u8');

        $this->assertCount(2, $keys);
        $this->assertCount(2, array_unique(array_values($keys)));

        $this->assertTrue(Storage::disk('local')->has('adaptive.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('adaptive_0_250.m3u8'));

        $playlist = Storage::disk('local')->get('adaptive_0_250.m3u8');

        $this->assertMatchesRegularExpression(static::keyLinePattern() . '#', $playlist);
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

/**
 * Due to slow I/O on the CI platform, we need to retry this test
 * every now and then. Sometimes the I/O is too slow to pickup
 * the rotating key.
 */
class DynamicHLSPlaylistTest extends TestCase
{
    use RetryTrait;

    /**
     * @test
     * @retry 5
     */
    public function it_can_export_a_single_media_file_into_an_encryped_hls_export()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate  = $this->x264()->setKiloBitrate(250);
        $highBitrate = $this->x264()->setKiloBitrate(500);

        FFMpeg::open('video.mp4')
            ->exportForHLS()
            ->setKeyFrameInterval(1)
            ->setSegmentLength(1)
            ->withRotatingEncryptionKey(function ($filename, $contents) {
                Storage::disk('local')->put("keys/{$filename}", $contents);
            })
            ->addFormat($lowBitrate)
            ->addFormat($highBitrate)
            ->save('adaptive.m3u8');

        $dynamicPlaylist = FFMpeg::dynamicHLSPlaylist()
            ->fromDisk('local')
            ->open('adaptive.m3u8')
            ->setMediaUrlResolver(function ($media) {
                return "https://example.com/{$media}";
            })
            ->setPlaylistUrlResolver(function ($playlist) {
                return "https://example.com/{$playlist}";
            })
            ->setKeyUrlResolver(function ($key) {
                return "https://example.com/{$key}?secret=1337";
            })
            ->all();

        $this->assertArrayHasKey('adaptive.m3u8', $dynamicPlaylist);
        $this->assertArrayHasKey('adaptive_0_250.m3u8', $dynamicPlaylist);
        $this->assertArrayHasKey('adaptive_1_500.m3u8', $dynamicPlaylist);

        $this->assertStringContainsString('https://example.com/adaptive_0_250.m3u8', $dynamicPlaylist['adaptive.m3u8']);
        $this->assertStringContainsString('#EXT-X-KEY:METHOD=AES-128,URI="https://example.com/', $dynamicPlaylist['adaptive_0_250.m3u8']);
        $this->assertStringContainsString('?secret=1337",IV=', $dynamicPlaylist['adaptive_0_250.m3u8']);
    }
}

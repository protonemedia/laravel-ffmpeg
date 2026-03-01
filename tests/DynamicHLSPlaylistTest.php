<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class DynamicHLSPlaylistTest extends TestCase
{
    #[Test]
    /** @test */
    public function it_throws_an_exception_when_a_playlist_file_is_missing()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Playlist file not found or invalid: missing.m3u8');

        FFMpeg::dynamicHLSPlaylist()
            ->fromDisk('local')
            ->open('missing.m3u8')
            ->all();
    }

    #[Test]
    /** @test */
    public function it_throws_a_catchable_exception_for_a_corrupted_playlist_path_string()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid or unreadable playlist');

        FFMpeg::dynamicHLSPlaylist()
            ->fromDisk('local')
            ->open("\0broken.m3u8")
            ->all();
    }

    #[Test]
    /** @test */
    public function it_resolves_nested_playlist_media_and_key_paths_relative_to_the_current_playlist()
    {
        Storage::disk('local')->put('videos/master.m3u8', implode(PHP_EOL, [
            '#EXTM3U',
            '#EXT-X-STREAM-INF:BANDWIDTH=250000',
            '480p/playlist.m3u8',
            '#EXT-X-ENDLIST',
        ]));

        Storage::disk('local')->put('videos/480p/playlist.m3u8', implode(PHP_EOL, [
            '#EXTM3U',
            '#EXT-X-KEY:METHOD=AES-128,URI="keys/secret.key",IV=0123456789abcdef',
            '#EXTINF:10.0,',
            'segment_00001.ts',
            '#EXT-X-ENDLIST',
        ]));

        $dynamicPlaylist = FFMpeg::dynamicHLSPlaylist()
            ->fromDisk('local')
            ->open('videos/master.m3u8')
            ->setMediaUrlResolver(fn ($media) => "https://cdn.test/{$media}")
            ->setPlaylistUrlResolver(fn ($playlist) => "https://app.test/hls/{$playlist}")
            ->setKeyUrlResolver(fn ($key) => "https://app.test/keys/{$key}")
            ->all();

        $this->assertStringContainsString('https://app.test/hls/videos/480p/playlist.m3u8', $dynamicPlaylist['videos/master.m3u8']);
        $this->assertStringContainsString('https://cdn.test/videos/480p/segment_00001.ts', $dynamicPlaylist['videos/480p/playlist.m3u8']);
        $this->assertStringContainsString('https://app.test/keys/videos/480p/keys/secret.key', $dynamicPlaylist['videos/480p/playlist.m3u8']);
    }

    #[Test]
    /**
     * @test
     */
    public function it_can_export_a_single_media_file_into_an_encryped_hls_export()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate = $this->x264()->setKiloBitrate(250);
        $highBitrate = $this->x264()->setKiloBitrate(500);

        FFMpeg::open('video.mp4')
            ->exportForHLS()
            ->setKeyFrameInterval(1)
            ->setSegmentLength(2)
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

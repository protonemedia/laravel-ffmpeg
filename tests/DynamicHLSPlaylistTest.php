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
    public function it_can_delete_an_hls_playlist_and_all_generated_files()
    {
        $this->fakeLocalVideoFile();

        $lowBitrate = $this->x264()->setKiloBitrate(250);
        $highBitrate = $this->x264()->setKiloBitrate(500);

        FFMpeg::open('video.mp4')
            ->exportForHLS()
            ->addFormat($lowBitrate)
            ->addFormat($highBitrate)
            ->save('cleanup/adaptive.m3u8');

        $this->assertTrue(Storage::disk('local')->has('cleanup/adaptive.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('cleanup/adaptive_0_250.m3u8'));
        $this->assertTrue(Storage::disk('local')->has('cleanup/adaptive_1_500.m3u8'));

        $segmentFilesBeforeCleanup = collect(Storage::disk('local')->allFiles('cleanup'))
            ->filter(fn ($path) => str_ends_with($path, '.ts'));

        $this->assertNotEmpty($segmentFilesBeforeCleanup);

        FFMpeg::dynamicHLSPlaylist()
            ->fromDisk('local')
            ->open('cleanup/adaptive.m3u8')
            ->deleteAllFiles();

        $this->assertFalse(Storage::disk('local')->has('cleanup/adaptive.m3u8'));
        $this->assertFalse(Storage::disk('local')->has('cleanup/adaptive_0_250.m3u8'));
        $this->assertFalse(Storage::disk('local')->has('cleanup/adaptive_1_500.m3u8'));

        $segmentFilesAfterCleanup = collect(Storage::disk('local')->allFiles('cleanup'))
            ->filter(fn ($path) => str_ends_with($path, '.ts'));

        $this->assertEmpty($segmentFilesAfterCleanup);
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

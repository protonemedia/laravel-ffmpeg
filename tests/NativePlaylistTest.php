<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class NativePlaylistTest extends TestCase
{
    #[Test]
    public function nested_playlists_resolve_original_references_without_reading_keys_or_media(): void
    {
        $disk = Storage::disk('memory');
        $master = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=250000\n../variants/low?token=old\n#EXT-X-STREAM-INF:BANDWIDTH=500000\n../variants/low?token=old\n";
        $variant = "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"../keys/a%20b.key?old=1\",IV=0x0123456789abcdef0123456789abcdef\n#EXTINF:2,\n../segments/part%200.ts?old=2\n#EXTINF:1,\n../segments/part%200.ts?old=2\n#EXT-X-KEY:METHOD=NONE\n#EXT-X-ENDLIST\n";
        $disk->put('shows/master/index.m3u8', $master);
        $disk->put('shows/variants/low', $variant);
        $calls = [];
        $signed = function ($target, $source, $original) use (&$calls) {
            $calls[] = [$target, $source, $original];

            return 'https://cdn.example.test/'.str_replace(' ', '%20', $target).'?signed='.count($calls);
        };
        $playlist = FFMpeg::disk($disk)->playlist('shows/master/index.m3u8')
            ->playlistUrlsUsing($signed)->keyUrlsUsing($signed)->mediaUrlsUsing($signed);
        $all = $playlist->all();
        $this->assertSame(['shows/master/index.m3u8', 'shows/variants/low'], $all->keys()->all());
        $this->assertSame([
            ['shows/variants/low', 'shows/master/index.m3u8', '../variants/low?token=old'],
            ['shows/keys/a b.key', 'shows/variants/low', '../keys/a%20b.key?old=1'],
            ['shows/segments/part 0.ts', 'shows/variants/low', '../segments/part%200.ts?old=2'],
        ], $calls);
        $this->assertSame(str_replace('../variants/low?token=old', 'https://cdn.example.test/shows/variants/low?signed=1', $master), $all->first());
        $this->assertSame(str_replace(
            ['../keys/a%20b.key?old=1', '../segments/part%200.ts?old=2'],
            ['https://cdn.example.test/shows/keys/a%20b.key?signed=2', 'https://cdn.example.test/shows/segments/part%200.ts?signed=3'],
            $variant
        ), $all->last());
        $this->assertStringContainsString('?signed=4', $playlist->get());
        $this->assertSame($master, $disk->get('shows/master/index.m3u8'));
        $this->assertSame($variant, $disk->get('shows/variants/low'));
    }

    #[Test]
    public function external_urls_have_no_disk_target_and_are_never_followed(): void
    {
        $disk = Storage::disk('memory');
        $master = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nhttps://cdn.example.test/variant?token=old\n#EXT-X-STREAM-INF:BANDWIDTH=2\n/streams/high\n";
        $disk->put('master.m3u8', $master);
        $seen = [];
        $all = FFMpeg::disk('memory')->playlist('master.m3u8')->playlistUrlsUsing(function ($target, $source, $uri) use (&$seen) {
            $seen[] = [$target, $source, $uri];

            return $uri;
        })->all();
        $this->assertSame(['master.m3u8' => $master], $all->all());
        $this->assertSame([
            [null, 'master.m3u8', 'https://cdn.example.test/variant?token=old'],
            [null, 'master.m3u8', '/streams/high'],
        ], $seen);
        $disk->put('encrypted.m3u8', "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"/private/key?id=1\",IV=0x01\n#EXTINF:1,\nhttps://cdn.example.test/segment\n");
        $output = FFMpeg::disk('memory')->playlist('encrypted.m3u8')->keyUrlsUsing(function ($target, $source, $uri) {
            $this->assertNull($target);
            $this->assertSame('encrypted.m3u8', $source);
            $this->assertSame('/private/key?id=1', $uri);

            return '/signed/key?id=2';
        })->get();
        $this->assertStringContainsString('URI="/signed/key?id=2",IV=0x01', $output);
    }

    #[Test]
    public function cycles_are_visited_once_and_get_reads_only_its_source(): void
    {
        $disk = Storage::disk('memory');
        $disk->put('master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nchild.m3u8\n");
        $this->assertStringContainsString('child.m3u8', FFMpeg::disk('memory')->playlist('master.m3u8')->get());
        $disk->put('child.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nmaster.m3u8\n");
        $this->assertSame(['master.m3u8', 'child.m3u8'], FFMpeg::disk('memory')->playlist('master.m3u8')->all()->keys()->all());
    }

    #[Test]
    public function identical_relative_uris_in_different_variants_keep_their_own_context(): void
    {
        $disk = Storage::disk('memory');
        $disk->put('master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nlow/index.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=2\nhigh/index.m3u8\n");
        foreach (['low', 'high'] as $variant) {
            $disk->put($variant.'/index.m3u8', "#EXTM3U\n#EXTINF:1,\npart.ts\n");
        }
        $all = FFMpeg::disk('memory')->playlist('master.m3u8')
            ->mediaUrlsUsing(fn ($target, $source) => '/signed/'.$target.'?source='.rawurlencode($source))->all();
        $this->assertSame("#EXTM3U\n#EXTINF:1,\n/signed/low/part.ts?source=low%2Findex.m3u8\n", $all['low/index.m3u8']);
        $this->assertSame("#EXTM3U\n#EXTINF:1,\n/signed/high/part.ts?source=high%2Findex.m3u8\n", $all['high/index.m3u8']);
    }

    #[Test]
    public function routes_return_private_playlists_and_leave_authorization_to_the_application(): void
    {
        Storage::disk('memory')->put('index.m3u8', "#EXTM3U\r\n#EXTINF:1,\r\npart.ts\r\n");
        Route::get('/native-playlist', function () {
            abort_unless(request()->query('token') === 'allowed', 403);

            return FFMpeg::disk('memory')->playlist('index.m3u8')
                ->mediaUrlsUsing(fn ($target) => 'https://cdn.example.test/'.$target.'?signature=ok');
        });
        $this->get('/native-playlist')->assertForbidden();
        $response = $this->get('/native-playlist?token=allowed')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');
        $this->assertSame("#EXTM3U\n#EXTINF:1,\nhttps://cdn.example.test/part.ts?signature=ok\n", $response->getContent());
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public static function invalidPlaylists(): array
    {
        return array_map(fn ($contents) => [$contents], [
            'not a playlist', "#EXTM3U\n# bad\rcomment\n", "#EXTM3U\n# \xFF\n", "#EXTM3U\n# \u{0085}\n",
            "#EXTM3U\npart.ts\n", "#EXTM3U\n#EXTINF:1,\n", "#EXTM3U\n#EXTINF:1,\n#EXTINF:2,\npart.ts\n",
            "#EXTM3U\n#EXTINF:1,\n%2e%2e/escape.ts\n", "#EXTM3U\n#EXTINF:1,\npart%zz.ts\n", "#EXTM3U\n#EXTINF:1,\npart%ff.ts\n",
            "#EXTM3U\n#EXTINF:1,\nfile:/secret\n", "#EXTM3U\n#EXTINF:1,\n//other.test/part.ts\n",
            "#EXTM3U\n#EXT-X-MAP:URI=\"init.mp4\"\n", "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=no-quotes\n",
            "#EXTM3U\n#EXT-X-KEY:METHOD=NONE,URI=\"key\"\n", "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"key\",URI=\"other\"\n",
        ]);
    }

    #[Test]
    #[DataProvider('invalidPlaylists')]
    public function malformed_or_unsupported_playlists_fail_without_a_partial_response(string $contents): void
    {
        Storage::disk('memory')->put('bad.m3u8', $contents);
        $this->expectException(MediaException::class);
        FFMpeg::disk('memory')->playlist('bad.m3u8')->get();
    }

    #[Test]
    public function resolver_failures_propagate_and_invalid_results_are_rejected(): void
    {
        Storage::disk('memory')->put('index.m3u8', "#EXTM3U\n#EXTINF:1,\npart.ts\n");
        foreach ([null, '', "/bad\nuri", "/bad\u{0085}uri", '/bad%zz', 'javascript:alert(1)'] as $url) {
            try {
                FFMpeg::disk('memory')->playlist('index.m3u8')->mediaUrlsUsing(fn () => $url)->get();
                $this->fail('Invalid resolver output was accepted.');
            } catch (MediaException $exception) {
                $this->assertSame(['response', 'artifact'], [$exception->phase, $exception->category]);
            }
        }
        $failure = new \RuntimeException('application failure');
        try {
            FFMpeg::disk('memory')->playlist('index.m3u8')->mediaUrlsUsing(fn () => throw $failure)->get();
            $this->fail('A callback exception was swallowed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    #[Test]
    public function missing_playlists_report_sanitized_filesystem_errors(): void
    {
        try {
            FFMpeg::disk('memory')->playlist('secret-token.m3u8')->get();
            $this->fail('Missing input was accepted.');
        } catch (MediaException $exception) {
            $this->assertSame(['response', 'filesystem'], [$exception->phase, $exception->category]);
            $this->assertStringNotContainsString('secret-token', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    #[Test]
    public function input_and_rewritten_output_are_bounded_at_sixteen_mib(): void
    {
        $disk = Storage::disk('memory');
        $exact = "#EXTM3U\n#".str_repeat('x', 16777216 - 9);
        $disk->put('exact.m3u8', $exact);
        $this->assertSame($exact, FFMpeg::disk('memory')->playlist('exact.m3u8')->get());
        $disk->put('large.m3u8', $exact.'x');
        try {
            FFMpeg::disk('memory')->playlist('large.m3u8')->get();
            $this->fail('Oversized input was accepted.');
        } catch (MediaException $exception) {
            $this->assertSame('artifact', $exception->category);
        }
        $disk->put('index.m3u8', "#EXTM3U\n#EXTINF:1,\npart.ts\n");
        $this->expectException(MediaException::class);
        FFMpeg::disk('memory')->playlist('index.m3u8')->mediaUrlsUsing(fn () => '/'.str_repeat('x', 16777216))->get();
    }

    #[Test]
    public function traversal_limits_apply_to_the_whole_collection(): void
    {
        $disk = Storage::disk('memory');
        for ($index = 0; $index < 1024; $index++) {
            $disk->put($index.'.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\n".($index + 1).".m3u8\n");
        }
        try {
            FFMpeg::disk('memory')->playlist('0.m3u8')->all();
            $this->fail('More than 1024 playlists were followed.');
        } catch (MediaException $exception) {
            $this->assertSame('artifact', $exception->category);
            $this->assertStringContainsString('1024', $exception->getMessage());
        }
        $disk->put('1023.m3u8', "#EXTM3U\n# End\n");
        $this->assertCount(1024, FFMpeg::disk('memory')->playlist('0.m3u8')->all());
        $disk->put('master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nlarge.m3u8\n");
        $disk->put('large.m3u8', "#EXTM3U\n#".str_repeat('x', 16777216 - 9));
        $this->expectException(MediaException::class);
        FFMpeg::disk('memory')->playlist('master.m3u8')->all();
    }

    #[Test]
    public function resolver_configuration_is_snapshotted_for_each_render(): void
    {
        Storage::disk('memory')->put('index.m3u8', "#EXTM3U\n#EXTINF:1,\na.ts\n#EXTINF:1,\nb.ts\n");
        $playlist = FFMpeg::disk('memory')->playlist('index.m3u8');
        $playlist->mediaUrlsUsing(function ($target) use ($playlist) {
            $playlist->mediaUrlsUsing(fn ($path) => '/next/'.$path);

            return '/current/'.$target;
        });
        $this->assertSame("#EXTM3U\n#EXTINF:1,\n/current/a.ts\n#EXTINF:1,\n/current/b.ts\n", $playlist->get());
        $this->assertSame("#EXTM3U\n#EXTINF:1,\n/next/a.ts\n#EXTINF:1,\n/next/b.ts\n", $playlist->get());
    }
}

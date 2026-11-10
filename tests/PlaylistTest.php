<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class PlaylistTest extends TestCase
{
    public function test_nested_playlists_resolve_original_references_without_reading_keys_or_media(): void
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

    public function test_external_urls_have_no_disk_target_and_are_never_followed(): void
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

    public function test_cycles_are_visited_once_and_get_reads_only_its_source(): void
    {
        $disk = Storage::disk('memory');
        $disk->put('master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nchild.m3u8\n");
        $this->assertStringContainsString('child.m3u8', FFMpeg::disk('memory')->playlist('master.m3u8')->get());
        $disk->put('child.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nmaster.m3u8\n");
        $this->assertSame(['master.m3u8', 'child.m3u8'], FFMpeg::disk('memory')->playlist('master.m3u8')->all()->keys()->all());
    }

    public function test_reopened_encrypted_playlists_delete_only_approved_original_files(): void
    {
        $disk = Storage::disk('memory');
        $files = [
            'show/master.m3u8' => "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nvariants/low\n#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"audio\",NAME=\"Audio, main\",URI=\"variants/low\"\n",
            'show/variants/low' => "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"../keys/a%20b.key?token=old\",IV=0x01\n#EXT-X-MAP:URI=\"../init.mp4\"\n#EXTINF:1,\n../parts/0.m4s\n#EXTINF:1,\n../parts/0.m4s?again=1\n#EXT-X-KEY:METHOD=NONE\n#EXT-X-STREAM-INF:BANDWIDTH=1\n../master.m3u8\n",
            'show/keys/a b.key' => '0123456789abcdef',
            'show/init.mp4' => 'initialization',
            'show/parts/0.m4s' => 'encrypted media',
        ];
        foreach ($files as $path => $contents) {
            $disk->put($path, $contents);
        }
        $disk->put('show/unrelated.ts', 'keep');
        $disk->put('other/master.m3u8', 'keep');
        $playlist = FFMpeg::disk('memory')->playlist('show/master.m3u8');
        $resolver = fn () => throw new \RuntimeException('Deletion must not invoke URL resolvers.');
        $playlist->playlistUrlsUsing($resolver)->keyUrlsUsing($resolver)->mediaUrlsUsing($resolver);
        $approved = [];
        $this->assertSame($playlist, $playlist->deleteAllFiles(function (string $path) use ($disk, $files, &$approved): bool {
            foreach (array_keys($files) as $existing) {
                $this->assertTrue($disk->exists($existing), 'Deletion started before all approvals.');
            }
            $approved[] = $path;

            return array_key_exists($path, $files);
        }));
        $this->assertSame(array_keys($files), $approved);
        $this->assertEqualsCanonicalizing(['show/unrelated.ts', 'other/master.m3u8'], $disk->allFiles());
    }

    public function test_an_old_native_export_can_be_reopened_and_deleted_without_its_result_inventory(): void
    {
        $binary = $this->useRealFFMpeg();
        $disk = $this->fakeLocalDisk();
        $source = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=80x48:rate=10:duration=1.2',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-threads', '1', $disk->path('source.mp4')]);
        $this->assertProcessSucceeded($source);
        FFMpeg::disk('local')->open('source.mp4')->hls(segmentDuration: 0.5)
            ->video(bitrate: '100k', preset: 'ultrafast')->variant('low', fn () => null)->variant('high', fn () => null)
            ->save('old/master.m3u8');
        $generated = $disk->allFiles('old');
        $this->assertGreaterThanOrEqual(5, count($generated));
        $disk->put('old/unrelated.ts', 'keep');
        FFMpeg::disk('local')->playlist('old/master.m3u8')->deleteAllFiles(fn (string $path): bool => in_array($path, $generated, true));
        $this->assertSame(['old/unrelated.ts'], $disk->allFiles('old'));
        $this->assertTrue($disk->exists('source.mp4'));
    }

    public function test_refusing_a_shared_file_or_throwing_during_approval_deletes_nothing(): void
    {
        $disk = Storage::disk('memory');
        $disk->put('show/master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nchild.m3u8\n");
        $disk->put('show/child.m3u8', "#EXTM3U\n#EXTINF:1,\n../shared.ts\n");
        $disk->put('shared.ts', 'shared with another export');
        foreach ([false, 1, new \RuntimeException('Application approval failed.')] as $refusal) {
            $seen = [];
            try {
                FFMpeg::disk($disk)->playlist('show/master.m3u8')->deleteAllFiles(function (string $path) use (&$seen, $refusal) {
                    $seen[] = $path;
                    if ($path === 'shared.ts') {
                        return $refusal instanceof \RuntimeException ? throw $refusal : $refusal;
                    }

                    return true;
                });
                $this->fail('An unapproved file was deleted.');
            } catch (MediaException $exception) {
                $this->assertSame('authorization', $exception->category);
            } catch (\RuntimeException $exception) {
                $this->assertSame($refusal, $exception);
            }
            $this->assertSame(['show/master.m3u8', 'show/child.m3u8', 'shared.ts'], $seen);
            $this->assertCount(3, $disk->allFiles());
        }
    }

    public static function unsafeDeletionReferences(): array
    {
        return array_map(fn ($uri) => [$uri], [
            '../../outside.ts', '%2e%2e/%2e%2e/outside.ts', '/outside.ts', '//other.test/file',
            'https://other.test/file', '%2Foutside.ts', '%5Coutside.ts', 'file:/outside.ts',
            '%00outside.ts', '%ff.ts', 'part%zz.ts', '%68ttps%3Aoutside.ts',
        ]);
    }

    #[DataProvider('unsafeDeletionReferences')]
    public function test_unsafe_references_abort_discovery_before_approval_or_deletion(string $uri): void
    {
        $disk = Storage::disk('memory');
        $disk->put('show/master.m3u8', "#EXTM3U\n#EXTINF:1,\nsafe.ts\n#EXTINF:1,\n{$uri}\n");
        $disk->put('show/safe.ts', 'keep');
        $disk->put('outside.ts', 'keep');
        $this->assertThrows(
            fn () => FFMpeg::disk($disk)->playlist('show/master.m3u8')->deleteAllFiles(function () {
                $this->fail('Approval ran before discovery finished.');
            }),
            fn (MediaException $exception) => $exception->category === 'artifact',
        );

        $this->assertCount(3, $disk->allFiles());
    }

    #[DataProvider('invalidPlaylists')]
    public function test_malformed_nested_playlists_abort_deletion_before_approval(string $contents): void
    {
        $disk = Storage::disk('memory');
        $disk->put('master.m3u8', "#EXTM3U\n#EXTINF:1,\nsafe.ts\n#EXT-X-STREAM-INF:BANDWIDTH=1\nbad.m3u8\n");
        $disk->put('safe.ts', 'keep');
        $disk->put('bad.m3u8', $contents);
        $this->assertThrows(
            fn () => FFMpeg::disk($disk)->playlist('master.m3u8')->deleteAllFiles(function () {
                $this->fail('Approval ran before validation finished.');
            }),
            fn (MediaException $exception) => $exception->category === 'artifact',
        );

        $this->assertCount(3, $disk->allFiles());
    }

    public function test_deletion_deduplicates_reads_and_deletes_and_stops_on_adapter_failure(): void
    {
        foreach ([false, new \RuntimeException('secret adapter details')] as $failure) {
            $disk = \Mockery::mock(Filesystem::class);
            foreach (['master.m3u8' => 'child.m3u8', 'child.m3u8' => 'master.m3u8'] as $path => $child) {
                $stream = fopen('php://temp', 'w+');
                fwrite($stream, "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\n{$child}\n#EXTINF:1,\npart.ts\n#EXTINF:1,\npart.ts\n");
                rewind($stream);
                $disk->shouldReceive('readStream')->once()->with($path)->andReturn($stream);
            }
            $disk->shouldReceive('delete')->once()->with('master.m3u8')->andReturn(true);
            $expectation = $disk->shouldReceive('delete')->once()->with('child.m3u8');
            if ($failure instanceof \RuntimeException) {
                $expectation->andThrow($failure);
            } else {
                $expectation->andReturn($failure);
            }
            $disk->shouldNotReceive('delete')->with('part.ts');
            $approved = [];
            try {
                FFMpeg::disk($disk)->playlist('master.m3u8')->deleteAllFiles(function (string $path) use (&$approved): bool {
                    $approved[] = $path;

                    return true;
                });
                $this->fail('An adapter failure was ignored.');
            } catch (MediaException $exception) {
                $this->assertSame(['response', 'filesystem'], [$exception->phase, $exception->category]);
                $this->assertStringNotContainsString('secret', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
            $this->assertSame(['master.m3u8', 'child.m3u8', 'part.ts'], $approved);
        }
    }

    public function test_an_unreadable_child_prevents_all_approval_and_deletion(): void
    {
        $disk = \Mockery::mock(Filesystem::class);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "#EXTM3U\n#EXTINF:1,\nsafe.ts\n#EXT-X-STREAM-INF:BANDWIDTH=1\nmissing.m3u8\n");
        rewind($stream);
        $disk->shouldReceive('readStream')->once()->with('master.m3u8')->andReturn($stream);
        $disk->shouldReceive('readStream')->once()->with('missing.m3u8')->andReturn(false);
        $disk->shouldNotReceive('delete');
        $this->assertThrows(
            fn () => FFMpeg::disk($disk)->playlist('master.m3u8')->deleteAllFiles(function () {
                $this->fail('Approval ran before every playlist was read.');
            }),
            fn (MediaException $exception) => $exception->category === 'filesystem',
        );
    }

    public function test_identical_relative_uris_in_different_variants_keep_their_own_context(): void
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

    public function test_routes_return_private_playlists_and_leave_authorization_to_the_application(): void
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
            "#EXTM3U\n#EXT-X-MAP:URI=init.mp4\n", "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=no-quotes\n",
            "#EXTM3U\n#EXT-X-KEY:METHOD=NONE,URI=\"key\"\n", "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"key\",URI=\"other\"\n",
        ]);
    }

    #[DataProvider('invalidPlaylists')]
    public function test_malformed_or_unsupported_playlists_fail_without_a_partial_response(string $contents): void
    {
        Storage::disk('memory')->put('bad.m3u8', $contents);
        $this->expectException(MediaException::class);
        FFMpeg::disk('memory')->playlist('bad.m3u8')->get();
    }

    public function test_resolver_failures_propagate_and_invalid_results_are_rejected(): void
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
        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->playlist('index.m3u8')->mediaUrlsUsing(fn () => throw $failure)->get(),
            fn (\RuntimeException $exception) => $exception === $failure,
        );
    }

    public function test_missing_playlists_report_sanitized_filesystem_errors(): void
    {
        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->playlist('secret-token.m3u8')->get(),
            fn (MediaException $exception) => $exception->phase === 'response'
                && $exception->category === 'filesystem'
                && ! str_contains($exception->getMessage(), 'secret-token')
                && $exception->getPrevious() === null,
        );
    }

    public function test_input_and_rewritten_output_are_bounded_at_sixteen_mib(): void
    {
        $disk = Storage::disk('memory');
        $exact = "#EXTM3U\n#".str_repeat('x', 16777216 - 9);
        $disk->put('exact.m3u8', $exact);
        $this->assertSame($exact, FFMpeg::disk('memory')->playlist('exact.m3u8')->get());
        $disk->put('large.m3u8', $exact.'x');
        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->playlist('large.m3u8')->get(),
            fn (MediaException $exception) => $exception->category === 'artifact',
        );
        $disk->put('index.m3u8', "#EXTM3U\n#EXTINF:1,\npart.ts\n");
        $this->expectException(MediaException::class);
        FFMpeg::disk('memory')->playlist('index.m3u8')->mediaUrlsUsing(fn () => '/'.str_repeat('x', 16777216))->get();
    }

    public function test_traversal_limits_apply_to_the_whole_collection(): void
    {
        $disk = Storage::disk('memory');
        for ($index = 0; $index < 1024; $index++) {
            $disk->put($index.'.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\n".($index + 1).".m3u8\n");
        }
        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->playlist('0.m3u8')->all(),
            fn (MediaException $exception) => $exception->category === 'artifact'
                && str_contains($exception->getMessage(), '1024'),
        );
        $disk->put('1023.m3u8', "#EXTM3U\n# End\n");
        $this->assertCount(1024, FFMpeg::disk('memory')->playlist('0.m3u8')->all());
        $disk->put('master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nlarge.m3u8\n");
        $disk->put('large.m3u8', "#EXTM3U\n#".str_repeat('x', 16777216 - 9));
        $this->expectException(MediaException::class);
        FFMpeg::disk('memory')->playlist('master.m3u8')->all();
    }

    public function test_resolver_configuration_is_snapshotted_for_each_render(): void
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

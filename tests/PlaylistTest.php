<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class PlaylistTest extends TestCase
{
    public function test_nested_playlists_resolve_original_references_without_reading_keys_or_media(): void
    {
        $disk = Storage::disk('memory');
        $master = <<<'M3U8'
            #EXTM3U
            #EXT-X-STREAM-INF:BANDWIDTH=250000
            ../variants/low?token=old
            #EXT-X-STREAM-INF:BANDWIDTH=500000
            ../variants/low?token=old

            M3U8;
        $variant = <<<'M3U8'
            #EXTM3U
            #EXT-X-KEY:METHOD=AES-128,URI="../keys/a%20b.key?old=1",IV=0x0123456789abcdef0123456789abcdef
            #EXTINF:2,
            ../segments/part%200.ts?old=2
            #EXTINF:1,
            ../segments/part%200.ts?old=2
            #EXT-X-KEY:METHOD=NONE
            #EXT-X-ENDLIST

            M3U8;
        $disk->put('shows/master/index.m3u8', $master);
        $disk->put('shows/variants/low', $variant);
        $calls = [];
        $signed = function ($target, $source, $original) use (&$calls) {
            $calls[] = [$target, $source, $original];

            return 'https://cdn.example.test/'.str_replace(' ', '%20', $target).'?signed='.count($calls);
        };
        $playlist = FFMpeg::disk($disk)
            ->playlist('shows/master/index.m3u8')
            ->playlistUrlsUsing($signed)
            ->keyUrlsUsing($signed)
            ->mediaUrlsUsing($signed);
        $all = $playlist->all();

        $this->assertSame(['shows/master/index.m3u8', 'shows/variants/low'], $all->keys()->all());
        $this->assertSame([
            ['shows/variants/low', 'shows/master/index.m3u8', '../variants/low?token=old'],
            ['shows/keys/a b.key', 'shows/variants/low', '../keys/a%20b.key?old=1'],
            ['shows/segments/part 0.ts', 'shows/variants/low', '../segments/part%200.ts?old=2'],
        ], $calls);
        $this->assertSame(
            str_replace('../variants/low?token=old', 'https://cdn.example.test/shows/variants/low?signed=1', $master),
            $all->first(),
        );
        $this->assertSame(str_replace(
            ['../keys/a%20b.key?old=1', '../segments/part%200.ts?old=2'],
            [
                'https://cdn.example.test/shows/keys/a%20b.key?signed=2',
                'https://cdn.example.test/shows/segments/part%200.ts?signed=3',
            ],
            $variant
        ), $all->last());
        $this->assertStringContainsString('?signed=4', $playlist->get());
        $this->assertSame($master, $disk->get('shows/master/index.m3u8'));
        $this->assertSame($variant, $disk->get('shows/variants/low'));
    }

    public function test_external_playlist_urls_have_no_disk_target_and_are_never_followed(): void
    {
        $disk = Storage::disk('memory');
        $master = <<<'M3U8'
            #EXTM3U
            #EXT-X-STREAM-INF:BANDWIDTH=1
            https://cdn.example.test/variant?token=old
            #EXT-X-STREAM-INF:BANDWIDTH=2
            /streams/high

            M3U8;
        $disk->put('master.m3u8', $master);
        $seen = [];

        $all = FFMpeg::disk('memory')
            ->playlist('master.m3u8')
            ->playlistUrlsUsing(function ($target, $source, $uri) use (&$seen) {
                $seen[] = [$target, $source, $uri];

                return $uri;
            })
            ->all();

        $this->assertSame(['master.m3u8' => $master], $all->all());
        $this->assertSame([
            [null, 'master.m3u8', 'https://cdn.example.test/variant?token=old'],
            [null, 'master.m3u8', '/streams/high'],
        ], $seen);
    }

    public function test_external_key_urls_have_no_disk_target(): void
    {
        Storage::disk('memory')->put('encrypted.m3u8', <<<'M3U8'
            #EXTM3U
            #EXT-X-KEY:METHOD=AES-128,URI="/private/key?id=1",IV=0x01
            #EXTINF:1,
            https://cdn.example.test/segment

            M3U8);

        $output = FFMpeg::disk('memory')
            ->playlist('encrypted.m3u8')
            ->keyUrlsUsing(function ($target, $source, $uri) {
                $this->assertNull($target);
                $this->assertSame('encrypted.m3u8', $source);
                $this->assertSame('/private/key?id=1', $uri);

                return '/signed/key?id=2';
            })
            ->get();

        $this->assertStringContainsString('URI="/signed/key?id=2",IV=0x01', $output);
    }

    public function test_get_reads_only_its_source(): void
    {
        Storage::disk('memory')->put('master.m3u8', <<<'M3U8'
            #EXTM3U
            #EXT-X-STREAM-INF:BANDWIDTH=1
            child.m3u8

            M3U8);

        $this->assertStringContainsString('child.m3u8', FFMpeg::disk('memory')->playlist('master.m3u8')->get());
    }

    public function test_cycles_are_visited_once(): void
    {
        $disk = Storage::disk('memory');
        $disk->put('master.m3u8', <<<'M3U8'
            #EXTM3U
            #EXT-X-STREAM-INF:BANDWIDTH=1
            child.m3u8

            M3U8);
        $disk->put('child.m3u8', <<<'M3U8'
            #EXTM3U
            #EXT-X-STREAM-INF:BANDWIDTH=1
            master.m3u8

            M3U8);

        $this->assertSame(
            ['master.m3u8', 'child.m3u8'],
            FFMpeg::disk('memory')->playlist('master.m3u8')->all()->keys()->all(),
        );
    }

    public function test_reopened_encrypted_playlists_delete_only_approved_original_files(): void
    {
        $disk = Storage::disk('memory');
        $files = [
            'show/master.m3u8' => <<<'M3U8'
                #EXTM3U
                #EXT-X-STREAM-INF:BANDWIDTH=1
                variants/low
                #EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="Audio, main",URI="variants/low"

                M3U8,
            'show/variants/low' => <<<'M3U8'
                #EXTM3U
                #EXT-X-KEY:METHOD=AES-128,URI="../keys/a%20b.key?token=old",IV=0x01
                #EXT-X-MAP:URI="../init.mp4"
                #EXTINF:1,
                ../parts/0.m4s
                #EXTINF:1,
                ../parts/0.m4s?again=1
                #EXT-X-KEY:METHOD=NONE
                #EXT-X-STREAM-INF:BANDWIDTH=1
                ../master.m3u8

                M3U8,
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
        $playlist->playlistUrlsUsing($resolver)
            ->keyUrlsUsing($resolver)
            ->mediaUrlsUsing($resolver);
        $approved = [];

        $returned = $playlist->deleteAllFiles(function (string $path) use ($disk, $files, &$approved): bool {
            foreach (array_keys($files) as $existing) {
                $this->assertTrue($disk->exists($existing), 'Deletion started before all approvals.');
            }
            $approved[] = $path;

            return array_key_exists($path, $files);
        });

        $this->assertSame($playlist, $returned);
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

        FFMpeg::disk('local')
            ->open('source.mp4')
            ->hls(segmentDuration: 0.5)
            ->video(bitrate: '100k', preset: 'ultrafast')
            ->variant('low', fn () => null)
            ->variant('high', fn () => null)
            ->save('old/master.m3u8');
        $generated = $disk->allFiles('old');

        $this->assertGreaterThanOrEqual(5, count($generated));

        $disk->put('old/unrelated.ts', 'keep');
        FFMpeg::disk('local')
            ->playlist('old/master.m3u8')
            ->deleteAllFiles(fn (string $path): bool => in_array($path, $generated, true));

        $this->assertSame(['old/unrelated.ts'], $disk->allFiles('old'));
        $this->assertTrue($disk->exists('source.mp4'));
    }

    #[TestWith([false], 'false')]
    #[TestWith([1], 'truthy non-boolean')]
    public function test_refusing_a_shared_file_deletes_nothing(mixed $refusal): void
    {
        $disk = $this->sharedFilePlaylist();
        $seen = [];

        $this->assertThrows(
            function () use ($disk, &$seen, $refusal) {
                FFMpeg::disk($disk)
                    ->playlist('show/master.m3u8')
                    ->deleteAllFiles(function (string $path) use (&$seen, $refusal) {
                        $seen[] = $path;

                        return $path === 'shared.ts' ? $refusal : true;
                    });
            },
            fn (MediaException $exception) => $exception->category === 'authorization',
        );

        $this->assertSame(['show/master.m3u8', 'show/child.m3u8', 'shared.ts'], $seen);
        $this->assertCount(3, $disk->allFiles());
    }

    public function test_throwing_during_approval_deletes_nothing(): void
    {
        $disk = $this->sharedFilePlaylist();
        $failure = new \RuntimeException('Application approval failed.');
        $seen = [];

        $this->assertThrows(
            function () use ($disk, &$seen, $failure) {
                FFMpeg::disk($disk)
                    ->playlist('show/master.m3u8')
                    ->deleteAllFiles(function (string $path) use (&$seen, $failure) {
                        $seen[] = $path;

                        return $path === 'shared.ts' ? throw $failure : true;
                    });
            },
            fn (\RuntimeException $exception) => $exception === $failure,
        );

        $this->assertSame(['show/master.m3u8', 'show/child.m3u8', 'shared.ts'], $seen);
        $this->assertCount(3, $disk->allFiles());
    }

    /**
     * Get a disk holding a playlist that references a file shared with another export.
     */
    protected function sharedFilePlaylist(): FilesystemAdapter
    {
        $disk = Storage::disk('memory');
        $disk->put('show/master.m3u8', <<<'M3U8'
            #EXTM3U
            #EXT-X-STREAM-INF:BANDWIDTH=1
            child.m3u8

            M3U8);
        $disk->put('show/child.m3u8', <<<'M3U8'
            #EXTM3U
            #EXTINF:1,
            ../shared.ts

            M3U8);
        $disk->put('shared.ts', 'shared with another export');

        return $disk;
    }

    public static function unsafeDeletionReferences(): array
    {
        return [
            'parent traversal' => ['../../outside.ts'],
            'encoded traversal' => ['%2e%2e/%2e%2e/outside.ts'],
            'absolute path' => ['/outside.ts'],
            'network path' => ['//other.test/file'],
            'URL' => ['https://other.test/file'],
            'encoded slash' => ['%2Foutside.ts'],
            'encoded backslash' => ['%5Coutside.ts'],
            'file scheme' => ['file:/outside.ts'],
            'encoded null byte' => ['%00outside.ts'],
            'encoded invalid UTF-8' => ['%ff.ts'],
            'invalid percent encoding' => ['part%zz.ts'],
            'encoded scheme' => ['%68ttps%3Aoutside.ts'],
        ];
    }

    #[DataProvider('unsafeDeletionReferences')]
    public function test_unsafe_references_abort_discovery_before_approval_or_deletion(string $uri): void
    {
        $disk = Storage::disk('memory');
        $disk->put('show/master.m3u8', sprintf(<<<'M3U8'
            #EXTM3U
            #EXTINF:1,
            safe.ts
            #EXTINF:1,
            %s

            M3U8, $uri));
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
        $disk->put('master.m3u8', <<<'M3U8'
            #EXTM3U
            #EXTINF:1,
            safe.ts
            #EXT-X-STREAM-INF:BANDWIDTH=1
            bad.m3u8

            M3U8);
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

    #[TestWith([false], 'false return')]
    #[TestWith([true], 'thrown exception')]
    public function test_deletion_deduplicates_reads_and_deletes_and_stops_on_adapter_failure(bool $throws): void
    {
        $disk = \Mockery::mock(Filesystem::class);
        foreach (['master.m3u8' => 'child.m3u8', 'child.m3u8' => 'master.m3u8'] as $path => $child) {
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, sprintf(<<<'M3U8'
                #EXTM3U
                #EXT-X-STREAM-INF:BANDWIDTH=1
                %s
                #EXTINF:1,
                part.ts
                #EXTINF:1,
                part.ts

                M3U8, $child));
            rewind($stream);
            $disk->shouldReceive('readStream')
                ->once()
                ->with($path)
                ->andReturn($stream);
        }
        $disk->shouldReceive('delete')
            ->once()
            ->with('master.m3u8')
            ->andReturn(true);
        $expectation = $disk->shouldReceive('delete')
            ->once()
            ->with('child.m3u8');
        if ($throws) {
            $expectation->andThrow(new \RuntimeException('secret adapter details'));
        } else {
            $expectation->andReturn(false);
        }
        $disk->shouldNotReceive('delete')
            ->with('part.ts');
        $approved = [];

        $this->assertThrows(
            function () use ($disk, &$approved) {
                FFMpeg::disk($disk)
                    ->playlist('master.m3u8')
                    ->deleteAllFiles(function (string $path) use (&$approved): bool {
                        $approved[] = $path;

                        return true;
                    });
            },
            fn (MediaException $exception) => $exception->phase === 'response'
                && $exception->category === 'filesystem'
                && ! str_contains($exception->getMessage(), 'secret')
                && $exception->getPrevious() === null,
        );

        $this->assertSame(['master.m3u8', 'child.m3u8', 'part.ts'], $approved);
    }

    public function test_an_unreadable_child_prevents_all_approval_and_deletion(): void
    {
        $disk = \Mockery::mock(Filesystem::class);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, <<<'M3U8'
            #EXTM3U
            #EXTINF:1,
            safe.ts
            #EXT-X-STREAM-INF:BANDWIDTH=1
            missing.m3u8

            M3U8);
        rewind($stream);
        $disk->shouldReceive('readStream')
            ->once()
            ->with('master.m3u8')
            ->andReturn($stream);
        $disk->shouldReceive('readStream')
            ->once()
            ->with('missing.m3u8')
            ->andReturn(false);
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
        $disk->put('master.m3u8', <<<'M3U8'
            #EXTM3U
            #EXT-X-STREAM-INF:BANDWIDTH=1
            low/index.m3u8
            #EXT-X-STREAM-INF:BANDWIDTH=2
            high/index.m3u8

            M3U8);
        foreach (['low', 'high'] as $variant) {
            $disk->put($variant.'/index.m3u8', <<<'M3U8'
                #EXTM3U
                #EXTINF:1,
                part.ts

                M3U8);
        }
        $all = FFMpeg::disk('memory')
            ->playlist('master.m3u8')
            ->mediaUrlsUsing(fn ($target, $source) => '/signed/'.$target.'?source='.rawurlencode($source))
            ->all();

        $this->assertSame(<<<'M3U8'
            #EXTM3U
            #EXTINF:1,
            /signed/low/part.ts?source=low%2Findex.m3u8

            M3U8, $all['low/index.m3u8']);
        $this->assertSame(<<<'M3U8'
            #EXTM3U
            #EXTINF:1,
            /signed/high/part.ts?source=high%2Findex.m3u8

            M3U8, $all['high/index.m3u8']);
    }

    public function test_routes_return_private_playlists_and_leave_authorization_to_the_application(): void
    {
        Storage::disk('memory')->put('index.m3u8', "#EXTM3U\r\n#EXTINF:1,\r\npart.ts\r\n");
        Route::get('/native-playlist', function () {
            abort_unless(request()->query('token') === 'allowed', 403);

            return FFMpeg::disk('memory')
                ->playlist('index.m3u8')
                ->mediaUrlsUsing(fn ($target) => 'https://cdn.example.test/'.$target.'?signature=ok');
        });
        $this->get('/native-playlist')
            ->assertForbidden();
        $response = $this->get('/native-playlist?token=allowed')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');

        $this->assertSame(<<<'M3U8'
            #EXTM3U
            #EXTINF:1,
            https://cdn.example.test/part.ts?signature=ok

            M3U8, $response->getContent());
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public static function invalidPlaylists(): array
    {
        return [
            'missing header' => ['not a playlist'],
            'carriage return in a comment' => ["#EXTM3U\n# bad\rcomment\n"],
            'invalid UTF-8' => ["#EXTM3U\n# \xFF\n"],
            'next line character' => ["#EXTM3U\n# \u{0085}\n"],
            'segment without a duration' => ["#EXTM3U\npart.ts\n"],
            'duration without a segment' => ["#EXTM3U\n#EXTINF:1,\n"],
            'two durations for one segment' => ["#EXTM3U\n#EXTINF:1,\n#EXTINF:2,\npart.ts\n"],
            'encoded traversal' => ["#EXTM3U\n#EXTINF:1,\n%2e%2e/escape.ts\n"],
            'invalid percent encoding' => ["#EXTM3U\n#EXTINF:1,\npart%zz.ts\n"],
            'encoded invalid UTF-8' => ["#EXTM3U\n#EXTINF:1,\npart%ff.ts\n"],
            'file scheme' => ["#EXTM3U\n#EXTINF:1,\nfile:/secret\n"],
            'network path' => ["#EXTM3U\n#EXTINF:1,\n//other.test/part.ts\n"],
            'unquoted map URI' => ["#EXTM3U\n#EXT-X-MAP:URI=init.mp4\n"],
            'unquoted key URI' => ["#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=no-quotes\n"],
            'key URI without encryption' => ["#EXTM3U\n#EXT-X-KEY:METHOD=NONE,URI=\"key\"\n"],
            'duplicate key URI' => ["#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"key\",URI=\"other\"\n"],
        ];
    }

    #[DataProvider('invalidPlaylists')]
    public function test_malformed_or_unsupported_playlists_fail_without_a_partial_response(string $contents): void
    {
        Storage::disk('memory')->put('bad.m3u8', $contents);

        $this->expectException(MediaException::class);

        FFMpeg::disk('memory')
            ->playlist('bad.m3u8')
            ->get();
    }

    #[DataProvider('invalidResolverResultProvider')]
    public function test_invalid_resolver_results_are_rejected(?string $url): void
    {
        Storage::disk('memory')->put('index.m3u8', <<<'M3U8'
            #EXTM3U
            #EXTINF:1,
            part.ts

            M3U8);

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->playlist('index.m3u8')->mediaUrlsUsing(fn () => $url)->get(),
            fn (MediaException $exception) => $exception->phase === 'response' && $exception->category === 'artifact',
        );
    }

    public static function invalidResolverResultProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'newline' => ["/bad\nuri"],
            'next line character' => ["/bad\u{0085}uri"],
            'invalid percent encoding' => ['/bad%zz'],
            'javascript scheme' => ['javascript:alert(1)'],
        ];
    }

    public function test_resolver_failures_propagate(): void
    {
        Storage::disk('memory')->put('index.m3u8', <<<'M3U8'
            #EXTM3U
            #EXTINF:1,
            part.ts

            M3U8);
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

    public function test_input_is_bounded_at_sixteen_mib(): void
    {
        $disk = Storage::disk('memory');
        $exact = "#EXTM3U\n#".str_repeat('x', 16777216 - 9);
        $disk->put('exact.m3u8', $exact);
        $disk->put('large.m3u8', $exact.'x');

        $this->assertSame($exact, FFMpeg::disk('memory')->playlist('exact.m3u8')->get());
        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->playlist('large.m3u8')->get(),
            fn (MediaException $exception) => $exception->category === 'artifact',
        );
    }

    public function test_rewritten_output_is_bounded_at_sixteen_mib(): void
    {
        Storage::disk('memory')->put('index.m3u8', <<<'M3U8'
            #EXTM3U
            #EXTINF:1,
            part.ts

            M3U8);

        $this->expectException(MediaException::class);

        FFMpeg::disk('memory')
            ->playlist('index.m3u8')
            ->mediaUrlsUsing(fn () => '/'.str_repeat('x', 16777216))
            ->get();
    }

    public function test_traversal_follows_at_most_1024_playlists(): void
    {
        $disk = Storage::disk('memory');
        for ($index = 0; $index < 1024; $index++) {
            $disk->put($index.'.m3u8', sprintf(<<<'M3U8'
                #EXTM3U
                #EXT-X-STREAM-INF:BANDWIDTH=1
                %d.m3u8

                M3U8, $index + 1));
        }

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->playlist('0.m3u8')->all(),
            fn (MediaException $exception) => $exception->category === 'artifact'
                && str_contains($exception->getMessage(), '1024'),
        );

        $disk->put('1023.m3u8', <<<'M3U8'
            #EXTM3U
            # End

            M3U8);

        $this->assertCount(1024, FFMpeg::disk('memory')->playlist('0.m3u8')->all());
    }

    public function test_traversal_bounds_the_whole_collection_at_sixteen_mib(): void
    {
        $disk = Storage::disk('memory');
        $disk->put('master.m3u8', <<<'M3U8'
            #EXTM3U
            #EXT-X-STREAM-INF:BANDWIDTH=1
            large.m3u8

            M3U8);
        $disk->put('large.m3u8', "#EXTM3U\n#".str_repeat('x', 16777216 - 9));

        $this->expectException(MediaException::class);

        FFMpeg::disk('memory')
            ->playlist('master.m3u8')
            ->all();
    }

    public function test_resolver_configuration_is_snapshotted_for_each_render(): void
    {
        Storage::disk('memory')->put('index.m3u8', <<<'M3U8'
            #EXTM3U
            #EXTINF:1,
            a.ts
            #EXTINF:1,
            b.ts

            M3U8);
        $playlist = FFMpeg::disk('memory')->playlist('index.m3u8');
        $playlist->mediaUrlsUsing(function ($target) use ($playlist) {
            $playlist->mediaUrlsUsing(fn ($path) => '/next/'.$path);

            return '/current/'.$target;
        });

        $this->assertSame(<<<'M3U8'
            #EXTM3U
            #EXTINF:1,
            /current/a.ts
            #EXTINF:1,
            /current/b.ts

            M3U8, $playlist->get());
        $this->assertSame(<<<'M3U8'
            #EXTM3U
            #EXTINF:1,
            /next/a.ts
            #EXTINF:1,
            /next/b.ts

            M3U8, $playlist->get());
    }
}

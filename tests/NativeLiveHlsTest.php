<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\LiveHlsResult;
use ProtoneMedia\LaravelFFMpeg\LiveHlsSource;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\PendingLiveHls;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Symfony\Component\Process\Process as Server;

class NativeLiveHlsTest extends TestCase
{
    private string $root;

    private string $binary;

    protected function setUp(): void
    {
        parent::setUp();
        $probe = getenv('FFMPEG_9_FFPROBE');
        $this->binary = $probe ? dirname($probe).'/ffmpeg' : '';
        if (! is_executable($this->binary)) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE to run native live acceptance.');
        }
        $this->root = sys_get_temp_dir().'/native-live-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        config(['laravel-ffmpeg.temporary_files_root' => $this->root,
            'laravel-ffmpeg.ffmpeg.binaries' => $this->binary,
            'laravel-ffmpeg.ffprobe.binaries' => dirname($this->binary).'/ffprobe',
            'laravel-ffmpeg.timeout' => 30, 'laravel-ffmpeg.idle_timeout' => null]);
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $this->root.'/storage']]);
        Storage::forgetDisk('local');
        Storage::disk('local')->makeDirectory('');
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    private function source(float $duration = 5): Media
    {
        $result = Process::run([$this->binary, '-v', 'error', '-y', '-f', 'lavfi', '-i',
            'testsrc2=size=128x72:rate=20:duration='.$duration, '-f', 'lavfi', '-i',
            'sine=sample_rate=48000:duration='.$duration, '-c:v', 'libx264', '-preset', 'ultrafast',
            '-threads', '1', '-c:a', 'aac', Storage::disk('local')->path('source.mkv')]);
        $this->assertTrue($result->successful(), $result->errorOutput());
        $probe = Process::run([dirname($this->binary).'/ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', Storage::disk('local')->path('source.mkv')]);
        $this->assertGreaterThanOrEqual($duration, (float) trim($probe->output()));

        return new Media('local', 'source.mkv');
    }

    private function children(): array
    {
        $children = [];
        foreach (glob('/proc/[0-9]*/status') ?: [] as $path) {
            $status = @file_get_contents($path);
            $pid = basename(dirname($path));
            if ($status !== false && preg_match('/^PPid:\s*'.getmypid().'\s*$/m', $status)
                && str_contains(@file_get_contents('/proc/'.$pid.'/cmdline') ?: '', $this->binary)) {
                $children[] = $pid;
            }
        }

        return $children;
    }

    #[Test]
    public function publishes_decodable_segments_while_encoder_is_alive_then_slides_cancels_and_reaps(): void
    {
        $source = $this->source();
        $disk = Storage::disk('local');
        $seen = [];
        $pids = [];
        $live = new PendingLiveHls($source, $disk, 0.4, 2);
        $live->onPublish(function (LiveHlsResult $snapshot) use ($disk, &$seen, &$pids, $live): void {
            $this->assertSame('running', $snapshot->status);
            $text = $disk->get($snapshot->playlist);
            $this->assertStringNotContainsString('#EXT-X-ENDLIST', $text);
            $this->assertLessThanOrEqual(2, count($snapshot->segments));
            $this->assertLessThanOrEqual(5, count($disk->allFiles('live')));
            $pids = array_unique([...$pids, ...$this->children()]);
            $this->assertNotEmpty($pids, 'Encoder must still be alive during publication.');
            foreach ($snapshot->segments as $segment) {
                $this->assertTrue($disk->exists($segment));
                $this->assertStringContainsString(basename($segment), $text);
            }
            if ($seen === []) {
                $decode = Process::run([$this->binary, '-v', 'error', '-i', $disk->path($snapshot->segments[0]), '-f', 'null', '-']);
                $this->assertTrue($decode->successful(), $decode->errorOutput());
            }
            $local = glob($this->root.'/laravel-ffmpeg-*/live-*.ts*') ?: [];
            $this->assertLessThanOrEqual(7, count($local));
            $seen[] = $snapshot->lastSequence;
            if ($snapshot->publishedSegments >= 7) {
                $live->stop();
            }
        });
        $result = $live->run('live');
        $this->assertSame('cancelled', $result->status);
        $this->assertGreaterThanOrEqual(7, $result->publishedSegments);
        $this->assertGreaterThan(0, $result->firstSequence);
        $sorted = $seen;
        sort($sorted);
        $this->assertSame($sorted, $seen);
        foreach ($pids as $pid) {
            $this->assertDirectoryDoesNotExist('/proc/'.$pid, 'Child must have been reaped.');
        }
        $this->assertSame([], glob($this->root.'/laravel-ffmpeg-*'));
        $this->assertStringNotContainsString('#EXT-X-ENDLIST', $disk->get('live/index.m3u8'));
    }

    #[Test]
    public function clean_eof_finishes_but_reconnects_keep_numbers_and_insert_discontinuities(): void
    {
        $source = $this->source(1.2);
        $disk = Storage::disk('local');
        $complete = (new PendingLiveHls($source, $disk, 0.4, 3))->run('finite');
        $this->assertSame('completed', $complete->status);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $disk->get($complete->playlist));

        $observed = false;
        $live = (new PendingLiveHls($source, $disk, 0.4, 3))->retry(1, 0, reconnectOnEof: true);
        $live->onPublish(function (LiveHlsResult $snapshot) use ($disk, $live, &$observed): void {
            if ($snapshot->attempts === 2) {
                $this->assertGreaterThanOrEqual(3, $snapshot->lastSequence);
                $this->assertStringContainsString("#EXT-X-DISCONTINUITY\n", $disk->get($snapshot->playlist));
                $observed = true;
                $live->stop();
            }
        });
        $result = $live->run('reconnect');
        $this->assertTrue($observed);
        $this->assertSame(2, $result->attempts);
        $this->assertSame('cancelled', $result->status);
        // stop() ends one session; running the same builder again starts fresh.
        $again = $live->run('reconnect-again');
        $this->assertSame(2, $again->attempts);
        $this->assertSame('cancelled', $again->status);
        $this->assertSame([], glob($this->root.'/laravel-ffmpeg-*'));
    }

    #[Test]
    public function failed_native_source_consumes_explicit_retry_budget_and_redacts_errors(): void
    {
        $disk = Storage::disk('local');
        $disk->put('broken.ts', 'not a media source');
        try {
            (new PendingLiveHls(new Media('local', 'broken.ts'), $disk, 0.4, 2))->retry(2, 0)->run('failed');
            $this->fail('Expected source failure.');
        } catch (MediaException $exception) {
            $this->assertSame('source', $exception->category);
            $this->assertSame('', $exception->diagnostics);
            $this->assertSame([], $exception->published);
            $this->assertStringContainsString('after 3 attempts', $exception->getMessage());
            $this->assertStringNotContainsString('broken.ts', $exception->getMessage());
        }
        $this->assertSame([], glob($this->root.'/laravel-ffmpeg-*'));
    }

    #[Test]
    public function reconnect_can_recover_a_failed_source_and_backoff_remains_cancellable(): void
    {
        $this->source(1.2);
        $disk = Storage::disk('local');
        $disk->put('recover.mkv', 'initial source failure');
        $started = hrtime(true);
        $restored = false;
        $live = (new PendingLiveHls(new Media('local', 'recover.mkv'), $disk, 0.4, 2))->retry(2, 0.3);
        $live->cancelWhen(function () use ($disk, $started, &$restored): bool {
            if (! $restored && (hrtime(true) - $started) / 1e9 > 0.15) {
                $disk->put('recover.mkv', $disk->get('source.mkv'));
                $restored = true;
            }

            return false;
        });
        $result = $live->run('recovered');
        $this->assertSame('completed', $result->status);
        $this->assertGreaterThanOrEqual(2, $result->attempts);
        $this->assertGreaterThan(0, $result->publishedSegments);

        $disk->put('broken.ts', 'source failure');
        $started = hrtime(true);
        $result = (new PendingLiveHls(new Media('local', 'broken.ts'), $disk, 0.4, 2))
            ->retry(2, 10)->cancelWhen(fn () => (hrtime(true) - $started) / 1e9 > 0.2)->run('backoff');
        $this->assertSame('cancelled', $result->status);
        $this->assertSame(1, $result->attempts);
        $this->assertLessThan(2, (hrtime(true) - $started) / 1e9);
        $this->assertSame([], glob($this->root.'/laravel-ffmpeg-*'));
    }

    #[Test]
    public function destination_failure_retains_last_readable_playlist_and_stops_child(): void
    {
        $source = $this->source();
        $disk = Storage::disk('local');
        $destination = Mockery::mock(Filesystem::class);
        $destination->shouldReceive('allFiles')->with('failed-disk')->andReturn([]);
        $writes = 0;
        $destination->shouldReceive('put')->andReturnUsing(function ($key, $value) use ($disk, &$writes) {
            if (str_ends_with($key, '.m3u8') && ++$writes === 2) {
                return false;
            }

            return $disk->put($key, $value);
        });
        $pids = [];
        try {
            (new PendingLiveHls($source, $destination, 0.4, 2))
                ->onPublish(function () use (&$pids): void { $pids = $this->children(); })
                ->run('failed-disk');
            $this->fail('Expected destination failure.');
        } catch (MediaException $exception) {
            $this->assertSame('transfer', $exception->category);
            $this->assertContains(['disk' => null, 'path' => 'failed-disk/index.m3u8'], $exception->published);
        }
        $playlist = $disk->get('failed-disk/index.m3u8');
        foreach (explode("\n", trim($playlist)) as $line) {
            if ($line !== '' && $line[0] !== '#') {
                $this->assertTrue($disk->exists('failed-disk/'.$line));
            }
        }
        foreach ($pids as $pid) {
            $this->assertDirectoryDoesNotExist('/proc/'.$pid);
        }
        $this->assertSame([], glob($this->root.'/laravel-ffmpeg-*'));
    }

    #[Test]
    public function total_timeout_and_pre_start_cancellation_have_explicit_partial_results(): void
    {
        $source = $this->source();
        $disk = Storage::disk('local');
        $result = (new PendingLiveHls($source, $disk, 0.4, 2))->timeout(3)->run('timeout');
        $this->assertSame('timed_out', $result->status);
        $this->assertGreaterThan(0, $result->publishedSegments);
        $this->assertStringNotContainsString('#EXT-X-ENDLIST', $disk->get($result->playlist));
        $cancelled = (new PendingLiveHls($source, $disk))->cancelWhen(fn () => true)->run('cancelled');
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(0, $cancelled->attempts);
        $this->assertSame([], $disk->allFiles('cancelled'));
        $this->assertSame([], glob($this->root.'/laravel-ffmpeg-*'));
    }

    #[Test]
    public function trusted_http_factory_ingests_and_publishes_a_decodable_presentation(): void
    {
        $this->source(2);
        $disk = Storage::disk('local');
        $server = new Server([PHP_BINARY, __DIR__.'/src/http-input-server.php', $disk->path('source.mkv'), $this->root.'/requests']);
        $server->start();
        try {
            $this->assertTrue($server->waitUntil(fn ($type, $buffer) => $type === Server::OUT && str_contains($buffer, "\n")));
            $result = FFMpeg::liveHls('http://'.trim($server->getOutput()).'/public', segmentDuration: 0.4, window: 10)
                ->toDisk('local')->run('network');
            $this->assertSame('completed', $result->status);
            $this->assertGreaterThan(1, $result->publishedSegments);
            $this->assertStringContainsString('#EXT-X-ENDLIST', $disk->get($result->playlist));
            $decoded = Process::run([$this->binary, '-v', 'error', '-xerror', '-i', $disk->path($result->playlist), '-f', 'null', '-']);
            $this->assertTrue($decoded->successful(), $decoded->errorOutput());
            $requests = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->root.'/requests', FILE_IGNORE_NEW_LINES));
            $this->assertContains('/public', array_column($requests, 'path'));
            $this->assertSame([], glob($this->root.'/laravel-ffmpeg-*'));
        } finally {
            $server->stop(0);
        }
    }

    #[Test]
    public function refuses_preexisting_namespaces_and_untrusted_protocols(): void
    {
        Storage::disk('local')->put('owned/keep.ts', 'keep');
        try {
            (new PendingLiveHls(new Media('local', 'missing'), 'local'))->run('owned');
            $this->fail('Expected namespace rejection.');
        } catch (MediaException $exception) {
            $this->assertSame('ownership', $exception->category);
            $this->assertSame('keep', Storage::disk('local')->get('owned/keep.ts'));
        }
        foreach (['file:///tmp/private', 'concat:http://example.com', 'rtsp://user:pass@example.com/live', "https://example.com/\n"] as $url) {
            try {
                new LiveHlsSource($url);
                $this->fail('Expected trusted source validation.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

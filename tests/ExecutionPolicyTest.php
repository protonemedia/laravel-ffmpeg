<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ProtoneMedia\LaravelFFMpeg\Events\ExportFailed;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class ExecutionPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['laravel-ffmpeg.timeout' => 5, 'laravel-ffmpeg.idle_timeout' => null]);
        Storage::disk('memory')->put('source.mp4', 'source');
        Event::fake();
    }

    protected function binaries(string $encode = '', int $probeDelay = 0): void
    {
        $body = '#!'.PHP_BINARY."\n<?php\n"
            .'if (in_array("-version", $argv, true)) { usleep('.$probeDelay.'); echo basename($argv[0])." version 9.0.2"; exit; }'."\n"
            .'if (basename($argv[0]) === "ffprobe") { usleep('.$probeDelay.'); echo \'{"format":{"duration":"1"},"streams":[{"index":0,"codec_type":"video"}],"chapters":[]}\'; exit; }'."\n"
            .$encode."\n".'file_put_contents(end($argv), "encoded");';
        foreach (['ffmpeg', 'ffprobe'] as $tool) {
            file_put_contents($this->root.'/'.$tool, $body);
            chmod($this->root.'/'.$tool, 0700);
            config(['laravel-ffmpeg.'.$tool.'.binaries' => $this->root.'/'.$tool]);
        }
    }

    public function test_one_operation_budget_covers_all_probes_without_leaking_to_the_next_export(): void
    {
        $this->binaries(probeDelay: 80000);
        $media = FFMpeg::disk('memory')->open('source.mp4');
        $this->assertThrows(
            fn () => $media->export()->timeout(0.18)->save('limited.mp4'),
            fn (MediaException $exception) => $exception->category === 'timeout' && $exception->phase === 'probing',
        );
        $this->assertFalse(Storage::disk('memory')->exists('limited.mp4'));
        $result = $media->export()->save('normal.mp4');
        $this->assertSame('normal.mp4', $result->file()->path);
        $this->assertSame('encoded', Storage::disk('memory')->get('normal.mp4'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function deadlines(): array
    {
        return [['timeout', true], ['idleTimeout', false]];
    }

    #[DataProvider('deadlines')]
    public function test_operation_limits_stop_and_reap_real_children(string $method, bool $active): void
    {
        $pid = $this->root.'/pid';
        $this->binaries('file_put_contents('.var_export($pid, true).', getmypid()); '
            .'for ($i = 0; $i < 100; $i++) { '.($active ? 'echo "out_time_us=1000\nprogress=continue\n"; flush();' : '').' usleep(30000); }', probeDelay: 100000);
        // Allow preflight process startup before testing encoder termination.
        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->export()->{$method}(2)->save('late.mp4'),
            fn (MediaException $exception) => $exception->category === 'timeout' && $exception->phase === 'encoding',
        );
        $this->assertFileExists($pid);
        $this->assertFalse(posix_kill((int) file_get_contents($pid), 0));
        $this->assertFalse(Storage::disk('memory')->exists('late.mp4'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_resetting_limits_restores_configuration_and_queue_plans_capture_limits(): void
    {
        $this->binaries('usleep(180000);');
        $export = FFMpeg::disk('memory')->open('source.mp4')->export()->timeout(0.1);
        $job = unserialize(serialize($export->queued()->job('queued.mp4')));
        $export->timeout(null)->idleTimeout(0.01)->idleTimeout(null)->save('normal.mp4');
        $this->assertSame('encoded', Storage::disk('memory')->get('normal.mp4'));
        $this->assertThrows(
            fn () => $job->handle(),
            fn (MediaException $exception) => $exception->category === 'timeout',
        );
        $this->assertFalse(Storage::disk('memory')->exists('queued.mp4'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_global_process_limits_still_cap_a_longer_operation_budget(): void
    {
        $this->binaries('usleep(500000);');
        config(['laravel-ffmpeg.timeout' => 0.15]);
        $this->expectException(MediaException::class);
        FFMpeg::disk('memory')->open('source.mp4')->export()->timeout(10)->save('late.mp4');
    }

    #[DataProvider('invalidDeadlineProvider')]
    public function test_deadlines_reject_nonpositive_and_nonfinite_values_without_staging(string $method, float $seconds): void
    {
        $this->assertThrows(
            fn () => FFMpeg::disk('missing')->open('source.mp4')->export()->{$method}($seconds),
            InvalidArgumentException::class,
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function invalidDeadlineProvider(): array
    {
        return [
            'zero timeout' => ['timeout', 0],
            'negative timeout' => ['timeout', -1],
            'infinite timeout' => ['timeout', INF],
            'timeout not a number' => ['timeout', NAN],
            'zero idle timeout' => ['idleTimeout', 0],
            'negative idle timeout' => ['idleTimeout', -1],
            'infinite idle timeout' => ['idleTimeout', INF],
            'idle timeout not a number' => ['idleTimeout', NAN],
        ];
    }

    public function test_queue_defaults_are_overridden_by_dispatch_and_job_configuration(): void
    {
        config(['laravel-ffmpeg.queue' => ['connection' => 'redis', 'queue' => 'conversions',
            'tries' => 3, 'backoff' => [10, 30], 'timeout' => 100]]);
        $export = FFMpeg::disk('memory')->open('source.mp4')->export();
        $job = $export->queued()->job('out.mp4');
        $this->assertSame(['redis', 'conversions', 3, [10, 30], 100],
            [$job->connection, $job->queue, $job->tries, $job->backoff, $job->timeout]);
        $job = $export->queued(connection: 'database', queue: 'media')->configureJob(function ($job) {
            $job->tries = 2;
            $job->timeout = 40;
        })->job('out.mp4');
        $this->assertSame(['database', 'media', 2, 40], [$job->connection, $job->queue, $job->tries, $job->timeout]);
        config(['laravel-ffmpeg.queue.timeout' => null]);
        $this->assertSame(33, $export->timeout(2.5)->queued()->job('out.mp4')->timeout);
        config(['laravel-ffmpeg.timeout' => null]);
        $this->assertSame(0, $export->timeout(null)->queued()->job('out.mp4')->timeout);
    }

    public function test_cancellation_during_analysis_reports_the_analysis_phase(): void
    {
        $this->binaries();
        $this->assertThrows(fn () => FFMpeg::disk('memory')->open('source.mp4')->gif()->onProgress(function ($progress) {
            if ($progress->phase === 'analysis') {
                throw new ExportCancelled;
            }
        })->save('out.gif'), ExportCancelled::class);
        Event::assertDispatched(\ProtoneMedia\LaravelFFMpeg\Events\ExportCancelled::class, fn ($event) => $event->phase === 'analysis');
    }

    public function test_invalid_progress_during_analysis_reports_the_analysis_phase(): void
    {
        $this->binaries('if (in_array("gif-prepared.nut", $argv, true)) { echo str_repeat("x", 9000); exit; }');
        $phases = [];
        $this->assertThrows(
            function () use (&$phases) {
                FFMpeg::disk('memory')->open('source.mp4')->gif()
                    ->onProgress(function ($progress) use (&$phases) {
                        $phases[] = $progress->phase;
                    })->save('out.gif');
            },
            fn (MediaException $exception) => [$exception->phase, $exception->category] === ['analysis', 'progress'],
        );
        $this->assertContains('analysis', $phases);
        $this->assertNotContains('encoding', $phases);
        Event::assertDispatched(ExportFailed::class, fn ($event) => $event->phase === 'analysis');
    }
}

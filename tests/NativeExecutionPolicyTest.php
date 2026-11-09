<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Events\ExportFailed;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class NativeExecutionPolicyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/native-policy-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/work', 0700);
        config(['laravel-ffmpeg.temporary_files_root' => $this->root.'/work',
            'laravel-ffmpeg.timeout' => 5, 'laravel-ffmpeg.idle_timeout' => null]);
        Storage::disk('memory')->put('source.mp4', 'source');
        Event::fake();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function binaries(string $encode = '', int $probeDelay = 0): void
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

    #[Test]
    public function one_operation_budget_covers_all_probes_without_leaking_to_the_next_export(): void
    {
        $this->binaries(probeDelay: 80000);
        $media = FFMpeg::disk('memory')->open('source.mp4');
        try {
            $media->export()->timeout(0.18)->save('limited.mp4');
            $this->fail('The cumulative probing budget must expire.');
        } catch (MediaException $exception) {
            $this->assertSame('timeout', $exception->category);
            $this->assertSame('probing', $exception->phase);
            $this->assertFalse(Storage::disk('memory')->exists('limited.mp4'));
        }
        $result = $media->export()->save('normal.mp4');
        $this->assertSame('normal.mp4', $result->file()->path);
        $this->assertSame('encoded', Storage::disk('memory')->get('normal.mp4'));
        $this->assertSame([], glob($this->root.'/work/*'));
    }

    public static function deadlines(): array
    {
        return [['timeout', true], ['idleTimeout', false]];
    }

    #[Test]
    #[DataProvider('deadlines')]
    public function operation_limits_stop_and_reap_real_children(string $method, bool $active): void
    {
        $pid = $this->root.'/pid';
        $this->binaries('file_put_contents('.var_export($pid, true).', getmypid()); '
            .'for ($i = 0; $i < 100; $i++) { '.($active ? 'echo "out_time_us=1000\nprogress=continue\n"; flush();' : '').' usleep(30000); }', probeDelay: 100000);
        try {
            // Allow preflight process startup before testing encoder termination.
            FFMpeg::disk('memory')->open('source.mp4')->export()->{$method}(2)->save('late.mp4');
            $this->fail('The encoder must not outlive its deadline.');
        } catch (MediaException $exception) {
            $this->assertSame('timeout', $exception->category);
            $this->assertSame('encoding', $exception->phase);
            $this->assertFileExists($pid);
            $this->assertFalse(posix_kill((int) file_get_contents($pid), 0));
            $this->assertFalse(Storage::disk('memory')->exists('late.mp4'));
            $this->assertSame([], glob($this->root.'/work/*'));
        }
    }

    #[Test]
    public function resetting_limits_restores_configuration_and_queue_plans_capture_limits(): void
    {
        $this->binaries('usleep(180000);');
        $export = FFMpeg::disk('memory')->open('source.mp4')->export()->timeout(0.1);
        $job = unserialize(serialize($export->queued()->job('queued.mp4')));
        $export->timeout(null)->idleTimeout(0.01)->idleTimeout(null)->save('normal.mp4');
        $this->assertSame('encoded', Storage::disk('memory')->get('normal.mp4'));
        try {
            $job->handle();
            $this->fail('The queued snapshot must retain its shorter deadline.');
        } catch (MediaException $exception) {
            $this->assertSame('timeout', $exception->category);
            $this->assertFalse(Storage::disk('memory')->exists('queued.mp4'));
            $this->assertSame([], glob($this->root.'/work/*'));
        }
    }

    #[Test]
    public function global_process_limits_still_cap_a_longer_operation_budget(): void
    {
        $this->binaries('usleep(500000);');
        config(['laravel-ffmpeg.timeout' => 0.15]);
        $this->expectException(MediaException::class);
        FFMpeg::disk('memory')->open('source.mp4')->export()->timeout(10)->save('late.mp4');
    }

    #[Test]
    public function deadlines_reject_nonpositive_and_nonfinite_values_without_staging(): void
    {
        foreach (['timeout', 'idleTimeout'] as $method) {
            foreach ([0, -1, INF, NAN] as $seconds) {
                try {
                    FFMpeg::disk('missing')->open('source.mp4')->export()->{$method}($seconds);
                    $this->fail('Invalid deadlines must be rejected.');
                } catch (InvalidArgumentException) {
                    $this->assertSame([], glob($this->root.'/work/*'));
                }
            }
        }
    }

    #[Test]
    public function queue_defaults_are_overridden_by_dispatch_and_job_configuration(): void
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

    #[Test]
    public function cancellation_during_analysis_reports_the_analysis_phase(): void
    {
        $this->binaries();
        try {
            FFMpeg::disk('memory')->open('source.mp4')->gif()->onProgress(function ($progress) {
                if ($progress->phase === 'analysis') {
                    throw new ExportCancelled;
                }
            })->save('out.gif');
            $this->fail('The analysis callback must cancel the export.');
        } catch (ExportCancelled) {
            Event::assertDispatched(\ProtoneMedia\LaravelFFMpeg\Events\ExportCancelled::class, fn ($event) => $event->phase === 'analysis');
        }
    }

    #[Test]
    public function invalid_progress_during_analysis_reports_the_analysis_phase(): void
    {
        $this->binaries('if (in_array("gif-prepared.nut", $argv, true)) { echo str_repeat("x", 9000); exit; }');
        $phases = [];
        try {
            FFMpeg::disk('memory')->open('source.mp4')->gif()
                ->onProgress(function ($progress) use (&$phases) {
                    $phases[] = $progress->phase;
                })->save('out.gif');
            $this->fail('An oversized progress record must fail the analysis pass.');
        } catch (MediaException $exception) {
            $this->assertSame(['analysis', 'progress'], [$exception->phase, $exception->category]);
            $this->assertContains('analysis', $phases);
            $this->assertNotContains('encoding', $phases);
            Event::assertDispatched(ExportFailed::class, fn ($event) => $event->phase === 'analysis');
        }
    }
}

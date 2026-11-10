<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\LoudnessStats;
use ProtoneMedia\LaravelFFMpeg\PendingLoudness;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use RuntimeException;

class LoudnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->root.'/files', 0700);
        config(['filesystems.disks.loudness' => ['driver' => 'local', 'root' => $this->root.'/files']]);
    }

    protected function fixture(bool $silent = false): string
    {
        $binary = $this->useRealFFMpeg();
        $path = Storage::disk('loudness')->path($silent ? 'silence.mka' : 'tracks.mka');
        $result = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i',
            $silent ? 'anullsrc=r=48000:cl=stereo:d=5' : 'aevalsrc=0.02*sin(2*PI*440*t):s=48000:d=8',
            '-f', 'lavfi', '-i', 'aevalsrc=if(lt(t\,3)\,0.02\,0.5)*sin(2*PI*1000*t)|0.01*sin(2*PI*250*t):s=48000:d=8',
            '-map', '0:a', '-map', '1:a', '-metadata', 'title=Loudness fixture', '-c:a', 'pcm_f64le', $path]);
        $this->assertProcessSucceeded($result);

        return $path;
    }

    protected function operation(int $audio = 1, bool $linear = true): PendingLoudness
    {
        return FFMpeg::disk('loudness')->open('tracks.mka')->normalizeLoudness(
            integrated: -18, truePeak: -2, linear: $linear, audio: $audio);
    }

    public function test_inspection_is_pure_and_rejects_unsupported_configuration(): void
    {
        Process::fake();
        $operation = $this->operation()->clip(3, duration: 4)->audioFilter('pan=mono|c0=c0,volume=0.5');
        $command = $operation->inspect();
        $this->assertSame($command, $operation->inspect());
        $this->assertStringContainsString('atrim=start=3:duration=4,asetpts=PTS-STARTPTS,pan=mono|c0=c0,volume=0.5,aresample=48000,loudnorm=', implode(' ', $command));
        $this->assertWorkspacesAreCleanedUp();
        Process::assertNothingRan();
    }

    #[DataProvider('unsupportedConfigurationProvider')]
    public function test_unsupported_configuration_is_rejected(Closure $configure): void
    {
        Process::fake();
        $this->assertThrows(fn () => $configure($this->operation())->inspect(), InvalidArgumentException::class);
        Process::assertNothingRan();
    }

    public static function unsupportedConfigurationProvider(): array
    {
        return [
            'copied audio' => [fn (PendingLoudness $operation) => $operation->audio('copy')],
            'channel option' => [fn (PendingLoudness $operation) => $operation->outputOptions(['-ac', '1'])],
            'keyframe clip' => [fn (PendingLoudness $operation) => $operation->clip(0, duration: 1, seek: 'keyframe')],
        ];
    }

    public function test_normalization_measures_exact_selected_trimmed_filtered_samples_and_normalizes_them(): void
    {
        $source = $this->fixture();
        $sourceHash = hash_file('sha256', $source);
        $result = $this->operation()->audio('pcm_s24le')->clip(3, duration: 4)
            ->audioFilter('pan=mono|c0=c0,volume=0.5')->save('filtered.wav');
        $this->assertEqualsWithDelta(-15.1, $result->measurement->inputIntegrated, 0.4);
        $this->assertEqualsWithDelta(-12.04, $result->measurement->inputTruePeak, 0.15);
        // FFmpeg falls back for a constant tone with measured LRA=0, even
        // when linear=true. The report must describe reality, not the request.
        $this->assertSame('linear', $result->requestedMode);
        $this->assertSame('dynamic', $result->actualMode());
        $output = Storage::disk('loudness')->path($result->file()->path);
        $independent = $this->measure($output);
        $this->assertEqualsWithDelta(-18, $independent->inputIntegrated, 0.2);
        $this->assertLessThanOrEqual(-1.9, $independent->inputTruePeak);
        $probe = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-of', 'json', '-show_streams', '-show_format', $output]);
        $this->assertProcessSucceeded($probe);
        $data = json_decode($probe->output(), true);
        $this->assertSame(1, $data['streams'][0]['channels']);
        $this->assertSame('48000', $data['streams'][0]['sample_rate']);
        $this->assertEqualsWithDelta(4, (float) $data['format']['duration'], 0.01);
        $this->assertSame($sourceHash, hash_file('sha256', $source));
        $this->assertWorkspacesAreCleanedUp();
    }

    protected function measure(string $path): LoudnessStats
    {
        $result = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-hide_banner', '-i', $path,
            '-af', 'loudnorm=print_format=json', '-f', 'null', '-']);
        $this->assertProcessSucceeded($result);
        $this->assertSame(1, preg_match('/\{\s*"input_i".*?\}/s', $result->errorOutput(), $matches));

        return LoudnessStats::fromJson($matches[0]);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_native_linear_and_explicit_dynamic_modes_use_a_private_sample_snapshot(bool $linear): void
    {
        $binary = $this->useRealFFMpeg();
        $path = Storage::disk('loudness')->path('varying.wav');
        $fixture = Process::run([$binary, '-v', 'error', '-y', '-f', 'lavfi', '-i',
            'aevalsrc=(0.2+0.1*sin(2*PI*t/10))*sin(2*PI*1000*t):s=48000:d=30', '-c:a', 'pcm_f64le', $path]);
        $this->assertProcessSucceeded($fixture);
        $starts = 0;
        $result = FFMpeg::disk('loudness')->open('varying.wav')
            ->normalizeLoudness(integrated: -18, linear: $linear)->audio('pcm_s24le')
            ->onProgress(function ($progress) use ($path, &$starts) {
                if ($progress->phase === 'analysis' && $progress->elapsedSeconds === null && ++$starts === 2) {
                    // The source changes after preprocessing, before measurement.
                    file_put_contents($path, 'source replaced between passes');
                }
            })->save($linear ? 'linear.wav' : 'dynamic.wav');
        $this->assertSame('source replaced between passes', file_get_contents($path));
        $this->assertGreaterThan(1, $result->measurement->inputRange);
        $this->assertSame($linear ? 'linear' : 'dynamic', $result->requestedMode);
        $this->assertSame($linear ? 'linear' : 'dynamic', $result->actualMode());
        $independent = $this->measure(Storage::disk('loudness')->path($result->file()->path));
        $this->assertEqualsWithDelta(-18, $independent->inputIntegrated, 0.4);
        $this->assertLessThanOrEqual(-1.9, $independent->inputTruePeak);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_normalization_keeps_silence_nonfinite_and_reports_dynamic_mode(): void
    {
        $this->fixture(silent: true);
        $result = FFMpeg::disk('loudness')->open('silence.mka')->normalizeLoudness(audio: 0)
            ->audio('pcm_s24le')->save('silent-output.wav');
        $this->assertTrue($result->measurement->silent());
        $this->assertFalse($result->measurement->finite());
        $this->assertNull($result->normalization->outputIntegrated);
        $this->assertSame('dynamic', $result->actualMode());
        $this->assertTrue($this->measure(Storage::disk('loudness')->path($result->file()->path))->silent());
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_invalid_measurement_statistics_fail_in_the_analysis_phase(): void
    {
        $this->fixture();
        $binary = $this->root.'/ffmpeg';
        // Wrap the real binary so only the measurement pass leaves invalid statistics.
        file_put_contents($binary, '#!'.PHP_BINARY."\n<?php\n"
            .'passthru(implode(" ", array_map("escapeshellarg", ['.var_export($this->useRealFFMpeg(), true).', ...array_slice($argv, 1)])), $code);'."\n"
            .'if (in_array("-af", $argv, true) && str_contains(implode(" ", $argv), "stats_file=loudness-measure.json")) { file_put_contents("loudness-measure.json", "{}"); }'."\n"
            .'exit($code);');
        chmod($binary, 0700);
        config(['laravel-ffmpeg.ffmpeg.binaries' => $binary]);
        $this->assertThrows(
            fn () => $this->operation()->save('normalized.wav'),
            fn (MediaException $exception) => [$exception->phase, $exception->category, $exception->getMessage()]
                === ['analysis', 'analysis', 'Invalid loudness statistics.'],
        );
        $this->assertFalse(Storage::disk('loudness')->exists('normalized.wav'));
    }

    public function test_export_pipeline_publishes_results_and_cleans_failure_and_callback_cancellation(): void
    {
        $this->fixture();
        $events = [];
        $result = $this->operation()->audio('pcm_s24le')->clip(3, duration: 4)
            ->onProgress(function ($progress) use (&$events) {
                $events[] = $progress->phase;
            })
            ->save('normalized.wav');
        $this->assertSame('linear', $result->requestedMode);
        $this->assertSame('dynamic', $result->actualMode());
        $this->assertSame(48000, $result->sampleRate);
        $this->assertCount(3, $result->processes());
        $this->assertContains('analysis', $events);
        $this->assertEqualsWithDelta(-18, $this->measure(Storage::disk('loudness')->path('normalized.wav'))->inputIntegrated, 0.2);
    }

    public function test_failed_normalization_publishes_nothing(): void
    {
        $this->fixture();
        $this->assertThrows(fn () => $this->operation()->audioFilter('not_a_filter')->save('failed.wav'), MediaException::class);
        $this->assertFalse(Storage::disk('loudness')->exists('failed.wav'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_cancelled_normalization_publishes_nothing(): void
    {
        $this->fixture();
        $this->assertThrows(
            fn () => $this->operation()->audioFilter('volume=1')->onProgress(function ($progress) {
                if ($progress->phase === 'analysis') {
                    throw new RuntimeException('cancel loudness');
                }
            })->save('failed.wav'),
            fn (RuntimeException $exception) => $exception->getMessage() === 'cancel loudness',
        );
        $this->assertFalse(Storage::disk('loudness')->exists('failed.wav'));
        $this->assertWorkspacesAreCleanedUp();
    }
}

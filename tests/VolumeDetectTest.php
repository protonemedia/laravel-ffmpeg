<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class VolumeDetectTest extends TestCase
{
    #[DataProvider('trackProvider')]
    public function test_native_analysis_measures_selected_tracks_without_publishing_media(string $disk, int $track, float $mean, float $max): void
    {
        $bytes = $this->tracksFixture();
        $media = FFMpeg::disk($disk)->open('tracks.mka');
        $result = $track === 0 ? $media->analyze() : $media->analyze(audio: $track);
        $this->assertProcessSucceeded($result);
        $this->assertFalse($result->failed());
        $this->assertSame(0, $result->exitCode);
        $this->assertSame($result, $result->throw());
        $this->assertSame('', $result->output());
        $this->assertStringContainsString('n_samples: 12000', $result->errorOutput());
        preg_match('/mean_volume: (-?[0-9.]+) dB/', $result->errorOutput(), $meanMatch);
        preg_match('/max_volume: (-?[0-9.]+) dB/', $result->errorOutput(), $maxMatch);
        $this->assertEqualsWithDelta($mean, (float) ($meanMatch[1] ?? INF), 0.1);
        $this->assertEqualsWithDelta($max, (float) ($maxMatch[1] ?? INF), 0.1);
        $this->assertStringNotContainsString(Storage::disk('local')->path('tracks.mka'), $result->errorOutput());
        $this->assertSame(['tracks.mka'], Storage::disk($disk)->allFiles());
        $this->assertSame($bytes, Storage::disk($disk)->get('tracks.mka'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function trackProvider(): array
    {
        return [
            'first track on a local disk' => ['local', 0, -21.1, -18.1],
            'second track on a local disk' => ['local', 1, -9.0, -6.0],
            'first track on a remote disk' => ['memory', 0, -21.1, -18.1],
            'second track on a remote disk' => ['memory', 1, -9.0, -6.0],
        ];
    }

    #[TestWith(['local'])]
    #[TestWith(['memory'])]
    public function test_native_analysis_applies_custom_filters_without_publishing_media(string $disk): void
    {
        $bytes = $this->tracksFixture();
        $filtered = FFMpeg::disk($disk)->open('tracks.mka')->analyze('volume=0.25,volumedetect', audio: 1)->throw();
        $this->assertStringContainsString('max_volume: -18.1 dB', $filtered->errorOutput());
        $this->assertSame(['tracks.mka'], Storage::disk($disk)->allFiles());
        $this->assertSame($bytes, Storage::disk($disk)->get('tracks.mka'));
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Write a file with a quiet and a loud audio track to the local and memory disks and get its contents.
     */
    protected function tracksFixture(): string
    {
        $binary = $this->useRealFFMpeg();
        $local = $this->fakeLocalDisk();
        $fixture = Process::run([$binary, '-v', 'error',
            '-f', 'lavfi', '-i', 'aevalsrc=0.125*sin(2*PI*440*t):s=48000:d=0.25',
            '-f', 'lavfi', '-i', 'aevalsrc=0.5*sin(2*PI*1000*t):s=48000:d=0.25',
            '-map', '0:a', '-map', '1:a', '-c:a', 'pcm_s16le', $local->path('tracks.mka')]);
        $this->assertProcessSucceeded($fixture);
        $bytes = $local->get('tracks.mka');
        Storage::disk('memory')->put('tracks.mka', $bytes);

        return $bytes;
    }

    public function test_silence_is_a_successful_analysis(): void
    {
        $this->silenceFixture();
        $result = FFMpeg::disk('local')->open('silence.wav')->analyze()->throw();
        $this->assertStringContainsString('n_samples: 800', $result->errorOutput());
        $this->assertStringContainsString('max_volume: -91.0 dB', $result->errorOutput());
        $this->assertSame(['silence.wav'], Storage::disk('local')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('failedAnalysisProvider')]
    public function test_failed_analyses_are_inspectable(Closure $analyze): void
    {
        $this->silenceFixture();
        Storage::disk('local')->put('broken.wav', 'not audio');
        $failure = $analyze();
        $this->assertTrue($failure->failed());
        $this->assertFalse($failure->successful());
        $this->assertNotSame(0, $failure->exitCode);
        $this->assertNotEmpty($failure->errorOutput());
        $this->assertSame('analysis', $failure->phase);
        $this->assertThrows(
            fn () => $failure->throw(),
            fn (MediaException $exception) => [$exception->phase, $exception->category] === ['analysis', 'exit']
                && $exception->diagnostics === $failure->errorOutput()
                && $exception->getPrevious() === null,
        );
        $this->assertSame(['broken.wav', 'silence.wav'], Storage::disk('local')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function failedAnalysisProvider(): array
    {
        return [
            'missing track' => [fn () => FFMpeg::disk('local')->open('silence.wav')->analyze(audio: 1)],
            'unknown filter' => [fn () => FFMpeg::disk('local')->open('silence.wav')->analyze('not_an_audio_filter')],
            'broken source' => [fn () => FFMpeg::disk('local')->open('broken.wav')->analyze()],
        ];
    }

    /**
     * Write a tenth of a second of silence to the local disk.
     */
    protected function silenceFixture(): void
    {
        $binary = $this->useRealFFMpeg();
        $local = $this->fakeLocalDisk();
        $fixture = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'anullsrc=r=8000:cl=mono:d=0.1', $local->path('silence.wav')]);
        $this->assertProcessSucceeded($fixture);
    }

    public function test_signed_urls_are_redacted_everywhere_they_appear(): void
    {
        config(['laravel-ffmpeg.redact_credentials' => true]);
        $url = 'https://example.invalid/audio.wav?token=private-token';
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($url) {
            if (in_array('-version', $process->command, true)) {
                return Process::result(output: 'ffmpeg version 9.0.2');
            }
            if (in_array('-protocols', $process->command, true)) {
                return Process::result(output: "Input:\nhttps\ntls\ntcp\nOutput:\nhttps\n");
            }

            return Process::result(output: $url.' private-token', errorOutput: $url.' private-token', exitCode: 7);
        });
        $result = FFMpeg::disk('memory')->openUrl($url)->analyze();
        $this->assertSame(7, $result->exitCode);
        $this->assertSame('[input] [redacted]', trim($result->output()));
        $this->assertSame('[input] [redacted]', trim($result->errorOutput()));
        $this->assertStringNotContainsString('private-token', json_encode($result->summary()));
        $this->assertThrows(
            fn () => $result->throw(),
            fn (MediaException $exception) => ! str_contains($exception->diagnostics, 'private-token')
                && $exception->getPrevious() === null,
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_credential_redaction_is_opt_in(): void
    {
        $url = 'https://example.invalid/audio.wav?dl=1&v=2&token=abc&id=0123456789abcdef';
        $this->fakeCredentialLeaks();
        $output = "mean_volume: -21.5 dB\nmax_volume: -2.0 dB\nabc 0123456789abcdef dl=1&v=2&token=abc&id=0123456789abcdef";
        $this->assertSame($output, trim(FFMpeg::openUrl($url)->analyze()->errorOutput()));

        config(['laravel-ffmpeg.redact_credentials' => true]);
        $this->assertSame("mean_volume: -21.5 dB\nmax_volume: -2.0 dB\n[redacted] [redacted] [query]", trim(FFMpeg::openUrl($url)->analyze()->errorOutput()));
    }

    public function test_failed_rtsp_captures_redact_the_camera_password(): void
    {
        $this->fakeCredentialLeaks();
        config(['laravel-ffmpeg.redact_credentials' => true]);
        $this->assertThrows(
            fn () => FFMpeg::openRtsp('rtsp://admin:1234@camera.invalid/live')->frame()->toDisk('memory')->save('snapshot.png'),
            fn (MediaException $exception) => trim($exception->diagnostics) === 'user admin rejected with password [redacted]',
        );
    }

    /**
     * Fake FFmpeg processes that echo the credentials of the input in their error output.
     */
    protected function fakeCredentialLeaks(): void
    {
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            return match (true) {
                in_array('-version', $process->command, true) => Process::result(output: 'ffmpeg version 9.0.2'),
                in_array('-protocols', $process->command, true) => Process::result(output: "Input:\nhttps\ntls\ntcp\nOutput:\nhttps\n"),
                in_array('rtsp', $process->command, true) || str_contains(implode(' ', $process->command), 'rtsp://') => Process::result(errorOutput: 'user admin rejected with password 1234', exitCode: 1),
                default => Process::result(errorOutput: "mean_volume: -21.5 dB\nmax_volume: -2.0 dB\nabc 0123456789abcdef dl=1&v=2&token=abc&id=0123456789abcdef"),
            };
        });
    }

    public function test_missing_url_protocols_fail_in_the_analysis_phase(): void
    {
        Process::preventStrayProcesses();
        Process::fake(fn (PendingProcess $process) => Process::result(output: in_array('-version', $process->command, true)
            ? 'ffmpeg version 9.0.2' : "Input:\nfile\nOutput:\nfile\n"));
        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->openUrl('https://example.invalid/audio.wav')->analyze(),
            fn (MediaException $exception) => [$exception->phase, $exception->category] === ['analysis', 'capability'],
        );
    }

    #[DataProvider('invalidAnalysisProvider')]
    public function test_invalid_analysis_requests_fail_before_staging_or_process_execution(string $filter, int $audio): void
    {
        Process::fake();
        $this->assertThrows(
            fn () => FFMpeg::disk('missing')->open('missing.wav')->analyze($filter, $audio),
            InvalidArgumentException::class,
        );
        $this->assertWorkspacesAreCleanedUp();
        Process::assertNothingRan();
    }

    public static function invalidAnalysisProvider(): array
    {
        return [
            'empty filter' => ['', 0],
            'null byte' => ["volumedetect\0", 0],
            'source filter' => ['amovie=https://example.invalid/audio.wav', 0],
            'negative track' => ['volumedetect', -1],
        ];
    }

    public function test_timeouts_throw_and_reap_the_process_instead_of_returning_a_failed_result(): void
    {
        Storage::disk('memory')->put('audio.wav', 'source bytes');
        $binary = $this->root.'/ffmpeg';
        $pidFile = $this->root.'/pid';
        file_put_contents($binary, '#!'.PHP_BINARY."\n<?php\n".
            'if (in_array("-version", $argv, true)) { echo "ffmpeg version 9.0.2\n"; exit; }'."\n".
            'file_put_contents('.var_export($pidFile, true).', getmypid()); sleep(30);');
        chmod($binary, 0700);
        config(['laravel-ffmpeg.ffmpeg.binaries' => $binary, 'laravel-ffmpeg.timeout' => 0.3]);
        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('audio.wav')->analyze(),
            fn (MediaException $exception) => [$exception->phase, $exception->category] === ['analysis', 'timeout'],
        );
        $this->assertFalse(posix_kill((int) file_get_contents($pidFile), 0));
        $this->assertWorkspacesAreCleanedUp();
        $this->assertSame('source bytes', Storage::disk('memory')->get('audio.wav'));
    }

    public function test_launch_errors_throw_instead_of_returning_a_failed_result(): void
    {
        Storage::disk('memory')->put('audio.wav', 'source bytes');
        config(['laravel-ffmpeg.ffmpeg.binaries' => $this->root.'/missing-binary']);
        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('audio.wav')->analyze(),
            fn (MediaException $exception) => in_array($exception->category, ['process', 'version'], true)
                && $exception->phase === 'analysis',
        );
        $this->assertWorkspacesAreCleanedUp();
        $this->assertSame('source bytes', Storage::disk('memory')->get('audio.wav'));
    }
}

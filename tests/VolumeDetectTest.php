<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class VolumeDetectTest extends TestCase
{
    public function test_native_analysis_measures_selected_tracks_and_filters_without_publishing_media(): void
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
        foreach (['local', 'memory'] as $disk) {
            $media = FFMpeg::disk($disk)->open('tracks.mka');
            foreach ([[0, -21.1, -18.1], [1, -9.0, -6.0]] as [$track, $mean, $max]) {
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
                $this->assertStringNotContainsString($local->path('tracks.mka'), $result->errorOutput());
            }
            $filtered = $media->analyze('volume=0.25,volumedetect', audio: 1)->throw();
            $this->assertStringContainsString('max_volume: -18.1 dB', $filtered->errorOutput());
            $this->assertSame(['tracks.mka'], Storage::disk($disk)->allFiles());
            $this->assertSame($bytes, Storage::disk($disk)->get('tracks.mka'));
            $this->assertWorkspacesAreCleanedUp();
        }
    }

    public function test_silence_is_successful_but_missing_tracks_and_bad_filters_are_inspectable_failures(): void
    {
        $binary = $this->useRealFFMpeg();
        $local = $this->fakeLocalDisk();
        $fixture = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'anullsrc=r=8000:cl=mono:d=0.1', $local->path('silence.wav')]);
        $this->assertProcessSucceeded($fixture);
        $media = FFMpeg::disk('local')->open('silence.wav');
        $result = $media->analyze()->throw();
        $this->assertStringContainsString('n_samples: 800', $result->errorOutput());
        $this->assertStringContainsString('max_volume: -91.0 dB', $result->errorOutput());
        $local->put('broken.wav', 'not audio');
        foreach ([$media->analyze(audio: 1), $media->analyze('not_an_audio_filter'), FFMpeg::disk('local')->open('broken.wav')->analyze()] as $failure) {
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
        }
        $this->assertSame(['broken.wav', 'silence.wav'], $local->allFiles());
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_signed_urls_are_redacted_in_both_output_streams_commands_and_explicit_exceptions(): void
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

    public function test_credential_redaction_is_opt_in_and_keeps_short_ordinary_values(): void
    {
        $url = 'https://example.invalid/audio.wav?dl=1&v=2&token=abc&id=0123456789abcdef';
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            return match (true) {
                in_array('-version', $process->command, true) => Process::result(output: 'ffmpeg version 9.0.2'),
                in_array('-protocols', $process->command, true) => Process::result(output: "Input:\nhttps\ntls\ntcp\nOutput:\nhttps\n"),
                in_array('rtsp', $process->command, true) || str_contains(implode(' ', $process->command), 'rtsp://') => Process::result(errorOutput: 'user admin rejected with password 1234', exitCode: 1),
                default => Process::result(errorOutput: "mean_volume: -21.5 dB\nmax_volume: -2.0 dB\nabc 0123456789abcdef dl=1&v=2&token=abc&id=0123456789abcdef"),
            };
        });
        $output = "mean_volume: -21.5 dB\nmax_volume: -2.0 dB\nabc 0123456789abcdef dl=1&v=2&token=abc&id=0123456789abcdef";
        $this->assertSame($output, trim(FFMpeg::openUrl($url)->analyze()->errorOutput()));

        config(['laravel-ffmpeg.redact_credentials' => true]);
        $this->assertSame("mean_volume: -21.5 dB\nmax_volume: -2.0 dB\n[redacted] [redacted] [query]", trim(FFMpeg::openUrl($url)->analyze()->errorOutput()));
        $this->assertThrows(
            fn () => FFMpeg::openRtsp('rtsp://admin:1234@camera.invalid/live')->frame()->toDisk('memory')->save('snapshot.png'),
            fn (MediaException $exception) => trim($exception->diagnostics) === 'user admin rejected with password [redacted]',
        );
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

    public function test_invalid_analysis_requests_fail_before_staging_or_process_execution(): void
    {
        Process::fake();
        foreach ([['', 0], ["volumedetect\0", 0], ['amovie=https://example.invalid/audio.wav', 0], ['volumedetect', -1]] as [$filter, $audio]) {
            $this->assertThrows(
                fn () => FFMpeg::disk('missing')->open('missing.wav')->analyze($filter, $audio),
                InvalidArgumentException::class,
            );
            $this->assertWorkspacesAreCleanedUp();
        }
        Process::assertNothingRan();
    }

    public function test_launch_errors_and_timeouts_throw_and_clean_up_instead_of_returning_a_failed_result(): void
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

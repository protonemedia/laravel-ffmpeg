<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class VolumeDetectTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/native-analysis-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/workspaces', 0700);
        config(['laravel-ffmpeg.temporary_files_root' => $this->root.'/workspaces']);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function native(): string
    {
        if (! ($probe = getenv('FFMPEG_9_FFPROBE'))) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for native audio analysis acceptance.');
        }
        config(['laravel-ffmpeg.ffmpeg.binaries' => dirname($probe).'/ffmpeg']);

        return dirname($probe).'/ffmpeg';
    }

    #[Test]
    public function native_analysis_measures_selected_tracks_and_filters_without_publishing_media(): void
    {
        $binary = $this->native();
        $local = $this->fakeLocalDisk();
        $fixture = Process::run([$binary, '-v', 'error',
            '-f', 'lavfi', '-i', 'aevalsrc=0.125*sin(2*PI*440*t):s=48000:d=0.25',
            '-f', 'lavfi', '-i', 'aevalsrc=0.5*sin(2*PI*1000*t):s=48000:d=0.25',
            '-map', '0:a', '-map', '1:a', '-c:a', 'pcm_s16le', $local->path('tracks.mka')]);
        $this->assertTrue($fixture->successful(), $fixture->errorOutput());
        $bytes = $local->get('tracks.mka');
        Storage::disk('memory')->put('tracks.mka', $bytes);
        foreach (['local', 'memory'] as $disk) {
            $media = FFMpeg::disk($disk)->open('tracks.mka');
            foreach ([[0, -21.1, -18.1], [1, -9.0, -6.0]] as [$track, $mean, $max]) {
                $result = $track === 0 ? $media->analyze() : $media->analyze(audio: $track);
                $this->assertTrue($result->successful(), $result->errorOutput());
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
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    #[Test]
    public function silence_is_successful_but_missing_tracks_and_bad_filters_are_inspectable_failures(): void
    {
        $binary = $this->native();
        $local = $this->fakeLocalDisk();
        $fixture = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'anullsrc=r=8000:cl=mono:d=0.1', $local->path('silence.wav')]);
        $this->assertTrue($fixture->successful(), $fixture->errorOutput());
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
            try {
                $failure->throw();
                $this->fail('throw() accepted an unsuccessful analysis.');
            } catch (MediaException $exception) {
                $this->assertSame(['analysis', 'exit'], [$exception->phase, $exception->category]);
                $this->assertSame($failure->errorOutput(), $exception->diagnostics);
                $this->assertNull($exception->getPrevious());
            }
        }
        $this->assertSame(['broken.wav', 'silence.wav'], $local->allFiles());
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function signed_urls_are_redacted_in_both_output_streams_commands_and_explicit_exceptions(): void
    {
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
        try {
            $result->throw();
            $this->fail('A failed signed-URL analysis was accepted.');
        } catch (MediaException $exception) {
            $this->assertStringNotContainsString('private-token', $exception->diagnostics);
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function invalid_analysis_requests_fail_before_staging_or_process_execution(): void
    {
        Process::fake();
        foreach ([['', 0], ["volumedetect\0", 0], ['amovie=https://example.invalid/audio.wav', 0], ['volumedetect', -1]] as [$filter, $audio]) {
            try {
                FFMpeg::disk('missing')->open('missing.wav')->analyze($filter, $audio);
                $this->fail('Invalid analysis settings were accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame([], glob($this->root.'/workspaces/*'));
            }
        }
        Process::assertNothingRan();
    }

    #[Test]
    public function launch_errors_and_timeouts_throw_and_clean_up_instead_of_returning_a_failed_result(): void
    {
        Storage::disk('memory')->put('audio.wav', 'source bytes');
        $binary = $this->root.'/ffmpeg';
        $pidFile = $this->root.'/pid';
        file_put_contents($binary, '#!'.PHP_BINARY."\n<?php\n".
            'if (in_array("-version", $argv, true)) { echo "ffmpeg version 9.0.2\n"; exit; }'."\n".
            'file_put_contents('.var_export($pidFile, true).', getmypid()); sleep(30);');
        chmod($binary, 0700);
        config(['laravel-ffmpeg.ffmpeg.binaries' => $binary, 'laravel-ffmpeg.timeout' => 0.3]);
        try {
            FFMpeg::disk('memory')->open('audio.wav')->analyze();
            $this->fail('A timed-out analysis returned a result.');
        } catch (MediaException $exception) {
            $this->assertSame(['analysis', 'timeout'], [$exception->phase, $exception->category]);
            $this->assertFalse(posix_kill((int) file_get_contents($pidFile), 0));
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
        config(['laravel-ffmpeg.ffmpeg.binaries' => $this->root.'/missing-binary']);
        try {
            FFMpeg::disk('memory')->open('audio.wav')->analyze();
            $this->fail('A missing executable returned a result.');
        } catch (MediaException $exception) {
            $this->assertContains($exception->category, ['process', 'version']);
            $this->assertSame([], glob($this->root.'/workspaces/*'));
            $this->assertSame('source bytes', Storage::disk('memory')->get('audio.wav'));
        }
    }
}

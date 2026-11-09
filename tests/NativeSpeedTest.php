<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class NativeSpeedTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/native-speed-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/workspaces', 0700);
        mkdir($this->root.'/files', 0700);
        config([
            'laravel-ffmpeg.temporary_files_root' => $this->root.'/workspaces',
            'filesystems.disks.speed-native' => ['driver' => 'local', 'root' => $this->root.'/files'],
        ]);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function native(): string
    {
        if (! ($probe = getenv('FFMPEG_9_FFPROBE'))) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for native speed acceptance.');
        }
        config([
            'laravel-ffmpeg.ffmpeg.binaries' => dirname($probe).'/ffmpeg',
            'laravel-ffmpeg.ffprobe.binaries' => $probe,
        ]);

        return dirname($probe).'/ffmpeg';
    }

    private function media(string $path = 'source.mkv')
    {
        return FFMpeg::disk('speed-native')->open($path);
    }

    private function fixture(float $delay = 0, string $kind = 'both'): string
    {
        $binary = $this->native();
        $path = Storage::disk('speed-native')->path('source.mkv');
        $command = [$binary, '-v', 'error', '-y'];
        if ($kind !== 'audio') {
            array_push($command, '-f', 'lavfi', '-i', 'testsrc2=s=96x64:r=30:d=6');
        }
        if ($kind !== 'video') {
            array_push($command, '-itsoffset', (string) $delay, '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration='.(6 - $delay));
        }
        array_push($command, '-c:v', 'ffv1', '-c:a', 'pcm_s16le', $path);
        $result = Process::run($command);
        $this->assertTrue($result->successful(), $result->errorOutput());

        return $path;
    }

    #[Test]
    public function inspection_is_pure_preserves_maps_and_orders_filters_before_speed(): void
    {
        Process::fake();
        $operation = $this->media()->speed(3)->mapVideo(track: 1)->mapAudio(track: 1)
            ->videoFilter('hflip')->audioFilter('volume=0.5');
        $command = $operation->inspect();
        $this->assertSame($command, $operation->inspect());
        $text = implode(' ', $command);
        $this->assertStringContainsString('-map 0:v:1 -map 0:a:1', $text);
        $this->assertStringContainsString('hflip,setpts=PTS/3', $text);
        $this->assertStringContainsString('volume=0.5,asetpts=PTS-STARTPTS+STARTPTS/3,atempo=2,atempo=1.5', $text);
        $this->assertStringContainsString('atempo=0.5,atempo=0.5', implode(' ', $this->media()->speed(0.25)->inspect()));
        foreach ([0, -1, INF, NAN] as $factor) {
            try {
                $this->media()->speed($factor);
                $this->fail('Invalid speed accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        foreach ([
            fn () => $this->media()->speed(1.5)->video('copy')->inspect(),
            fn () => $this->media()->speed(1.5)->audio('copy')->inspect(),
            fn () => $this->media()->speed(1.5)->clip(0, duration: 1)->inspect(),
            fn () => $this->media()->speed(1.5)->addInput($this->media()),
            fn () => $this->media()->speed(1.5)->filterComplex('[0:v]null[v]'),
            fn () => $this->media()->speed(1.5)->output('x.mp4', fn ($output) => $output->video('libx264')),
            fn () => $this->media()->speed(1.5)->watermark($this->media()),
            fn () => $this->media()->speed(1.5)->withoutAudio()->withoutVideo()->inspect(),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Unsupported speed configuration accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        Process::assertNothingRan();
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function asymmetric_factors_retime_decoded_video_and_audio_without_changing_pitch(): void
    {
        $source = $this->fixture();
        $hash = hash_file('sha256', $source);
        foreach ([1.5, 0.75, 3.0] as $factor) {
            $result = $this->media()->speed($factor)->video('ffv1')->audio('pcm_s16le')->save('speed-'.$factor.'.mkv');
            $path = Storage::disk('speed-native')->path($result->file()->path);
            $frames = $this->frames($path);
            $video = array_values(array_filter($frames, fn ($f) => $f['media_type'] === 'video'));
            $audio = array_values(array_filter($frames, fn ($f) => $f['media_type'] === 'audio'));
            $this->assertEqualsWithDelta(6 / $factor, (float) end($video)['best_effort_timestamp_time'] + 1 / 30, 0.08);
            $samples = array_sum(array_column($audio, 'nb_samples'));
            $this->assertEqualsWithDelta(6 / $factor, $samples / 48000, 0.08);
            $this->assertPitch($path);
        }
        $this->assertSame($hash, hash_file('sha256', $source));
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function delayed_audio_initial_offset_is_scaled_with_video_not_discarded(): void
    {
        $this->fixture(1.2);
        foreach ([1.5, 0.75, 3.0] as $factor) {
            $result = $this->media()->speed($factor)->video('ffv1')->audio('pcm_s16le')->save('delayed-'.$factor.'.mkv');
            $frames = $this->frames(Storage::disk('speed-native')->path($result->file()->path));
            $audio = array_values(array_filter($frames, fn ($f) => $f['media_type'] === 'audio'));
            $this->assertEqualsWithDelta(1.2 / $factor, (float) $audio[0]['best_effort_timestamp_time'], 0.025);
            $last = end($audio);
            $this->assertEqualsWithDelta(6 / $factor, (float) $last['best_effort_timestamp_time'] + $last['nb_samples'] / 48000, 0.08);
        }
    }

    #[Test]
    public function audio_only_and_video_only_sources_and_explicit_omission_work(): void
    {
        foreach (['audio', 'video', 'both'] as $kind) {
            $this->fixture(kind: $kind);
            foreach (['audio', 'video'] as $select) {
                if ($kind !== 'both' && $kind !== $select) {
                    continue;
                }
                $operation = $this->media()->speed(1.5)->video('ffv1')->audio('pcm_s16le');
                $select === 'audio' ? $operation->withoutVideo() : $operation->withoutAudio();
                $operation->save('only.mkv');
                $types = array_unique(array_column($this->frames(Storage::disk('speed-native')->path('only.mkv')), 'media_type'));
                $this->assertSame([$select], array_values($types));
            }
            if ($kind !== 'both') {
                try {
                    $operation = $this->media()->speed(1.5);
                    $kind === 'audio' ? $operation->withoutAudio() : $operation->withoutVideo();
                    $operation->save('missing.mkv');
                    $this->fail('An empty stream selection was accepted.');
                } catch (MediaException $exception) {
                    $this->assertSame(['probing', 'mapping'], [$exception->phase, $exception->category]);
                }
            }
        }
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function progress_uses_retimed_duration_and_stays_indeterminate_for_trusted_filters(): void
    {
        $this->fixture();
        $metadata = $this->media()->probe();
        $method = new \ReflectionMethod(\ProtoneMedia\LaravelFFMpeg\PendingSpeed::class, 'prepareEncoding');
        $this->assertEqualsWithDelta(4, $method->invoke($this->media()->speed(1.5), [$metadata]), 0.01);
        $this->assertNull($method->invoke($this->media()->speed(1.5)->audioFilter('atempo=1.1'), [$metadata]));
        $this->assertNull($method->invoke($this->media()->speed(1.5)->mapAudio(), [$metadata]));
        $percentages = [];
        $this->media()->speed(1.5)->video('ffv1')->audio('pcm_s16le')->audioFilter('atempo=1.1')
            ->onProgress(function ($progress) use (&$percentages) {
                if ($progress->isEncoding()) {
                    $percentages[] = $progress->percentage;
                }
            })->save('indeterminate.mkv');
        $this->assertNotEmpty($percentages);
        $this->assertSame([null], array_values(array_unique($percentages)));
    }

    #[Test]
    public function failure_and_unsupported_timeline_leave_source_and_workspace_intact(): void
    {
        $source = $this->fixture();
        $hash = hash_file('sha256', $source);
        try {
            $this->media()->speed(1.5)->videoFilter('not_a_filter')->save('failed.mkv');
            $this->fail('Invalid filter accepted.');
        } catch (MediaException $exception) {
            $this->assertSame('encoding', $exception->phase);
        }
        $shift = Process::run([$this->native(), '-v', 'error', '-i', $source, '-c', 'copy', '-output_ts_offset', '2', Storage::disk('speed-native')->path('shifted.mkv')]);
        $this->assertTrue($shift->successful(), $shift->errorOutput());
        try {
            $this->media('shifted.mkv')->speed(1.5)->save('failed.mkv');
            $this->fail('Nonzero timeline accepted.');
        } catch (MediaException $exception) {
            $this->assertSame(['probing', 'metadata'], [$exception->phase, $exception->category]);
        }
        $this->assertSame($hash, hash_file('sha256', $source));
        $this->assertFalse(Storage::disk('speed-native')->exists('failed.mkv'));
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    private function frames(string $path): array
    {
        $result = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-of', 'json', '-show_frames', $path]);
        $this->assertTrue($result->successful(), $result->errorOutput());

        return json_decode($result->output(), true)['frames'];
    }

    private function assertPitch(string $path): void
    {
        $result = Process::run([$this->native(), '-v', 'error', '-i', $path, '-map', '0:a:0', '-t', '1', '-f', 's16le', '-ac', '1', '-ar', '48000', '-']);
        $this->assertTrue($result->successful(), $result->errorOutput());
        $samples = array_values(unpack('v*', $result->output()));
        $crossings = 0;
        $previous = 0;
        foreach ($samples as $sample) {
            $sample = $sample > 32767 ? $sample - 65536 : $sample;
            if ($previous < 0 && $sample >= 0) {
                $crossings++;
            }
            $previous = $sample;
        }
        $this->assertEqualsWithDelta(440, $crossings / (count($samples) / 48000), 3);
    }
}

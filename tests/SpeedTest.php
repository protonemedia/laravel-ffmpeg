<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\PendingSpeed;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class SpeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->root.'/files', 0700);
        config(['filesystems.disks.speed' => ['driver' => 'local', 'root' => $this->root.'/files']]);
    }

    protected function media(string $path = 'source.mkv')
    {
        return FFMpeg::disk('speed')->open($path);
    }

    protected function fixture(float $delay = 0, string $kind = 'both'): string
    {
        $binary = $this->useRealFFMpeg();
        $path = Storage::disk('speed')->path('source.mkv');
        $command = [$binary, '-v', 'error', '-y'];
        if ($kind !== 'audio') {
            array_push($command, '-f', 'lavfi', '-i', 'testsrc2=s=96x64:r=30:d=6');
        }
        if ($kind !== 'video') {
            array_push($command, '-itsoffset', (string) $delay, '-f', 'lavfi',
                '-i', 'sine=frequency=440:sample_rate=48000:duration='.(6 - $delay));
        }
        array_push($command, '-c:v', 'ffv1', '-c:a', 'pcm_s16le', $path);
        $result = Process::run($command);
        $this->assertProcessSucceeded($result);

        return $path;
    }

    public function test_filters_are_applied_before_the_speed_change(): void
    {
        Process::fake();

        $operation = $this->media()
            ->speed(3)
            ->mapVideo(track: 1)
            ->mapAudio(track: 1)
            ->videoFilter('hflip')
            ->audioFilter('volume=0.5');
        $command = $operation->inspect();

        $this->assertSame($command, $operation->inspect());

        $text = implode(' ', $command);

        $this->assertStringContainsString('-map 0:v:1 -map 0:a:1', $text);
        $this->assertStringContainsString('hflip,settb=AVTB,setpts=PTS/3', $text);
        $this->assertStringContainsString('volume=0.5,asetpts=PTS-STARTPTS+STARTPTS/3,atempo=2,atempo=1.5', $text);
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_slow_factors_chain_tempo_filters(): void
    {
        Process::fake();

        $this->assertStringContainsString(
            'atempo=0.5,atempo=0.5',
            implode(' ', $this->media()->speed(0.25)->inspect()),
        );
        Process::assertNothingRan();
    }

    #[DataProvider('invalidFactorProvider')]
    public function test_invalid_speed_factors_are_rejected(float $factor): void
    {
        Process::fake();

        $this->assertThrows(fn () => $this->media()->speed($factor), InvalidArgumentException::class);
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function invalidFactorProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'infinite' => [INF],
            'not a number' => [NAN],
        ];
    }

    #[DataProvider('unsupportedConfigurationProvider')]
    public function test_unsupported_speed_configurations_are_rejected(Closure $configure): void
    {
        Process::fake();

        $this->assertThrows(
            fn () => $configure($this->media()->speed(1.5))->inspect(),
            InvalidArgumentException::class,
        );
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function unsupportedConfigurationProvider(): array
    {
        return [
            'copied video' => [fn (PendingSpeed $operation) => $operation->video('copy')],
            'copied audio' => [fn (PendingSpeed $operation) => $operation->audio('copy')],
            'no streams' => [fn (PendingSpeed $operation) => $operation->withoutAudio()->withoutVideo()],
        ];
    }

    #[DataProvider('factorProvider')]
    public function test_speed_changes_keep_the_pitch(float $factor): void
    {
        $source = $this->fixture();

        $hash = hash_file('sha256', $source);
        $result = $this->media()
            ->speed($factor)
            ->video('ffv1')
            ->audio('pcm_s16le')
            ->save('speed-'.$factor.'.mkv');
        $path = Storage::disk('speed')->path($result->file()->path);
        $frames = $this->frames($path);
        $video = array_values(array_filter($frames, fn ($f) => $f['media_type'] === 'video'));
        $audio = array_values(array_filter($frames, fn ($f) => $f['media_type'] === 'audio'));

        $this->assertEqualsWithDelta(6 / $factor, (float) end($video)['best_effort_timestamp_time'] + 1 / 30, 0.08);

        $samples = array_sum(array_column($audio, 'nb_samples'));

        $this->assertEqualsWithDelta(6 / $factor, $samples / 48000, 0.08);
        $this->assertPitch($path);
        $this->assertSame($hash, hash_file('sha256', $source));
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function factorProvider(): array
    {
        return [
            'faster' => [1.5],
            'slower' => [0.75],
            'three times faster' => [3.0],
        ];
    }

    #[TestWith([2.0])]
    #[TestWith([3.0])]
    public function test_speeding_up_h264_with_a_frame_rate_time_base_keeps_every_frame(float $factor): void
    {
        $binary = $this->useRealFFMpeg();

        $result = Process::run([$binary, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=s=96x64:r=25:d=2',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-video_track_timescale', '25', Storage::disk('speed')->path('h264.mp4')]);

        $this->assertProcessSucceeded($result);

        $result = $this->media('h264.mp4')
            ->speed($factor)
            ->save("h264-{$factor}.mp4");
        $frames = $this->frames(Storage::disk('speed')->path($result->file()->path));
        $times = array_map(
            fn ($frame) => (float) $frame['best_effort_timestamp_time'],
            array_values(array_filter($frames, fn ($frame) => $frame['media_type'] === 'video')),
        );

        $this->assertCount(50, $times);
        $this->assertSame($times, array_values(array_unique($times, SORT_NUMERIC)));
        $this->assertEqualsWithDelta(49 / 25 / $factor, end($times), 0.01);
    }

    #[DataProvider('factorProvider')]
    public function test_delayed_audio_initial_offset_is_scaled_with_video_not_discarded(float $factor): void
    {
        $this->fixture(1.2);

        $result = $this->media()
            ->speed($factor)
            ->video('ffv1')
            ->audio('pcm_s16le')
            ->save('delayed-'.$factor.'.mkv');
        $frames = $this->frames(Storage::disk('speed')->path($result->file()->path));
        $audio = array_values(array_filter($frames, fn ($f) => $f['media_type'] === 'audio'));

        $this->assertEqualsWithDelta(1.2 / $factor, (float) $audio[0]['best_effort_timestamp_time'], 0.025);

        $last = end($audio);

        $this->assertEqualsWithDelta(
            6 / $factor,
            (float) $last['best_effort_timestamp_time'] + $last['nb_samples'] / 48000,
            0.08,
        );
    }

    #[DataProvider('streamSelectionProvider')]
    public function test_only_the_selected_stream_is_retimed(string $kind, string $select): void
    {
        $this->fixture(kind: $kind);

        $operation = $this->media()
            ->speed(1.5)
            ->video('ffv1')
            ->audio('pcm_s16le');
        $select === 'audio' ? $operation->withoutVideo() : $operation->withoutAudio();
        $operation->save('only.mkv');
        $types = array_unique(array_column($this->frames(Storage::disk('speed')->path('only.mkv')), 'media_type'));

        $this->assertSame([$select], array_values($types));
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function streamSelectionProvider(): array
    {
        return [
            'audio-only source' => ['audio', 'audio'],
            'video-only source' => ['video', 'video'],
            'audio of both' => ['both', 'audio'],
            'video of both' => ['both', 'video'],
        ];
    }

    #[TestWith(['audio'])]
    #[TestWith(['video'])]
    public function test_omitting_the_only_stream_fails_while_mapping(string $kind): void
    {
        $this->fixture(kind: $kind);

        $operation = $this->media()->speed(1.5);
        $kind === 'audio' ? $operation->withoutAudio() : $operation->withoutVideo();

        $this->assertThrows(
            fn () => $operation->save('missing.mkv'),
            fn (MediaException $exception) => [$exception->phase, $exception->category] === ['probing', 'mapping'],
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_progress_uses_the_retimed_duration(): void
    {
        $this->fixture();

        // Against the 6 second source duration the final percentage would stop near 67.
        $retimed = $this->encodingPercentages($this->media()->speed(1.5)->video('ffv1'), 'retimed.mkv');

        $this->assertEqualsWithDelta(100, end($retimed), 1);
    }

    #[DataProvider('indeterminateProgressProvider')]
    public function test_progress_stays_indeterminate_for_trusted_filters(Closure $configure): void
    {
        $this->fixture();

        $percentages = $this->encodingPercentages($configure($this->media()->speed(1.5)), 'output.mkv');

        $this->assertSame([null], array_values(array_unique($percentages)));
    }

    public static function indeterminateProgressProvider(): array
    {
        return [
            'audio filter' => [fn (PendingSpeed $operation) => $operation->video('ffv1')->audioFilter('atempo=1.1')],
            'mapped audio' => [fn (PendingSpeed $operation) => $operation->mapAudio()],
        ];
    }

    /**
     * Encode the given operation and get the percentages it reported while encoding.
     */
    protected function encodingPercentages(PendingSpeed $operation, string $path): array
    {
        $percentages = [];
        $operation->audio('pcm_s16le')
            ->onProgress(function ($progress) use (&$percentages) {
                if ($progress->isEncoding()) {
                    $percentages[] = $progress->percentage;
                }
            })
            ->save($path);
        $this->assertNotEmpty($percentages);

        return $percentages;
    }

    public function test_audio_only_sources_with_a_later_start_are_retimed_from_their_first_sample(): void
    {
        $this->fixture(delay: 0.5, kind: 'audio');

        $this->assertEqualsWithDelta(0.5, (float) $this->media()->probe()->get('format.start_time'), 0.01);

        $this->media()
            ->speed(1.5)
            ->audio('pcm_s16le')
            ->save('offset.mkv');
        $path = Storage::disk('speed')->path('offset.mkv');
        $frames = $this->frames($path);
        $last = end($frames);

        // As in any export, FFmpeg drops an audio-only source's leading offset; 5.5 s of audio becomes 3.67 s.
        $this->assertEqualsWithDelta(0, (float) $frames[0]['pts_time'], 0.001);
        $this->assertEqualsWithDelta(5.5 / 1.5, (float) $last['pts_time'] + (float) $last['duration_time'], 0.01);
        $this->assertPitch($path);
    }

    public function test_encoding_failures_leave_the_source_and_workspace_intact(): void
    {
        $source = $this->fixture();

        $hash = hash_file('sha256', $source);

        $this->assertThrows(
            fn () => $this->media()->speed(1.5)->videoFilter('not_a_filter')->save('failed.mkv'),
            fn (MediaException $exception) => $exception->phase === 'encoding',
        );
        $this->assertSame($hash, hash_file('sha256', $source));
        $this->assertFalse(Storage::disk('speed')->exists('failed.mkv'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_sources_with_a_shifted_timeline_are_rejected(): void
    {
        $source = $this->fixture();

        $hash = hash_file('sha256', $source);
        $shift = Process::run([$this->useRealFFMpeg(), '-v', 'error', '-i', $source, '-c', 'copy',
            '-output_ts_offset', '2', Storage::disk('speed')->path('shifted.mkv')]);

        $this->assertProcessSucceeded($shift);
        $this->assertThrows(
            fn () => $this->media('shifted.mkv')->speed(1.5)->save('failed.mkv'),
            fn (MediaException $exception) => [$exception->phase, $exception->category] === ['probing', 'metadata'],
        );
        $this->assertSame($hash, hash_file('sha256', $source));
        $this->assertFalse(Storage::disk('speed')->exists('failed.mkv'));
        $this->assertWorkspacesAreCleanedUp();
    }

    protected function frames(string $path): array
    {
        $result = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-of', 'json',
            '-show_frames', $path]);
        $this->assertProcessSucceeded($result);

        return json_decode($result->output(), true)['frames'];
    }

    protected function assertPitch(string $path): void
    {
        $result = Process::run([$this->useRealFFMpeg(), '-v', 'error', '-i', $path, '-map', '0:a:0', '-t', '1',
            '-f', 's16le', '-ac', '1', '-ar', '48000', '-']);
        $this->assertProcessSucceeded($result);
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

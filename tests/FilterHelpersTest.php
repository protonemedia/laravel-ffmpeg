<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ProtoneMedia\LaravelFFMpeg\PendingExport;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class FilterHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->root.'/files', 0700);
        config(['filesystems.disks.filter-helpers' => ['driver' => 'local', 'root' => $this->root.'/files']]);
    }

    protected function fixture(): void
    {
        $this->useRealFFMpeg();
        $this->ffmpeg([
            '-f', 'lavfi', '-i', 'color=white:s=32x32:r=20:d=3,drawbox=x=0:y=0:w=16:h=16:color=red:t=fill,drawbox=x=16:y=16:w=16:h=16:color=blue:t=fill',
            '-f', 'lavfi', '-i', 'aevalsrc=0.4:s=8000:d=3',
            '-c:v', 'ffv1', '-c:a', 'pcm_f64le', Storage::disk('filter-helpers')->path('source.mkv'),
        ]);
    }

    protected function ffmpeg(array $arguments): string
    {
        $result = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', ...$arguments]);
        $this->assertProcessSucceeded($result);

        return $result->output();
    }

    protected function export()
    {
        return FFMpeg::disk('filter-helpers')->open('source.mkv')->export()->video('ffv1')->audio('pcm_f64le');
    }

    protected function pixels(string $name): string
    {
        return $this->ffmpeg(['-i', Storage::disk('filter-helpers')->path($name), '-map', '0:v:0', '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-']);
    }

    protected function pixel(string $frames, int $x, int $y, int $frame = 0, int $width = 32, int $height = 32): array
    {
        return array_values(unpack('C*', substr($frames, (($frame * $height + $y) * $width + $x) * 3, 3)));
    }

    protected function samples(string $name): array
    {
        return array_values(unpack('e*', $this->ffmpeg(['-i', Storage::disk('filter-helpers')->path($name), '-map', '0:a:0', '-c:a', 'pcm_f64le', '-f', 'f64le', '-'])));
    }

    public function test_flips_grayscale_and_low_level_filters_transform_asymmetric_pixels_in_call_order(): void
    {
        $this->fixture();
        $this->export()->flipHorizontally()->save('horizontal.mkv');
        $this->export()->flipVertically()->save('vertical.mkv');
        $this->export()->flipHorizontally()->flipVertically()->grayscale()->save('gray.mkv');
        $this->export()->flipHorizontally()->videoFilter('crop=16:16:0:0')->save('flip-first.mkv');
        $this->export()->videoFilter('crop=16:16:0:0')->flipHorizontally()->save('crop-first.mkv');

        $horizontal = $this->pixels('horizontal.mkv');
        $vertical = $this->pixels('vertical.mkv');
        $this->assertGreaterThan(230, $this->pixel($horizontal, 24, 8)[0]);
        $this->assertLessThan(20, $this->pixel($horizontal, 24, 8)[2]);
        $this->assertGreaterThan(230, $this->pixel($horizontal, 8, 24)[2]);
        $this->assertLessThan(20, $this->pixel($horizontal, 8, 24)[0]);
        $this->assertGreaterThan(230, $this->pixel($vertical, 8, 24)[0]);
        $this->assertLessThan(20, $this->pixel($vertical, 8, 24)[2]);
        $this->assertGreaterThan(230, $this->pixel($vertical, 24, 8)[2]);
        $gray = $this->pixels('gray.mkv');
        foreach ([[8, 8], [24, 24]] as [$x, $y]) {
            [$red, $green, $blue] = $this->pixel($gray, $x, $y);
            $this->assertEqualsWithDelta($red, $green, 2);
            $this->assertEqualsWithDelta($red, $blue, 2);
        }
        $this->assertGreaterThan($this->pixel($gray, 8, 8)[0], $this->pixel($gray, 24, 24)[0]);
        $white = $this->pixel($this->pixels('flip-first.mkv'), 8, 8, width: 16, height: 16);
        $red = $this->pixel($this->pixels('crop-first.mkv'), 8, 8, width: 16, height: 16);
        $this->assertGreaterThan(230, min($white));
        $this->assertGreaterThan(230, $red[0]);
        $this->assertLessThan(20, $red[1]);
    }

    #[DataProvider('frameRateProvider')]
    public function test_frame_rates_resample_real_frames_without_changing_duration(int|float|string $rate, float $expected): void
    {
        $this->fixture();
        $this->export()->frameRate($rate)->save('rate.mkv');
        $probe = FFMpeg::disk('filter-helpers')->open('rate.mkv')->probe();
        [$numerator, $denominator] = explode('/', $probe->video()->get('avg_frame_rate'));
        $this->assertEqualsWithDelta($expected, $numerator / $denominator, 0.001);
        $this->assertEqualsWithDelta(round(3 * $expected), strlen($this->pixels('rate.mkv')) / (32 * 32 * 3), 1);
        $this->assertCount(24000, $this->samples('rate.mkv'));
    }

    public static function frameRateProvider(): array
    {
        return [
            'integer' => [10, 10],
            'decimal' => [12.5, 12.5],
            'fraction' => ['30000/1001', 30000 / 1001],
            'exponent' => ['1e1', 10],
        ];
    }

    public function test_volume_and_fades_change_real_pcm_and_video_at_requested_times(): void
    {
        $this->fixture();
        $this->export()->audioFilter('volume=0.5')->volume(0.5)->save('quiet.mkv');
        $this->export()->volume(0)->save('mute.mkv');
        $this->assertEqualsWithDelta(0.1, $this->samples('quiet.mkv')[8000], 0.00001);
        $this->assertSame(0.0, max($this->samples('mute.mkv')));

        $this->export()->fadeIn(1)->fadeOut(1, 2)->save('both.mkv');
        $this->export()->fadeIn(1, 0.5, audio: false)->fadeOut(0.5, 2, audio: false)->save('video.mkv');
        $this->export()->fadeIn(1, 0.5, video: false)->fadeOut(0.5, 2, video: false)->save('audio.mkv');
        $samples = $this->samples('both.mkv');
        $this->assertCount(24000, $samples);
        foreach ([0 => 0, 4000 => 0.2, 12000 => 0.4, 20000 => 0.2, 23999 => 0] as $sample => $expected) {
            $this->assertEqualsWithDelta($expected, $samples[$sample], 0.0001);
        }
        $frames = $this->pixels('both.mkv');
        foreach ([0 => 0, 10 => 127, 30 => 255, 50 => 127] as $frame => $expected) {
            $this->assertEqualsWithDelta($expected, $this->pixel($frames, 24, 8, $frame)[0], 4);
        }
        $video = $this->pixels('video.mkv');
        foreach ([5 => 0, 20 => 127, 35 => 255, 45 => 127, 55 => 0] as $frame => $expected) {
            $this->assertEqualsWithDelta($expected, $this->pixel($video, 24, 8, $frame)[0], 4);
        }
        foreach ([0, 8000, 20000] as $sample) {
            $this->assertEqualsWithDelta(0.4, $this->samples('video.mkv')[$sample], 0.00001);
        }
        $audio = $this->samples('audio.mkv');
        foreach ([2000 => 0, 8000 => 0.2, 14000 => 0.4, 18000 => 0.2, 22000 => 0] as $sample => $expected) {
            $this->assertEqualsWithDelta($expected, $audio[$sample], 0.0001);
        }
        $frames = $this->pixels('audio.mkv');
        foreach ([0, 20, 50] as $frame) {
            $this->assertGreaterThan(250, $this->pixel($frames, 24, 8, $frame)[0]);
        }
        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('audioDelayProvider')]
    public function test_audio_delay_inserts_silence_in_every_channel_and_keeps_all_original_samples(float $seconds): void
    {
        $this->useRealFFMpeg();
        $this->ffmpeg(['-f', 'lavfi', '-i', 'aevalsrc=0.25|-0.5:s=8000:d=1', '-c:a', 'pcm_f64le', Storage::disk('filter-helpers')->path('stereo.wav')]);
        $media = FFMpeg::disk('filter-helpers')->open('stereo.wav');
        $original = $this->samples('stereo.wav');

        $percentages = [];
        $media->export()->delayAudio($seconds)->audio('pcm_f64le')
            ->onProgress(function ($progress) use (&$percentages) {
                if ($progress->isEncoding()) {
                    $percentages[] = $progress->percentage;
                }
            })->save('delayed.wav');
        $this->assertNotEmpty($percentages);
        $this->assertSame([null], array_values(array_unique($percentages)));
        $samples = $this->samples('delayed.wav');
        $silenceSamples = (int) ($seconds * 8000) * 2;
        $this->assertCount(16000 + $silenceSamples, $samples);
        $this->assertSame(array_fill(0, $silenceSamples, 0.0), array_slice($samples, 0, $silenceSamples));
        $this->assertSame($original, array_slice($samples, $silenceSamples));
        $this->assertSame([0.25, -0.5], array_slice($samples, $silenceSamples, 2));
        $probe = FFMpeg::disk('filter-helpers')->open('delayed.wav')->probe();
        $this->assertEqualsWithDelta(1 + $seconds, $probe->duration(), 0.0001);
        $this->assertSame(2, $probe->audio()->get('channels'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function audioDelayProvider(): array
    {
        return [
            'no delay' => [0],
            'an eighth of a second' => [0.125],
            'a quarter of a second' => [0.25],
            'one second' => [1],
        ];
    }

    #[DataProvider('deinterlaceProvider')]
    public function test_deinterlace_outputs_progressive_frames_with_explicit_frame_or_field_cadence(array $arguments, int $count): void
    {
        $this->useRealFFMpeg();
        $this->ffmpeg(['-f', 'lavfi', '-i', 'testsrc2=s=64x48:r=40:d=1,tinterlace=mode=interleave_top',
            '-c:v', 'ffv1', Storage::disk('filter-helpers')->path('interlaced.mkv')]);
        $input = $this->frameFlags('interlaced.mkv');
        $this->assertCount(20, $input);
        $this->assertSame([1], array_values(array_unique(array_column($input, 'interlaced_frame'))));
        $media = FFMpeg::disk('filter-helpers')->open('interlaced.mkv');
        $media->export()->deinterlace(...$arguments)->resize(32, 24)->video('ffv1')->save('deinterlaced.mkv');
        $output = $this->frameFlags('deinterlaced.mkv');
        $this->assertCount($count, $output);
        $this->assertSame([0], array_values(array_unique(array_column($output, 'interlaced_frame'))));
        $this->assertSame([32], array_values(array_unique(array_column($output, 'width'))));
        $this->assertSame([24], array_values(array_unique(array_column($output, 'height'))));
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function deinterlaceProvider(): array
    {
        return [
            'frame' => [[], 20],
            'field' => [['field', 'tff'], 40],
            'forced' => [['frame', 'bff', false], 20],
        ];
    }

    public function test_field_deinterlacing_only_doubles_interlaced_frames_by_default(): void
    {
        $this->fixture();
        // The default interlaced-only setting must not double progressive frames.
        $this->export()->deinterlace('field')->save('progressive.mkv');
        $this->export()->deinterlace('field', onlyInterlaced: false)->save('force-progressive.mkv');
        $this->assertCount(60, $this->frameFlags('progressive.mkv'));
        $this->assertCount(120, $this->frameFlags('force-progressive.mkv'));
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Get the interlacing flag and size of every video frame in the given file.
     */
    protected function frameFlags(string $name): array
    {
        $result = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-select_streams', 'v:0',
            '-show_frames', '-show_entries', 'frame=interlaced_frame,width,height', '-of', 'json', Storage::disk('filter-helpers')->path($name)]);
        $this->assertProcessSucceeded($result);

        return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR)['frames'];
    }

    #[DataProvider('invalidAudioDelayProvider')]
    public function test_invalid_audio_delays_are_rejected_before_io(float $seconds): void
    {
        Process::fake();
        $this->assertThrows(fn () => $this->missingExport()->delayAudio($seconds), InvalidArgumentException::class);
        Process::assertNothingRan();
    }

    public static function invalidAudioDelayProvider(): array
    {
        return [
            'negative' => [-1],
            'infinite' => [INF],
            'negative infinite' => [-INF],
            'not a number' => [NAN],
        ];
    }

    #[DataProvider('invalidDeinterlaceProvider')]
    public function test_invalid_deinterlace_settings_are_rejected_before_io(array $arguments): void
    {
        Process::fake();
        $this->assertThrows(fn () => $this->missingExport()->deinterlace(...$arguments), InvalidArgumentException::class);
        Process::assertNothingRan();
    }

    public static function invalidDeinterlaceProvider(): array
    {
        return [
            'empty mode' => [['']],
            'unknown mode' => [['send_frame']],
            'filter in mode' => [['field,hflip']],
            'empty parity' => [['frame', '']],
            'unknown parity' => [['frame', 'top']],
            'newline in parity' => [['frame', "auto\n"]],
        ];
    }

    #[DataProvider('invalidFrameRateProvider')]
    public function test_invalid_frame_rates_are_rejected_before_io(int|float|string $rate): void
    {
        Process::fake();
        $this->assertThrows(fn () => $this->missingExport()->frameRate($rate), InvalidArgumentException::class);
        Process::assertNothingRan();
    }

    public static function invalidFrameRateProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'infinite' => [INF],
            'not a number' => [NAN],
            'empty' => [''],
            'zero fraction' => ['0/1'],
            'zero denominator' => ['1/0'],
            'negative fraction' => ['-1/2'],
            'three parts' => ['1/2/3'],
            'filter injection' => ['25,hflip'],
            'trailing newline' => ["25\n"],
            'overflowing exponent' => ['1e999'],
            'underflowing exponent' => ['1e-999'],
        ];
    }

    #[DataProvider('invalidVolumeProvider')]
    public function test_invalid_volumes_are_rejected_before_io(float $volume): void
    {
        Process::fake();
        $this->assertThrows(fn () => $this->missingExport()->volume($volume), InvalidArgumentException::class);
        Process::assertNothingRan();
    }

    public static function invalidVolumeProvider(): array
    {
        return [
            'negative' => [-1],
            'infinite' => [INF],
            'not a number' => [NAN],
        ];
    }

    #[DataProvider('invalidFadeProvider')]
    public function test_invalid_fades_are_rejected_before_io(array $arguments): void
    {
        Process::fake();
        $this->assertThrows(fn () => $this->missingExport()->fadeIn(...$arguments), InvalidArgumentException::class);
        $this->assertThrows(fn () => $this->missingExport()->fadeOut(...$arguments), InvalidArgumentException::class);
        Process::assertNothingRan();
    }

    public static function invalidFadeProvider(): array
    {
        return [
            'zero duration' => [[0, 0]],
            'negative duration' => [[-1, 0]],
            'infinite duration' => [[INF, 0]],
            'duration not a number' => [[NAN, 0]],
            'negative start' => [[1, -1]],
            'infinite start' => [[1, INF]],
            'start not a number' => [[1, NAN]],
            'no streams' => [[1, 0, false, false]],
        ];
    }

    #[DataProvider('helperProvider')]
    public function test_helpers_conflict_with_stream_copy(string $method, array $arguments): void
    {
        Process::fake();
        $this->assertThrows(
            fn () => $this->missingExport()->{$method}(...$arguments)->video('copy')->audio('copy')->inspect(),
            InvalidArgumentException::class,
        );
        Process::assertNothingRan();
    }

    #[DataProvider('helperProvider')]
    public function test_helpers_cannot_change_an_export_after_outputs_are_added(string $method, array $arguments): void
    {
        Process::fake();
        $this->assertThrows(
            fn () => $this->missingExport()->output('one.mkv', fn () => null)->{$method}(...$arguments),
            InvalidArgumentException::class,
        );
        Process::assertNothingRan();
    }

    public static function helperProvider(): array
    {
        return [
            'frameRate' => ['frameRate', [25]],
            'flipHorizontally' => ['flipHorizontally', []],
            'flipVertically' => ['flipVertically', []],
            'grayscale' => ['grayscale', []],
            'deinterlace' => ['deinterlace', []],
            'volume' => ['volume', [0.5]],
            'delayAudio' => ['delayAudio', [0.5]],
            'fadeIn' => ['fadeIn', [1]],
            'fadeOut' => ['fadeOut', [1, 2]],
        ];
    }

    /**
     * Get an export of a file that does not exist, to prove validation happens before any I/O.
     */
    protected function missingExport(): PendingExport
    {
        return FFMpeg::disk('missing')->open('absent.mkv')->export();
    }
}

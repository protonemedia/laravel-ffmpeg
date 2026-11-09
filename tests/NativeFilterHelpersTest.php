<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class NativeFilterHelpersTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/native-filter-helpers-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/workspaces', 0700);
        mkdir($this->root.'/files', 0700);
        config([
            'laravel-ffmpeg.temporary_files_root' => $this->root.'/workspaces',
            'filesystems.disks.filter-helpers' => ['driver' => 'local', 'root' => $this->root.'/files'],
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function fixture(): void
    {
        if (! ($probe = getenv('FFMPEG_9_FFPROBE'))) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for real filter helper acceptance.');
        }

        config([
            'laravel-ffmpeg.ffmpeg.binaries' => dirname($probe).'/ffmpeg',
            'laravel-ffmpeg.ffprobe.binaries' => $probe,
        ]);
        $this->ffmpeg([
            '-f', 'lavfi', '-i', 'color=white:s=32x32:r=20:d=3,drawbox=x=0:y=0:w=16:h=16:color=red:t=fill,drawbox=x=16:y=16:w=16:h=16:color=blue:t=fill',
            '-f', 'lavfi', '-i', 'aevalsrc=0.4:s=8000:d=3',
            '-c:v', 'ffv1', '-c:a', 'pcm_f64le', Storage::disk('filter-helpers')->path('source.mkv'),
        ]);
    }

    private function ffmpeg(array $arguments): string
    {
        $result = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', ...$arguments]);
        $this->assertTrue($result->successful(), $result->errorOutput());

        return $result->output();
    }

    private function export()
    {
        return FFMpeg::disk('filter-helpers')->open('source.mkv')->export()->video('ffv1')->audio('pcm_f64le');
    }

    private function pixels(string $name): string
    {
        return $this->ffmpeg(['-i', Storage::disk('filter-helpers')->path($name), '-map', '0:v:0', '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-']);
    }

    private function pixel(string $frames, int $x, int $y, int $frame = 0, int $width = 32, int $height = 32): array
    {
        return array_values(unpack('C*', substr($frames, (($frame * $height + $y) * $width + $x) * 3, 3)));
    }

    private function samples(string $name): array
    {
        return array_values(unpack('e*', $this->ffmpeg(['-i', Storage::disk('filter-helpers')->path($name), '-map', '0:a:0', '-c:a', 'pcm_f64le', '-f', 'f64le', '-'])));
    }

    #[Test]
    public function flips_grayscale_and_low_level_filters_transform_asymmetric_pixels_in_call_order(): void
    {
        $this->fixture();
        $this->export()->flipHorizontal()->save('horizontal.mkv');
        $this->export()->flipVertical()->save('vertical.mkv');
        $this->export()->flipHorizontal()->flipVertical()->grayscale()->save('gray.mkv');
        $this->export()->flipHorizontal()->videoFilter('crop=16:16:0:0')->save('flip-first.mkv');
        $this->export()->videoFilter('crop=16:16:0:0')->flipHorizontal()->save('crop-first.mkv');

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

    #[Test]
    public function frame_rates_resample_real_frames_without_changing_duration(): void
    {
        $this->fixture();
        foreach ([10, 12.5, '30000/1001', '1e1'] as $index => $rate) {
            $name = 'rate-'.$index.'.mkv';
            $this->export()->frameRate($rate)->save($name);
            $probe = FFMpeg::disk('filter-helpers')->open($name)->probe();
            $expected = [10, 12.5, 30000 / 1001, 10][$index];
            [$numerator, $denominator] = explode('/', $probe->video()->get('avg_frame_rate'));
            $this->assertEqualsWithDelta($expected, $numerator / $denominator, 0.001);
            $this->assertEqualsWithDelta(round(3 * $expected), strlen($this->pixels($name)) / (32 * 32 * 3), 1);
            $this->assertCount(24000, $this->samples($name));
        }
    }

    #[Test]
    public function volume_and_fades_change_real_pcm_and_video_at_requested_times(): void
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
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function audio_delay_inserts_silence_in_every_channel_and_keeps_all_original_samples(): void
    {
        $this->fixture();
        $this->ffmpeg(['-f', 'lavfi', '-i', 'aevalsrc=0.25|-0.5:s=8000:d=1', '-c:a', 'pcm_f64le', Storage::disk('filter-helpers')->path('stereo.wav')]);
        $media = FFMpeg::disk('filter-helpers')->open('stereo.wav');
        $original = $this->samples('stereo.wav');

        foreach ([0, 0.125, 0.25, 1] as $index => $seconds) {
            $name = 'delayed-'.$index.'.wav';
            $percentages = [];
            $media->export()->delayAudio($seconds)->audio('pcm_f64le')
                ->onProgress(function ($progress) use (&$percentages) {
                    if ($progress->isEncoding()) {
                        $percentages[] = $progress->percentage;
                    }
                })->save($name);
            $this->assertNotEmpty($percentages);
            $this->assertSame([null], array_values(array_unique($percentages)));
            $samples = $this->samples($name);
            $silenceSamples = (int) ($seconds * 8000) * 2;
            $this->assertCount(16000 + $silenceSamples, $samples);
            $this->assertSame(array_fill(0, $silenceSamples, 0.0), array_slice($samples, 0, $silenceSamples));
            $this->assertSame($original, array_slice($samples, $silenceSamples));
            $this->assertSame([0.25, -0.5], array_slice($samples, $silenceSamples, 2));
            $probe = FFMpeg::disk('filter-helpers')->open($name)->probe();
            $this->assertEqualsWithDelta(1 + $seconds, $probe->duration(), 0.0001);
            $this->assertSame(2, $probe->audio()->get('channels'));
        }
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function deinterlace_outputs_progressive_frames_with_explicit_frame_or_field_cadence(): void
    {
        $this->fixture();
        $this->ffmpeg(['-f', 'lavfi', '-i', 'testsrc2=s=64x48:r=40:d=1,tinterlace=mode=interleave_top',
            '-c:v', 'ffv1', Storage::disk('filter-helpers')->path('interlaced.mkv')]);
        $frames = function (string $name): array {
            $result = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-select_streams', 'v:0',
                '-show_frames', '-show_entries', 'frame=interlaced_frame,width,height', '-of', 'json', Storage::disk('filter-helpers')->path($name)]);
            $this->assertTrue($result->successful(), $result->errorOutput());

            return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR)['frames'];
        };
        $input = $frames('interlaced.mkv');
        $this->assertCount(20, $input);
        $this->assertSame([1], array_values(array_unique(array_column($input, 'interlaced_frame'))));
        $media = FFMpeg::disk('filter-helpers')->open('interlaced.mkv');
        foreach (['frame' => [[], 20], 'field' => [['field', 'tff'], 40], 'forced' => [['frame', 'bff', false], 20]] as $name => [$arguments, $count]) {
            $media->export()->deinterlace(...$arguments)->resize(32, 24)->video('ffv1')->save($name.'.mkv');
            $output = $frames($name.'.mkv');
            $this->assertCount($count, $output);
            $this->assertSame([0], array_values(array_unique(array_column($output, 'interlaced_frame'))));
            $this->assertSame([32], array_values(array_unique(array_column($output, 'width'))));
            $this->assertSame([24], array_values(array_unique(array_column($output, 'height'))));
        }

        // The default interlaced-only setting must not double progressive frames.
        $this->export()->deinterlace('field')->save('progressive.mkv');
        $this->export()->deinterlace('field', onlyInterlaced: false)->save('force-progressive.mkv');
        $this->assertCount(60, $frames('progressive.mkv'));
        $this->assertCount(120, $frames('force-progressive.mkv'));
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function invalid_inputs_copy_conflicts_and_mutation_after_outputs_are_rejected_before_io(): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('absent.mkv');
        $attempts = [];
        foreach ([-1, INF, -INF, NAN] as $seconds) {
            $attempts[] = fn () => $media->export()->delayAudio($seconds);
        }
        foreach ([[''], ['send_frame'], ['field,hflip'], ['frame', ''], ['frame', 'top'], ['frame', "auto\n"]] as $arguments) {
            $attempts[] = fn () => $media->export()->deinterlace(...$arguments);
        }
        foreach ([0, -1, INF, NAN, '', '0/1', '1/0', '-1/2', '1/2/3', '25,hflip', "25\n", '1e999', '1e-999'] as $rate) {
            $attempts[] = fn () => $media->export()->frameRate($rate);
        }
        foreach ([-1, INF, NAN] as $volume) {
            $attempts[] = fn () => $media->export()->volume($volume);
        }
        foreach (['fadeIn', 'fadeOut'] as $method) {
            foreach ([[0, 0], [-1, 0], [INF, 0], [NAN, 0], [1, -1], [1, INF], [1, NAN], [1, 0, false, false]] as $arguments) {
                $attempts[] = fn () => $media->export()->{$method}(...$arguments);
            }
        }
        foreach (['frameRate' => [25], 'flipHorizontal' => [], 'flipVertical' => [], 'grayscale' => [], 'deinterlace' => [], 'volume' => [0.5], 'delayAudio' => [0.5], 'fadeIn' => [1], 'fadeOut' => [1, 2]] as $method => $arguments) {
            $attempts[] = fn () => $media->export()->{$method}(...$arguments)->video('copy')->audio('copy')->inspect();
            $attempts[] = fn () => $media->export()->output('one.mkv', fn () => null)->{$method}(...$arguments);
        }
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Invalid helper configuration was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        Process::assertNothingRan();
    }
}

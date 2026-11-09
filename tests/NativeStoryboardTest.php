<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class NativeStoryboardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/native-storyboard-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        config(['laravel-ffmpeg.temporary_files_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function native(): string
    {
        if (! ($probe = getenv('FFMPEG_9_FFPROBE'))) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for native storyboard acceptance.');
        }
        config(['laravel-ffmpeg.ffprobe.binaries' => $probe, 'laravel-ffmpeg.ffmpeg.binaries' => dirname($probe).'/ffmpeg']);

        return dirname($probe).'/ffmpeg';
    }

    private function colors(): Media
    {
        $binary = $this->native();
        $local = $this->fakeLocalDisk();
        $command = [$binary, '-v', 'error'];
        foreach (['red', 'lime', 'blue', 'yellow', 'magenta', 'cyan', 'white'] as $index => $color) {
            array_push($command, '-f', 'lavfi', '-i', 'color='.$color.':size=80x48:rate=20:duration='.($index === 6 ? '0.15' : '0.2'));
        }
        array_push($command, '-filter_complex', '[0:v][1:v][2:v][3:v][4:v][5:v][6:v]concat=n=7:v=1:a=0[video]',
            '-map', '[video]', '-c:v', 'ffv1', '-threads', '1', $local->path('colors.mkv'));
        $result = Process::run($command);
        $this->assertTrue($result->successful(), $result->errorOutput());

        return FFMpeg::disk('local')->open('colors.mkv');
    }

    #[Test]
    public function inspection_is_pure_and_invalid_intervals_and_layouts_are_rejected(): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('video.mp4');
        $command = $media->storyboard(every: 0.2, columns: 3, rows: 2, margin: 3, padding: 2)
            ->resize(width: 20)->vtt('metadata/preview.vtt')->inspect();
        $this->assertStringContainsString('tile=layout=3x2:nb_frames=6:margin=3:padding=2', $command[array_search('-filter:v', $command) + 1]);
        $this->assertNotContains('metadata/preview.vtt', $command);
        $clipped = $media->storyboard(every: 0.2)->clip(1, duration: 2)->inspect();
        $this->assertNotContains('-ss', $clipped);
        $this->assertNotContains('-t', $clipped);
        $this->assertStringStartsWith('trim=start=1:end=', $clipped[array_search('-filter:v', $clipped) + 1]);
        foreach ([$media->storyboard(every: 1), $media->frames(every: 1)] as $operation) {
            try {
                // A per-track -filter:v:0 would silently replace or be replaced by the sampling filter.
                $operation->videoStream(0, fn ($video) => $video->color('bt709', 'bt709', 'bt709'))->inspect();
                $this->fail('Per-track video filters must be rejected for sampled image operations.');
            } catch (InvalidArgumentException) {
            }
        }
        foreach ([[0, 2, 2, 0, 0], [INF, 2, 2, 0, 0], [0.0001, 2, 2, 0, 0], [0.0015, 2, 2, 0, 0],
            [1, 0, 2, 0, 0], [1, 2, 0, 0, 0], [1, 2, 2, -1, 0], [1, 2, 2, 0, -1], [1, PHP_INT_MAX, 2, 0, 0]] as $arguments) {
            try {
                $media->storyboard(...$arguments);
                $this->fail('An invalid storyboard was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame([], glob($this->root.'/*'));
            }
        }
        Process::assertNothingRan();
    }

    #[Test]
    public function sheets_and_cues_agree_on_order_spacing_relative_paths_and_the_partial_last_sheet(): void
    {
        $media = $this->colors();
        $original = Storage::disk('local')->get('colors.mkv');
        $result = $media->storyboard(every: 0.2, columns: 3, rows: 2, margin: 3, padding: 2)->resize(width: 20, height: 12)
            ->vtt('metadata/nested/preview.vtt')->toDisk('memory')->save('images/é #?/sheet_%02d.png');
        $this->assertSame(['image', 'image', 'vtt'], $result->files()->pluck('role')->all());
        $this->assertSame(['images/é #?/sheet_01.png', 'images/é #?/sheet_02.png', 'metadata/nested/preview.vtt'], $result->files()->pluck('path')->all());
        $sheets = [];
        foreach ([1, 2] as $number) {
            $bytes = Storage::disk('memory')->get('images/é #?/sheet_0'.$number.'.png');
            $this->assertSame([70, 32], array_slice(getimagesizefromstring($bytes), 0, 2));
            $sheets[] = imagecreatefromstring($bytes);
        }
        $positions = [[3, 3], [25, 3], [47, 3], [3, 17], [25, 17], [47, 17], [3, 3]];
        $colors = [[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 255, 0], [255, 0, 255], [0, 255, 255], [255, 255, 255]];
        $times = ['00:00:00.000 --> 00:00:00.200', '00:00:00.200 --> 00:00:00.400',
            '00:00:00.400 --> 00:00:00.600', '00:00:00.600 --> 00:00:00.800',
            '00:00:00.800 --> 00:00:01.000', '00:00:01.000 --> 00:00:01.200', '00:00:01.200 --> 00:00:01.350'];
        $cues = explode("\n\n", trim(Storage::disk('memory')->get('metadata/nested/preview.vtt')));
        $this->assertSame('WEBVTT', array_shift($cues));
        $this->assertCount(7, $cues);
        foreach ($positions as $index => [$x, $y]) {
            $sheet = $index === 6 ? 1 : 0;
            $this->assertSame($times[$index]."\n../../images/%C3%A9%20%23%3F/sheet_0".($sheet + 1).".png#xywh=$x,$y,20,12", $cues[$index]);
            $pixel = imagecolorsforindex($sheets[$sheet], imagecolorat($sheets[$sheet], $x + 10, $y + 6));
            foreach (['red', 'green', 'blue'] as $channel => $name) {
                $this->assertEqualsWithDelta($colors[$index][$channel], $pixel[$name], 8);
            }
        }
        foreach ([[$sheets[0], 1, 1], [$sheets[0], 23, 10], [$sheets[1], 35, 9]] as [$sheet, $x, $y]) {
            $pixel = imagecolorsforindex($sheet, imagecolorat($sheet, $x, $y));
            $this->assertSame([0, 0, 0], [$pixel['red'], $pixel['green'], $pixel['blue']]);
        }
        $this->assertSame($original, Storage::disk('local')->get('colors.mkv'));
        $this->assertSame([], glob($this->root.'/*'));
    }

    #[Test]
    public function clips_bound_thumbnails_before_tiling_and_custom_urls_keep_their_query(): void
    {
        $media = $this->colors();
        $media->storyboard(every: 0.2, columns: 2, rows: 2)->resize(height: 12)->clip(0.2, duration: 0.45)
            ->vtt('clip.vtt', fn ($key, $index) => 'https://cdn.example/custom-'.$index.'.png?token=signed')
            ->toDisk('memory')->save('clip-%d.png');
        $image = imagecreatefromstring(Storage::disk('memory')->get('clip-1.png'));
        foreach ([[10, 6, [0, 255, 0]], [30, 6, [0, 0, 255]], [10, 18, [255, 255, 0]], [30, 18, [0, 0, 0]]] as [$x, $y, $expected]) {
            $pixel = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            foreach (['red', 'green', 'blue'] as $channel => $name) {
                $this->assertEqualsWithDelta($expected[$channel], $pixel[$name], 8);
            }
        }
        $vtt = Storage::disk('memory')->get('clip.vtt');
        $this->assertSame(3, substr_count($vtt, ' --> '));
        $this->assertStringContainsString('00:00:00.400 --> 00:00:00.450', $vtt);
        $this->assertStringContainsString('https://cdn.example/custom-1.png?token=signed#xywh=0,12,20,12', $vtt);
        $media->storyboard(every: 1, columns: 1, rows: 1)->clip(1.3, duration: 1)->vtt('short.vtt')
            ->toDisk('memory')->save('short-%d.png');
        $this->assertStringContainsString('00:00:00.000 --> 00:00:00.050', Storage::disk('memory')->get('short.vtt'));
        $this->assertFalse(Storage::disk('memory')->exists('short-2.png'));
        $this->assertSame([], glob($this->root.'/*'));
    }

    #[Test]
    public function jpeg_quality_is_preserved_and_invalid_cue_urls_prevent_all_publication(): void
    {
        $binary = $this->native();
        $local = $this->fakeLocalDisk();
        $result = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=160x96:rate=10:duration=0.4', '-c:v', 'ffv1', $local->path('detail.mkv')]);
        $this->assertTrue($result->successful(), $result->errorOutput());
        $media = FFMpeg::disk('local')->open('detail.mkv');
        foreach ([2, 31] as $quality) {
            $result = $media->storyboard(every: 0.2, columns: 2, rows: 1)->outputOptions(['-q:v', (string) $quality])
                ->toDisk('memory')->save('quality-'.$quality.'-%d.jpg');
            $this->assertCount(1, $result->files());
        }
        $this->assertGreaterThan(Storage::disk('memory')->size('quality-31-1.jpg'), Storage::disk('memory')->size('quality-2-1.jpg'));
        try {
            $media->storyboard(every: 0.2, columns: 1, rows: 1)->vtt('invalid.vtt', fn () => "image.png\n\ninjected")
                ->toDisk('memory')->save('invalid-%d.png');
            $this->fail('A cue with an injected newline was accepted.');
        } catch (MediaException $exception) {
            $this->assertSame('validation', $exception->phase);
            $this->assertFalse(Storage::disk('memory')->exists('invalid-1.png'));
            $this->assertFalse(Storage::disk('memory')->exists('invalid.vtt'));
            $this->assertSame([], glob($this->root.'/*'));
        }
    }

    #[Test]
    public function a_failed_vtt_upload_reports_and_retains_the_published_sheets(): void
    {
        $media = $this->colors();
        $storage = Storage::disk('memory');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturnUsing(fn ($path, $stream, $options) => $path === 'preview.vtt' ? false : $storage->put($path, $stream, $options));
        $disk->shouldReceive('size')->andReturnUsing(fn ($path) => $storage->size($path));

        try {
            $media->storyboard(every: 0.2, columns: 3, rows: 2)->vtt('preview.vtt')->toDisk($disk)->save('sheet-%d.png');
            $this->fail('A failed VTT upload was accepted.');
        } catch (MediaException $exception) {
            $this->assertSame(['publication', 'transfer'], [$exception->phase, $exception->category]);
            $this->assertSame([['disk' => null, 'path' => 'sheet-1.png'], ['disk' => null, 'path' => 'sheet-2.png']], $exception->published);
            $this->assertSame(['disk' => null, 'path' => 'preview.vtt'], $exception->destination);
        }
        $this->assertNotEmpty($storage->get('sheet-1.png'));
        $this->assertNotEmpty($storage->get('sheet-2.png'));
        $this->assertFalse($storage->exists('preview.vtt'));
        $this->assertTrue(Storage::disk('local')->exists('colors.mkv'));
        $this->assertSame([], glob($this->root.'/*'));
    }
}

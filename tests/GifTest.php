<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class GifTest extends TestCase
{
    public function test_filters_precede_the_palette_in_caller_order(): void
    {
        Process::fake();
        $gif = FFMpeg::disk('missing')->open('missing.mp4')->gif()->rotate(90)->crop(100, 100)
            ->clip(1.0, duration: 2.0);
        $command = $gif->inspect();
        $this->assertSame($command, $gif->inspect());
        $graph = $command[array_search('-filter_complex', $command) + 1];
        $this->assertStringContainsString('trim=start=1:duration=2,setpts=PTS-STARTPTS,scale=w=480:h=-2:reset_sar=1,fps=fps=12,transpose=clock,crop=', $graph);
        $this->assertStringContainsString('movie=gif-palette.png[gif_palette];[gif_frames][gif_palette]paletteuse[gif_out]', $graph);
        $this->assertStringNotContainsString('split', $graph);
        $this->assertNotContains('-filter:v', $command);
        $this->assertNotContains('-ss', $command);
        $this->assertNotContains('-t', $command);
        $this->assertContains('[gif_out]', $command);
        $this->assertSame('0', $command[array_search('-loop', $command) + 1]);
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('invalidSettingsProvider')]
    public function test_invalid_settings_fail_without_io(Closure $configure): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('missing.mp4');
        $this->assertThrows(fn () => $configure($media), InvalidArgumentException::class);

        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function invalidSettingsProvider(): array
    {
        return [
            'zero width' => [fn (Media $media) => $media->gif(0)],
            'excessive width' => [fn (Media $media) => $media->gif(65536)],
            'zero frame rate' => [fn (Media $media) => $media->gif(frameRate: 0)],
            'infinite frame rate' => [fn (Media $media) => $media->gif(frameRate: INF)],
            'frame rate not a number' => [fn (Media $media) => $media->gif(frameRate: NAN)],
            'zero loops' => [fn (Media $media) => $media->gif(loops: 0)],
            'excessive loops' => [fn (Media $media) => $media->gif(loops: 65536)],
            'loops without looping' => [fn (Media $media) => $media->gif(loop: false, loops: 2)],
            'keyframe clip' => [fn (Media $media) => $media->gif()->clip(1.0, duration: 1.0, seek: 'keyframe')->inspect()],
        ];
    }

    #[DataProvider('loopProvider')]
    public function test_native_gifs_honour_the_requested_settings(int $loop, bool $looping, ?int $loops): void
    {
        $binary = $this->useRealFFMpeg();
        $source = $this->root.'/source.mp4';
        $fixture = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=128x96:rate=20:duration=2',
            '-f', 'lavfi', '-i', 'sine=duration=2', '-c:v', 'libx264', '-threads', '1', '-c:a', 'aac', $source]);
        $this->assertProcessSucceeded($fixture);
        $hash = hash_file('sha256', $source);
        $media = FFMpeg::disk()->open(new File($source));

        $spoolBytes = 0;
        $result = $media->gif(64, 10, $looping, $loops)->clip(0.5, duration: 1.0)->toDisk('memory')
            ->onProgress(function ($progress) use (&$spoolBytes) {
                if ($progress->phase === 'encoding') {
                    foreach (glob($this->root.'/workspaces/*/gif-prepared.nut') as $spool) {
                        $spoolBytes = filesize($spool);
                    }
                }
            })->save('animation.gif');
        $this->assertGreaterThan(0, $spoolBytes);
        $this->assertCount(1, $result->files());
        $this->assertCount(3, $result->processes());
        $bytes = Storage::disk('memory')->get('animation.gif');
        $this->assertStringStartsWith('GIF89a', $bytes);
        $extension = "\x21\xff\x0bNETSCAPE2.0\x03\x01";
        if ($loop === -1) {
            $this->assertStringNotContainsString($extension, $bytes);
        } else {
            $this->assertStringContainsString($extension.pack('v', $loop)."\x00", $bytes);
        }

        $path = $this->root.'/animation.gif';
        file_put_contents($path, $bytes);
        $probe = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-count_frames', '-show_streams', '-show_format', '-of', 'json', $path]);
        $this->assertProcessSucceeded($probe);
        $data = json_decode($probe->output(), true);
        $this->assertCount(1, $data['streams']);
        $stream = $data['streams'][0];
        $this->assertSame('gif', $stream['codec_name']);
        $this->assertSame(64, $stream['width']);
        $this->assertSame(48, $stream['height']);
        $this->assertSame('10', $stream['nb_read_frames']);
        $this->assertSame('10/1', $stream['r_frame_rate']);
        $this->assertEqualsWithDelta(1.0, $data['format']['duration'], 0.01);

        $decoded = Process::run([$binary, '-v', 'error', '-i', $path, '-pix_fmt', 'rgb24', '-f', 'rawvideo', 'pipe:1']);
        $this->assertProcessSucceeded($decoded);
        $frames = str_split($decoded->output(), 64 * 48 * 3);
        $this->assertCount(10, $frames);
        $this->assertGreaterThan(5, count(array_unique(array_map('md5', $frames))));
        $this->assertGreaterThan(50, count(array_unique(str_split($frames[0], 3))));

        $this->assertSame($hash, hash_file('sha256', $source));
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function loopProvider(): array
    {
        // FFmpeg's loop count: 0 loops forever and -1 plays once without a loop extension.
        return [
            'forever' => [0, true, null],
            'once' => [-1, false, null],
            'three times' => [3, true, 3],
        ];
    }

    public function test_clip_selects_source_frames_before_palette_generation(): void
    {
        $source = $this->colorsFixture();
        FFMpeg::disk()->open(new File($source))->gif(64, 10)->clip(1.0, duration: 0.5)->toDisk('memory')->save('blue.gif');
        $image = imagecreatefromstring(Storage::disk('memory')->get('blue.gif'));
        $pixel = imagecolorsforindex($image, imagecolorat($image, 20, 20));
        $this->assertGreaterThan(240, $pixel['blue']);
        $this->assertLessThan(10, $pixel['red']);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_geometry_is_applied_in_caller_order_without_a_clip(): void
    {
        $source = $this->colorsFixture();
        $media = FFMpeg::disk()->open(new File($source));
        $media->gif(64, 12.5)->rotate(90)->resize(32, 40)->toDisk('memory')->save('whole.gif');
        $whole = FFMpeg::disk('memory')->open('whole.gif')->probe();
        $this->assertSame(32, $whole->video()->get('width'));
        $this->assertSame(40, $whole->video()->get('height'));
        $this->assertEqualsWithDelta(2.0, $whole->duration(), 0.01);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_failed_preprocessing_keeps_the_existing_destination(): void
    {
        $source = $this->colorsFixture();
        $media = FFMpeg::disk()->open(new File($source));
        Storage::disk('memory')->put('retained.gif', 'existing destination');
        $hash = hash_file('sha256', $source);
        $this->assertThrows(
            fn () => $media->gif()->videoFilter('not_a_real_filter')->toDisk('memory')->save('retained.gif'),
            fn (MediaException $exception) => $exception->phase === 'analysis',
        );

        $this->assertSame('existing destination', Storage::disk('memory')->get('retained.gif'));
        $this->assertSame($hash, hash_file('sha256', $source));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_cancelled_analysis_keeps_the_existing_destination(): void
    {
        $source = $this->colorsFixture();
        $media = FFMpeg::disk()->open(new File($source));
        Storage::disk('memory')->put('retained.gif', 'existing destination');
        $hash = hash_file('sha256', $source);
        $this->assertThrows(fn () => $media->gif()->toDisk('memory')->onProgress(function ($progress) {
            if ($progress->phase === 'analysis') {
                throw new ExportCancelled;
            }
        })->save('retained.gif'), ExportCancelled::class);

        $this->assertSame('existing destination', Storage::disk('memory')->get('retained.gif'));
        $this->assertSame($hash, hash_file('sha256', $source));
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Write a two second video that turns from red to blue halfway and get its path.
     */
    protected function colorsFixture(): string
    {
        $binary = $this->useRealFFMpeg();
        $source = $this->root.'/colors.mp4';
        $fixture = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'color=red:size=64x48:rate=10:duration=1',
            '-f', 'lavfi', '-i', 'color=blue:size=64x48:rate=10:duration=1',
            '-filter_complex', '[0:v][1:v]concat=n=2:v=1:a=0[v]', '-map', '[v]', '-c:v', 'libx264', '-threads', '1', $source]);
        $this->assertProcessSucceeded($fixture);

        return $source;
    }
}

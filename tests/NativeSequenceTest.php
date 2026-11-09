<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class NativeSequenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/native-sequence-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/workspaces', 0700);
        config(['laravel-ffmpeg.temporary_files_root' => $this->root.'/workspaces']);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function native(): string
    {
        if (! ($probe = getenv('FFMPEG_9_FFPROBE'))) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE to run native sequence acceptance.');
        }

        $binary = dirname($probe).'/ffmpeg';
        config(['laravel-ffmpeg.ffprobe.binaries' => $probe, 'laravel-ffmpeg.ffmpeg.binaries' => $binary]);

        return $binary;
    }

    private function image(array $color, bool $jpeg = false, int $width = 64): string
    {
        $image = imagecreatetruecolor($width, 48);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$color));
        ob_start();
        $jpeg ? imagejpeg($image, quality: 100) : imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    #[Test]
    public function sequence_inspection_is_pure_and_rejects_ambiguous_patterns_rates_and_ranges(): void
    {
        Process::fake();
        $selector = FFMpeg::disk('missing');
        $sequence = $selector->openSequence('photos/frame-%04d.png', count: 3, framerate: '30000/1001', start: 7);
        $command = $sequence->export()->video('libx264', crf: 23)->inspect();
        $this->assertSame('30000/1001', $command[array_search('-framerate', $command) + 1]);
        $this->assertLessThan(array_search('-i', $command), array_search('-framerate', $command));
        try {
            $selector->open('one.png')->concat($sequence);
            $this->fail('A multi-file input was accepted as a standalone concat input.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
        foreach ([['plain.png', 3, 2.5, 1], ['%d/%d.png', 3, 2.5, 1], ['%03d/photo.png', 3, 2.5, 1],
            ['photo-%s.png', 3, 2.5, 1], ['photo-%d.gif', 3, 2.5, 1], ['/photo-%d.png', 3, 2.5, 1],
            ['photo-%d.png', 0, 2.5, 1], ['photo-%d.png', 3, 2.5, -1], ['photo-%d.png', 3, 2.5, PHP_INT_MAX],
            ['photo-%d.png', 3, 0, 1], ['photo-%d.png', 3, INF, 1], ['photo-%d.png', 3, '1/0', 1]] as $arguments) {
            try {
                $selector->openSequence(...$arguments);
                $this->fail('An invalid sequence was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame([], glob($this->root.'/workspaces/*'));
            }
        }
        Process::assertNothingRan();
    }

    #[Test]
    public function local_png_and_remote_jpeg_sequences_preserve_exact_order_count_rate_and_source_bytes(): void
    {
        $binary = $this->native();
        $this->fakeLocalDisk();
        foreach ([['local', 'png', 7, 2.5], ['memory', 'jpg', 0, '5/2']] as [$disk, $suffix, $start, $rate]) {
            $pattern = "photos/é 'quoted'/frame-%04d.$suffix";
            $hashes = [];
            foreach ([[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 255, 255]] as $index => $color) {
                $key = sprintf($pattern, $start + $index);
                $bytes = $this->image($color, $suffix === 'jpg');
                Storage::disk($disk)->put($key, $bytes);
                $hashes[$key] = hash('sha256', $bytes);
            }
            $media = FFMpeg::disk($disk)->openSequence($pattern, count: 3, framerate: $rate, start: $start);
            $this->assertEqualsWithDelta(1.2, $media->probe()->duration(), 0.000001);
            $this->assertSame('[image sequence]', $media->probe()->get('format.filename'));
            $media->export()->video('libx264', crf: 18)->outputOptions(['-pix_fmt', 'yuv420p'])->save('timelapse.mp4');
            $metadata = FFMpeg::disk($disk)->open('timelapse.mp4')->probe();
            $this->assertSame('5/2', $metadata->video()->get('r_frame_rate'));
            $this->assertSame('3', $metadata->video()->get('nb_frames'));
            $this->assertSame(64, $metadata->video()->get('width'));
            $this->assertSame(48, $metadata->video()->get('height'));
            $this->assertEqualsWithDelta(1.2, $metadata->duration(), 0.000001);

            file_put_contents($this->root.'/timelapse.mp4', Storage::disk($disk)->get('timelapse.mp4'));
            $decoded = Process::run([$binary, '-v', 'error', '-i', $this->root.'/timelapse.mp4', '-f', 'rawvideo', '-pix_fmt', 'rgb24', 'pipe:1']);
            $this->assertTrue($decoded->successful(), $decoded->errorOutput());
            $this->assertSame(3 * 64 * 48 * 3, strlen($decoded->output()));
            foreach ([0, 1, 2] as $frame) {
                $pixel = array_values(unpack('C3', substr($decoded->output(), $frame * 64 * 48 * 3, 3)));
                $this->assertGreaterThan(200, $pixel[$frame]);
                $this->assertLessThan(40, $pixel[($frame + 1) % 3]);
            }
            foreach ($hashes as $key => $hash) {
                $this->assertSame($hash, hash('sha256', Storage::disk($disk)->get($key)));
            }
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    #[Test]
    public function missing_mismatched_and_disguised_images_fail_without_partial_publication(): void
    {
        $this->native();
        Storage::disk('memory')->put('photo-07.png', $this->image([255, 0, 0]));
        Storage::disk('memory')->put('photo-09.png', $this->image([0, 0, 255]));
        foreach ([null, $this->image([0, 255, 0], width: 80), 'not an image', $this->image([0, 255, 0], jpeg: true)] as $bytes) {
            if ($bytes !== null) {
                Storage::disk('memory')->put('photo-08.png', $bytes);
            }
            try {
                FFMpeg::disk('memory')->openSequence('photo-%02d.png', count: 3, framerate: 2, start: 7)
                    ->export()->video('libx264', crf: 23)->save('invalid.mp4');
                $this->fail('An incomplete or invalid sequence was accepted.');
            } catch (MediaException $exception) {
                $this->assertSame('staging', $exception->phase);
                $this->assertFalse(Storage::disk('memory')->exists('invalid.mp4'));
                $this->assertSame([], glob($this->root.'/workspaces/*'));
            }
        }
    }

    #[Test]
    public function a_valid_header_with_corrupt_image_data_cannot_publish_a_shorter_timelapse(): void
    {
        $this->native();
        Storage::disk('memory')->put('photo-0.png', $this->image([255, 0, 0]));
        Storage::disk('memory')->put('photo-1.png', substr($this->image([0, 255, 0]), 0, 33));
        Storage::disk('memory')->put('photo-2.png', $this->image([0, 0, 255]));
        try {
            FFMpeg::disk('memory')->openSequence('photo-%d.png', count: 3, framerate: 2, start: 0)
                ->export()->video('libx264', crf: 23)->save('corrupt.mp4');
            $this->fail('A timelapse with a missing decoded frame was published.');
        } catch (MediaException $exception) {
            $this->assertSame('encoding', $exception->phase);
            $this->assertFalse(Storage::disk('memory')->exists('corrupt.mp4'));
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class SequenceTest extends TestCase
{
    /**
     * Get the bytes of a solid image in the given color, 48 pixels high.
     */
    protected static function image(array $color, bool $jpeg = false, int $width = 64): string
    {
        $image = imagecreatetruecolor($width, 48);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$color));

        ob_start();
        $jpeg ? imagejpeg($image, quality: 100) : imagepng($image);
        $bytes = ob_get_clean();

        return $bytes;
    }

    public function test_sequence_framerate_is_inspected_as_an_input_option(): void
    {
        Process::fake();

        $command = FFMpeg::disk('missing')
            ->openSequence('photos/frame-%04d.png', count: 3, framerate: '30000/1001', start: 7)
            ->export()
            ->video('libx264', crf: 23)
            ->inspect();

        $this->assertSame('30000/1001', $command[array_search('-framerate', $command) + 1]);
        $this->assertLessThan(array_search('-i', $command), array_search('-framerate', $command));
        $this->assertWorkspacesAreCleanedUp();
        Process::assertNothingRan();
    }

    public function test_sequences_cannot_be_standalone_concat_inputs(): void
    {
        Process::fake();

        $selector = FFMpeg::disk('missing');
        $sequence = $selector->openSequence('photos/frame-%04d.png', count: 3, framerate: '30000/1001', start: 7);

        $this->assertThrows(
            fn () => $selector->open('one.png')->concat($sequence),
            InvalidArgumentException::class,
        );
        $this->assertWorkspacesAreCleanedUp();
        Process::assertNothingRan();
    }

    #[DataProvider('invalidSequenceProvider')]
    public function test_invalid_sequences_are_rejected(
        string $pattern,
        int $count,
        float|string $framerate,
        int $start,
    ): void {
        Process::fake();

        $this->assertThrows(
            fn () => FFMpeg::disk('missing')->openSequence($pattern, $count, $framerate, $start),
            InvalidArgumentException::class,
        );
        $this->assertWorkspacesAreCleanedUp();
        Process::assertNothingRan();
    }

    /**
     * Get the sequence patterns, counts, rates and start numbers that cannot be opened.
     */
    public static function invalidSequenceProvider(): array
    {
        return [
            'no placeholder' => ['plain.png', 3, 2.5, 1],
            'two placeholders' => ['%d/%d.png', 3, 2.5, 1],
            'placeholder in a directory' => ['%03d/photo.png', 3, 2.5, 1],
            'string placeholder' => ['photo-%s.png', 3, 2.5, 1],
            'unsupported image type' => ['photo-%d.gif', 3, 2.5, 1],
            'absolute path' => ['/photo-%d.png', 3, 2.5, 1],
            'no images' => ['photo-%d.png', 0, 2.5, 1],
            'negative start' => ['photo-%d.png', 3, 2.5, -1],
            'start overflows' => ['photo-%d.png', 3, 2.5, PHP_INT_MAX],
            'zero rate' => ['photo-%d.png', 3, 0, 1],
            'infinite rate' => ['photo-%d.png', 3, INF, 1],
            'zero denominator' => ['photo-%d.png', 3, '1/0', 1],
        ];
    }

    #[DataProvider('sequenceProvider')]
    public function test_sequences_encode_every_frame_in_order_without_changing_the_sources(
        string $disk,
        string $suffix,
        int $start,
        float|string $rate,
    ): void {
        $binary = $this->useRealFFMpeg();
        $this->fakeLocalDisk();
        $pattern = "photos/é 'quoted'/frame-%04d.$suffix";
        $hashes = [];

        // The fourth image is white and lies outside the requested count, so a
        // sequence that reads one image too many shows up in the frame count.
        foreach ([[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 255, 255]] as $index => $color) {
            $key = sprintf($pattern, $start + $index);
            $bytes = static::image($color, $suffix === 'jpg');

            Storage::disk($disk)->put($key, $bytes);

            $hashes[$key] = hash('sha256', $bytes);
        }

        $media = FFMpeg::disk($disk)->openSequence($pattern, count: 3, framerate: $rate, start: $start);

        $this->assertEqualsWithDelta(1.2, $media->probe()->duration(), 0.000001);
        $this->assertSame('[image sequence]', $media->probe()->get('format.filename'));

        $media->export()
            ->video('libx264', crf: 18)
            ->outputOptions(['-pix_fmt', 'yuv420p'])
            ->save('timelapse.mp4');

        $metadata = FFMpeg::disk($disk)->open('timelapse.mp4')->probe();

        $this->assertSame('5/2', $metadata->video()->get('r_frame_rate'));
        $this->assertSame('3', $metadata->video()->get('nb_frames'));
        $this->assertSame(64, $metadata->video()->get('width'));
        $this->assertSame(48, $metadata->video()->get('height'));
        $this->assertEqualsWithDelta(1.2, $metadata->duration(), 0.000001);

        file_put_contents($this->root.'/timelapse.mp4', Storage::disk($disk)->get('timelapse.mp4'));

        $decoded = Process::run([
            $binary, '-v', 'error', '-i', $this->root.'/timelapse.mp4', '-f', 'rawvideo', '-pix_fmt', 'rgb24', 'pipe:1',
        ]);
        $frameBytes = 64 * 48 * 3;

        $this->assertProcessSucceeded($decoded);
        $this->assertSame(3 * $frameBytes, strlen($decoded->output()));

        foreach ([0, 1, 2] as $frame) {
            $pixel = array_values(unpack('C3', substr($decoded->output(), $frame * $frameBytes, 3)));

            $this->assertGreaterThan(200, $pixel[$frame]);
            $this->assertLessThan(40, $pixel[($frame + 1) % 3]);
        }

        foreach ($hashes as $key => $hash) {
            $this->assertSame($hash, hash('sha256', Storage::disk($disk)->get($key)));
        }

        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Get the disks, image types, start numbers and rates of the sequences to encode.
     */
    public static function sequenceProvider(): array
    {
        return [
            'local png' => ['local', 'png', 7, 2.5],
            'memory jpeg' => ['memory', 'jpg', 0, '5/2'],
        ];
    }

    #[DataProvider('invalidMiddleImageProvider')]
    public function test_incomplete_sequences_fail_without_publishing(?string $bytes): void
    {
        $this->useRealFFMpeg();
        Storage::disk('memory')->put('photo-07.png', static::image([255, 0, 0]));
        Storage::disk('memory')->put('photo-09.png', static::image([0, 0, 255]));

        if ($bytes !== null) {
            Storage::disk('memory')->put('photo-08.png', $bytes);
        }

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')
                ->openSequence('photo-%02d.png', count: 3, framerate: 2, start: 7)
                ->export()
                ->video('libx264', crf: 23)
                ->save('invalid.mp4'),
            fn (MediaException $exception) => $exception->phase === 'staging',
        );
        $this->assertFalse(Storage::disk('memory')->exists('invalid.mp4'));
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Get the middle images that are missing, wider than their neighbours, or not PNG images.
     */
    public static function invalidMiddleImageProvider(): array
    {
        return [
            'missing' => [null],
            'mismatched size' => [static::image([0, 255, 0], width: 80)],
            'not an image' => ['not an image'],
            'jpeg disguised as png' => [static::image([0, 255, 0], jpeg: true)],
        ];
    }

    public function test_corrupt_images_cannot_publish_a_shorter_timelapse(): void
    {
        $this->useRealFFMpeg();
        Storage::disk('memory')->put('photo-0.png', static::image([255, 0, 0]));
        Storage::disk('memory')->put('photo-1.png', substr(static::image([0, 255, 0]), 0, 33));
        Storage::disk('memory')->put('photo-2.png', static::image([0, 0, 255]));

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')
                ->openSequence('photo-%d.png', count: 3, framerate: 2, start: 0)
                ->export()
                ->video('libx264', crf: 23)
                ->save('corrupt.mp4'),
            fn (MediaException $exception) => $exception->phase === 'encoding',
        );
        $this->assertFalse(Storage::disk('memory')->exists('corrupt.mp4'));
        $this->assertWorkspacesAreCleanedUp();
    }
}

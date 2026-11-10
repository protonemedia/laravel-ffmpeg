<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Process;
use Orchestra\Testbench\Attributes\RequiresEnv;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Watermark;

#[RequiresEnv('FFMPEG_9_FFPROBE')]
class AnimatedWatermarkTest extends TestCase
{
    /**
     * The directory with the media FFmpeg generated once for the whole class.
     */
    protected static ?string $fixtures = null;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.animated' => ['driver' => 'local', 'root' => $this->root.'/output']]);
    }

    /**
     * Remove the generated media after the last test of the class.
     */
    public static function tearDownAfterClass(): void
    {
        if (static::$fixtures !== null) {
            (new Filesystem)->deleteDirectory(static::$fixtures);

            static::$fixtures = null;
        }

        parent::tearDownAfterClass();
    }

    /**
     * Get the path of the given fixture, generating it with FFmpeg the first time.
     */
    protected function fixture(string $name): string
    {
        $binary = $this->useRealFFMpeg();

        static::$fixtures ??= sys_get_temp_dir().'/laravel-ffmpeg-animated-'.bin2hex(random_bytes(8));

        $path = static::$fixtures.'/'.$name;

        if (is_file($path)) {
            return $path;
        }

        if (! is_dir(static::$fixtures)) {
            mkdir(static::$fixtures, 0700);
        }

        $arguments = match ($name) {
            'source.mp4' => ['-f', 'lavfi', '-i', 'color=c=black:s=80x48:r=10:d=1.6', '-c:v', 'libx264'],
            'logo.gif' => ['-f', 'lavfi', '-i', 'color=c=red:s=20x12:r=5:d=0.2',
                '-f', 'lavfi', '-i', 'color=c=blue:s=20x12:r=5:d=0.2',
                '-filter_complex', '[0:v][1:v]concat=n=2:v=1:a=0[logo]', '-map', '[logo]', '-loop', '-1'],
            'tone.mp4' => ['-f', 'lavfi', '-i', 'color=c=black:s=80x48:r=10:d=1',
                '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=1',
                '-c:v', 'libx264', '-c:a', 'aac', '-shortest'],
            'source.h264' => ['-i', $this->fixture('source.mp4'),
                '-c:v', 'copy', '-bsf:v', 'h264_mp4toannexb', '-f', 'h264'],
        };

        $this->assertProcessSucceeded(Process::run([$binary, '-v', 'error', ...$arguments, $path]));

        return $path;
    }

    /**
     * Get the stream durations of the given file, keyed by codec type.
     */
    protected function durations(string $path): array
    {
        $result = Process::run([
            config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error',
            '-show_entries', 'stream=codec_type,duration', '-of', 'json', $path,
        ]);

        $this->assertProcessSucceeded($result);

        $streams = json_decode($result->output(), true)['streams'];

        return array_column(
            array_map(fn ($stream) => [$stream['codec_type'], round((float) $stream['duration'], 1)], $streams),
            1,
            0,
        );
    }

    /**
     * Decode every frame of the given file to raw RGB bytes.
     */
    protected function decodeToRgb(string $path): string
    {
        $decoded = Process::run([
            $this->useRealFFMpeg(), '-v', 'error', '-i', $path, '-f', 'rawvideo', '-pix_fmt', 'rgb24', 'pipe:1',
        ]);

        $this->assertProcessSucceeded($decoded);

        return $decoded->output();
    }

    /**
     * Get the RGB values of the pixel two rows down and two columns in, inside the watermark.
     */
    protected function markPixel(string $frames, int $frame, int $width, int $height): array
    {
        $offset = $frame * $width * $height * 3 + ($width * 2 + 2) * 3;

        return array_values(unpack('C3', substr($frames, $offset, 3)));
    }

    #[TestWith(['ts'])]
    #[TestWith(['fmp4'])]
    public function test_animated_watermarks_loop_through_every_hls_variant(string $type): void
    {
        // The GIF plays once on its own; loop() must still repeat it for the whole video.
        $media = FFMpeg::open(new File($this->fixture('source.mp4')));
        $logo = FFMpeg::open(new File($this->fixture('logo.gif')));
        $corner = fn (Watermark $watermark) => $watermark->left()
            ->top()
            ->size(width: 10)
            ->loop();

        $export = $media->hls(segmentDuration: 0.4)
            ->watermark($logo, $corner)
            ->video('libx264', crf: 12)
            ->toDisk('animated')
            ->variant('small', fn ($variant) => $variant->resize(width: 40))
            ->variant('large', fn ($variant) => $variant->resize(width: 80));

        if ($type === 'fmp4') {
            $export->fmp4();
        }

        $result = $export->save($type.'/master.m3u8');

        foreach (['small' => [40, 24], 'large' => [80, 48]] as $name => [$width, $height]) {
            $frames = $this->decodeToRgb($this->root.'/output/'.$result->variant($name)->playlist()->path);

            $this->assertSame(16, intdiv(strlen($frames), $width * $height * 3));

            // Both colors must still alternate after several full GIF loops.
            foreach ([8 => 0, 10 => 2, 12 => 0, 14 => 2] as $frame => $channel) {
                $pixel = $this->markPixel($frames, $frame, $width, $height);

                $message = $type.' '.$name.' frame '.$frame.' '.json_encode($pixel);

                $this->assertGreaterThan(160, $pixel[$channel], $message);
                $this->assertLessThan(70, $pixel[$channel === 0 ? 2 : 0]);
            }
        }

        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_edited_animated_watermarks_keep_every_frame(): void
    {
        $media = FFMpeg::open(new File($this->fixture('source.mp4')));
        $logo = FFMpeg::open(new File($this->fixture('logo.gif')));

        $media->export()
            ->watermark($logo, fn (Watermark $watermark) => $watermark->left()
                ->top()
                ->size(width: 10)
                ->loop()
                ->grayscale())
            ->video('libx264', crf: 12)
            ->toDisk('animated')
            ->save('gray.mp4');

        // Edits keep every GIF frame: the grayscale mark still alternates between two brightness levels.
        $frames = $this->decodeToRgb($this->root.'/output/gray.mp4');
        $brightness = [];

        foreach ([8, 10, 12, 14] as $frame) {
            $pixel = $this->markPixel($frames, $frame, 80, 48);

            $this->assertLessThan(16, max($pixel) - min($pixel), 'frame '.$frame.' '.json_encode($pixel));

            $brightness[] = $pixel[1];
        }

        // Red becomes brighter gray than blue.
        $this->assertGreaterThan($brightness[1] + 20, $brightness[0]);
        $this->assertGreaterThan($brightness[3] + 20, $brightness[2]);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_looping_watermarks_end_with_the_video_without_cutting_audio_or_clips(): void
    {
        $logo = FFMpeg::open(new File($this->fixture('logo.gif')));
        $looping = fn (Watermark $watermark) => $watermark->loop();

        // The looping overlay ends with the main video without cutting longer streams or clips.
        FFMpeg::open(new File($this->fixture('tone.mp4')))
            ->export()
            ->watermark($logo, $looping)
            ->delayAudio(0.5)
            ->video('libx264')
            ->audio('aac')
            ->toDisk('animated')
            ->save('delayed.mp4');

        FFMpeg::open(new File($this->fixture('source.mp4')))
            ->export()
            ->watermark($logo, $looping)
            ->clip(0.4, duration: 0.6)
            ->video('libx264')
            ->toDisk('animated')
            ->save('clipped.mp4');

        $this->assertEqualsWithDelta(1.5, $this->durations($this->root.'/output/delayed.mp4')['audio'], 0.1);
        $this->assertEqualsWithDelta(1.0, $this->durations($this->root.'/output/delayed.mp4')['video'], 0.1);
        $this->assertEqualsWithDelta(0.6, $this->durations($this->root.'/output/clipped.mp4')['video'], 0.1);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_looping_watermarks_end_with_raw_streams_that_have_no_duration(): void
    {
        $logo = FFMpeg::open(new File($this->fixture('logo.gif')));

        // A raw stream has no container duration; the overlay still ends with it.
        FFMpeg::open(new File($this->fixture('source.h264')))
            ->export()
            ->watermark($logo, fn (Watermark $watermark) => $watermark->loop())
            ->video('libx264')
            ->toDisk('animated')
            ->save('raw.mp4');

        $this->assertEqualsWithDelta(1.6, $this->durations($this->root.'/output/raw.mp4')['video'], 0.1);
        $this->assertWorkspacesAreCleanedUp();
    }
}

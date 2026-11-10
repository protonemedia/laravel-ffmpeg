<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Http\File;
use Illuminate\Support\Facades\Process;
use Orchestra\Testbench\Attributes\RequiresEnv;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Watermark;

#[RequiresEnv('FFMPEG_9_FFPROBE')]
class AnimatedWatermarkTest extends TestCase
{
    public function test_animated_watermarks_loop_through_finite_multi_variant_ts_and_fmp4_exports(): void
    {
        $binary = $this->useRealFFMpeg();
        $probe = config('laravel-ffmpeg.ffprobe.binaries');
        config(['filesystems.disks.animated' => ['driver' => 'local', 'root' => $this->root.'/output']]);
        $source = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'color=c=black:s=80x48:r=10:d=1.6',
            '-c:v', 'libx264', $this->root.'/source.mp4']);
        $this->assertProcessSucceeded($source);
        $gif = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'color=c=red:s=20x12:r=5:d=0.2',
            '-f', 'lavfi', '-i', 'color=c=blue:s=20x12:r=5:d=0.2',
            '-filter_complex', '[0:v][1:v]concat=n=2:v=1:a=0[logo]', '-map', '[logo]', '-loop', '-1', $this->root.'/logo.gif']);
        $this->assertProcessSucceeded($gif);
        // The GIF plays once on its own; loop() must still repeat it for the whole video.
        $media = FFMpeg::open(new File($this->root.'/source.mp4'));
        $logo = FFMpeg::open(new File($this->root.'/logo.gif'));
        $looping = fn (Watermark $watermark) => $watermark->loop();
        $corner = fn (Watermark $watermark) => $watermark->left()->top()->size(width: 10)->loop();
        foreach (['ts', 'fmp4'] as $type) {
            $export = $media->hls(segmentDuration: 0.4)->watermark($logo, $corner)
                ->video('libx264', crf: 12)->toDisk('animated')
                ->variant('small', fn ($variant) => $variant->resize(width: 40))
                ->variant('large', fn ($variant) => $variant->resize(width: 80));
            if ($type === 'fmp4') {
                $export->fmp4();
            }
            $result = $export->save($type.'/master.m3u8');
            foreach (['small' => 40, 'large' => 80] as $name => $width) {
                $playlist = $this->root.'/output/'.$result->variant($name)->playlist()->path;
                $decoded = Process::run([$binary, '-v', 'error', '-i', $playlist, '-f', 'rawvideo', '-pix_fmt', 'rgb24', 'pipe:1']);
                $this->assertProcessSucceeded($decoded);
                $height = $width === 40 ? 24 : 48;
                $frameBytes = $width * $height * 3;
                $this->assertSame(16, intdiv(strlen($decoded->output()), $frameBytes));
                // Both colors must still alternate after several full GIF loops.
                foreach ([8 => 0, 10 => 2, 12 => 0, 14 => 2] as $frame => $channel) {
                    $pixel = array_values(unpack('C3', substr($decoded->output(), $frame * $frameBytes + ($width * 2 + 2) * 3, 3)));
                    $this->assertGreaterThan(160, $pixel[$channel], $type.' '.$name.' frame '.$frame.' '.json_encode($pixel));
                    $this->assertLessThan(70, $pixel[$channel === 0 ? 2 : 0]);
                }
            }
        }
        // Edits keep every GIF frame: the grayscale mark still alternates between two brightness levels.
        $media->export()->watermark($logo, fn (Watermark $watermark) => $corner($watermark)->grayscale())
            ->video('libx264', crf: 12)->toDisk('animated')->save('gray.mp4');
        $decoded = Process::run([$binary, '-v', 'error', '-i', $this->root.'/output/gray.mp4', '-f', 'rawvideo', '-pix_fmt', 'rgb24', 'pipe:1']);
        $this->assertProcessSucceeded($decoded);
        $brightness = [];
        foreach ([8, 10, 12, 14] as $frame) {
            $pixel = array_values(unpack('C3', substr($decoded->output(), $frame * 80 * 48 * 3 + (80 * 2 + 2) * 3, 3)));
            $this->assertLessThan(16, max($pixel) - min($pixel), 'frame '.$frame.' '.json_encode($pixel));
            $brightness[] = $pixel[1];
        }
        // Red becomes brighter gray than blue.
        $this->assertGreaterThan($brightness[1] + 20, $brightness[0]);
        $this->assertGreaterThan($brightness[3] + 20, $brightness[2]);
        // The looping overlay ends with the main video without cutting longer streams or clips.
        $tone = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'color=c=black:s=80x48:r=10:d=1',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=1', '-c:v', 'libx264', '-c:a', 'aac', '-shortest', $this->root.'/tone.mp4']);
        $this->assertProcessSucceeded($tone);
        $durations = function (string $path) use ($probe): array {
            $result = Process::run([$probe, '-v', 'error', '-show_entries', 'stream=codec_type,duration', '-of', 'json', $path]);
            $this->assertProcessSucceeded($result);

            return array_column(array_map(fn ($stream) => [$stream['codec_type'], round((float) $stream['duration'], 1)],
                json_decode($result->output(), true)['streams']), 1, 0);
        };
        FFMpeg::open(new File($this->root.'/tone.mp4'))->export()->watermark($logo, $looping)->delayAudio(0.5)
            ->video('libx264')->audio('aac')->toDisk('animated')->save('delayed.mp4');
        $this->assertEqualsWithDelta(1.5, $durations($this->root.'/output/delayed.mp4')['audio'], 0.1);
        $this->assertEqualsWithDelta(1.0, $durations($this->root.'/output/delayed.mp4')['video'], 0.1);
        $media->export()->watermark($logo, $looping)->clip(0.4, duration: 0.6)->video('libx264')->toDisk('animated')->save('clipped.mp4');
        $this->assertEqualsWithDelta(0.6, $durations($this->root.'/output/clipped.mp4')['video'], 0.1);
        // A raw stream has no container duration; the overlay still ends with it.
        $raw = Process::run([$binary, '-v', 'error', '-i', $this->root.'/source.mp4', '-c:v', 'copy', '-bsf:v', 'h264_mp4toannexb', '-f', 'h264', $this->root.'/source.h264']);
        $this->assertProcessSucceeded($raw);
        FFMpeg::open(new File($this->root.'/source.h264'))->export()->watermark($logo, $looping)->video('libx264')->toDisk('animated')->save('raw.mp4');
        $this->assertEqualsWithDelta(1.6, $durations($this->root.'/output/raw.mp4')['video'], 0.1);
        $this->assertWorkspacesAreCleanedUp();
    }
}

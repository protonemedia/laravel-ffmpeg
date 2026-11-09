<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Http\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\PendingExport;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class NativeAnimatedWatermarkTest extends TestCase
{
    #[Test]
    public function animated_watermarks_loop_through_finite_multi_variant_ts_and_fmp4_exports(): void
    {
        if (! ($probe = getenv('FFMPEG_9_FFPROBE'))) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for animated-watermark acceptance.');
        }
        $binary = dirname($probe).'/ffmpeg';
        $root = sys_get_temp_dir().'/native-animated-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        mkdir($root.'/work', 0700);
        config(['laravel-ffmpeg.ffmpeg.binaries' => $binary, 'laravel-ffmpeg.ffprobe.binaries' => $probe,
            'laravel-ffmpeg.temporary_files_root' => $root.'/work',
            'filesystems.disks.animated' => ['driver' => 'local', 'root' => $root.'/output']]);
        try {
            $source = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'color=c=black:s=80x48:r=10:d=1.6',
                '-c:v', 'libx264', $root.'/source.mp4']);
            $this->assertTrue($source->successful(), $source->errorOutput());
            $gif = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'color=c=red:s=20x12:r=5:d=0.2',
                '-f', 'lavfi', '-i', 'color=c=blue:s=20x12:r=5:d=0.2',
                '-filter_complex', '[0:v][1:v]concat=n=2:v=1:a=0[logo]', '-map', '[logo]', '-loop', '0', $root.'/logo.gif']);
            $this->assertTrue($gif->successful(), $gif->errorOutput());
            $media = FFMpeg::open(new File($root.'/source.mp4'));
            $logo = FFMpeg::open(new File($root.'/logo.gif'), inputOptions: ['-ignore_loop', '0']);
            foreach (['ts', 'fmp4'] as $type) {
                $export = $media->hls(segmentDuration: 0.4)->watermark($logo, position: 'top-left', width: 10)
                    ->video('libx264', crf: 12)->toDisk('animated')
                    ->variant('small', fn ($variant) => $variant->resize(width: 40))
                    ->variant('large', fn ($variant) => $variant->resize(width: 80));
                if ($type === 'fmp4') {
                    $export->fmp4();
                }
                $result = $export->save($type.'/master.m3u8');
                foreach (['small' => 40, 'large' => 80] as $name => $width) {
                    $playlist = $root.'/output/'.$result->variant($name)->playlist()->path;
                    $decoded = Process::run([$binary, '-v', 'error', '-i', $playlist, '-f', 'rawvideo', '-pix_fmt', 'rgb24', 'pipe:1']);
                    $this->assertTrue($decoded->successful(), $decoded->errorOutput());
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
            // The looping overlay ends with the main video without cutting longer streams or clips.
            $tone = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'color=c=black:s=80x48:r=10:d=1',
                '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=1', '-c:v', 'libx264', '-c:a', 'aac', '-shortest', $root.'/tone.mp4']);
            $this->assertTrue($tone->successful(), $tone->errorOutput());
            $durations = function (string $path) use ($probe): array {
                $result = Process::run([$probe, '-v', 'error', '-show_entries', 'stream=codec_type,duration', '-of', 'json', $path]);
                $this->assertTrue($result->successful(), $result->errorOutput());

                return array_column(array_map(fn ($stream) => [$stream['codec_type'], round((float) $stream['duration'], 1)],
                    json_decode($result->output(), true)['streams']), 1, 0);
            };
            FFMpeg::open(new File($root.'/tone.mp4'))->export()->watermark($logo)->delayAudio(0.5)
                ->video('libx264')->audio('aac')->toDisk('animated')->save('delayed.mp4');
            $this->assertEqualsWithDelta(1.5, $durations($root.'/output/delayed.mp4')['audio'], 0.1);
            $this->assertEqualsWithDelta(1.0, $durations($root.'/output/delayed.mp4')['video'], 0.1);
            $media->export()->watermark($logo)->clip(0.4, duration: 0.6)->video('libx264')->toDisk('animated')->save('clipped.mp4');
            $this->assertEqualsWithDelta(0.6, $durations($root.'/output/clipped.mp4')['video'], 0.1);
            // A raw stream has no container duration; the overlay still ends with it.
            $raw = Process::run([$binary, '-v', 'error', '-i', $root.'/source.mp4', '-c:v', 'copy', '-bsf:v', 'h264_mp4toannexb', '-f', 'h264', $root.'/source.h264']);
            $this->assertTrue($raw->successful(), $raw->errorOutput());
            FFMpeg::open(new File($root.'/source.h264'))->export()->watermark($logo)->video('libx264')->toDisk('animated')->save('raw.mp4');
            $this->assertEqualsWithDelta(1.6, $durations($root.'/output/raw.mp4')['video'], 0.1);

            // Overlay setup must not depend on a subclass calling parent::prepareEncoding().
            $custom = new class($media, 'animated') extends PendingExport
            {
                protected function prepareEncoding(array $metadata): ?float
                {
                    return null;
                }
            };
            $custom->watermark($logo)->video('libx264')->save('custom.mp4');
            $this->assertEqualsWithDelta(1.6, FFMpeg::disk('animated')->open('custom.mp4')->probe()->duration(), 0.05);
            $this->assertSame([], glob($root.'/work/*'));
        } finally {
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($root);
        }
    }
}

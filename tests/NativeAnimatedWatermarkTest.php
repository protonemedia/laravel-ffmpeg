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

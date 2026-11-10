<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class InputHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.disks.input-helpers' => ['driver' => 'local', 'root' => $this->root.'/output']]);
    }

    public function test_still_video_is_finite_and_preserves_local_and_remote_image_content(): void
    {
        $binary = $this->useRealFFMpeg();
        $image = imagecreatetruecolor(80, 48);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 10, 30));
        imagepng($image, $this->root.'/source.png');
        imagedestroy($image);
        $bytes = file_get_contents($this->root.'/source.png');
        Storage::disk('memory')->put('source.png', $bytes);
        foreach ([FFMpeg::open(new File($this->root.'/source.png')), FFMpeg::disk('memory')->open('source.png')] as $index => $image) {
            $export = $image->still(1.2, frameRate: 10)->toDisk('input-helpers');
            $command = $export->inspect();
            $this->assertLessThan(array_search('-i', $command), array_search('-loop', $command));
            $export->save($index.'.mp4');
            $result = FFMpeg::disk('input-helpers')->open($index.'.mp4')->probe();
            $this->assertEqualsWithDelta(1.2, $result->duration(), 0.0001);
            $this->assertSame('12', $result->video()->get('nb_frames'));
            $this->assertSame('yuv420p', $result->video()->get('pix_fmt'));
            $decoded = Process::run([$binary, '-v', 'error', '-i', $this->root.'/output/'.$index.'.mp4',
                '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', 'pipe:1']);
            $this->assertProcessSucceeded($decoded);
            $pixel = array_values(unpack('C3', substr($decoded->output(), 0, 3)));
            $this->assertGreaterThan(220, $pixel[0]);
            $this->assertLessThan(40, $pixel[1]);
        }
        $this->assertSame($bytes, file_get_contents($this->root.'/source.png'));
        $this->assertSame($bytes, Storage::disk('memory')->get('source.png'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_invalid_still_inputs_fail_without_publication(): void
    {
        $this->useRealFFMpeg();
        Storage::disk('memory')->put('bad.png', 'not image data');
        $this->assertThrows(function () {
            FFMpeg::disk('memory')->open('bad.png')->still(1)->toDisk('input-helpers')->save('bad.mp4');
        }, function (MediaException $exception) {
            $this->assertSame('image', $exception->category);

            return true;
        });
        $this->assertFalse(Storage::disk('input-helpers')->exists('bad.mp4'));
        foreach ([[0, 25], [-1, 25], [INF, 25], [NAN, 25], [1, 0], [1, 241]] as [$duration, $rate]) {
            $this->assertThrows(fn () => FFMpeg::open('photo.png')->still($duration, $rate), InvalidArgumentException::class);
            $this->assertWorkspacesAreCleanedUp();
        }
    }

    public function test_cache_hits_preserve_metadata_and_source_bound_stream_selection_and_version_keys_invalidate(): void
    {
        $binary = $this->useRealFFMpeg();
        $path = $this->root.'/input.mp4';
        $make = fn ($width) => Process::run([$binary, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s='.$width.'x48:r=10',
            '-t', '1', '-c:v', 'libx264', $path]);
        $this->assertTrue($make(80)->successful());
        $media = FFMpeg::open(new File($path));
        $cache = new Repository(new ArrayStore);
        $first = $media->probeCached($cache, 'version-one');
        $this->assertSame(80, $first->video()->width());
        $this->assertTrue($make(96)->successful());
        $this->assertSame(80, $media->probeCached($cache, 'version-one')->video()->width());
        $this->assertSame(96, $media->probeCached($cache, 'version-two')->video()->width());
        $media->export()->mapVideo($first->video())->video('copy')->withoutMetadata(chapters: true)
            ->toDisk('input-helpers')->save('mapped.mp4');
        $this->assertSame(96, FFMpeg::disk('input-helpers')->open('mapped.mp4')->probe()->video()->width());
        Process::fake();
        $this->assertSame($first->toArray(), $media->probeCached($cache, 'version-one')->toArray());
        Process::assertNothingRan();
    }

    public function test_hardware_and_gif_options_are_input_scoped_and_never_passed_to_ffprobe(): void
    {
        $binary = $this->root.'/probe';
        file_put_contents($binary, "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> '".$this->root."/args'\ncase \"\$1\" in -version) echo 'ffprobe version 9.0.2';; *) echo '{\"format\":{},\"streams\":[]}' ;; esac\n");
        chmod($binary, 0700);
        config(['laravel-ffmpeg.ffprobe.binaries' => $binary]);
        file_put_contents($this->root.'/input', 'input');
        $media = FFMpeg::open(new File($this->root.'/input'), inputOptions: [
            '-hwaccel', 'cuda', '-hwaccel_device', '0', '-hwaccel_output_format', 'cuda', '-ignore_loop', '0', '-probesize', '65536',
        ]);
        $command = $media->export()->video('h264_nvenc')->inspect();
        $this->assertLessThan(array_search('-i', $command), array_search('-hwaccel', $command));
        $media->probe();
        $arguments = file_get_contents($this->root.'/args');
        $this->assertStringContainsString('-probesize', $arguments);
        foreach (['-hwaccel', '-hwaccel_device', '-hwaccel_output_format', '-ignore_loop'] as $option) {
            $this->assertStringNotContainsString($option, $arguments);
        }
        foreach ([['-hwaccel', 'auto'], ['-hwaccel', 'bogus'], ['-ignore_loop', '-1'], ['-hwaccel_device', "0\nsecret"]] as $options) {
            $this->assertThrows(fn () => FFMpeg::open('input', inputOptions: $options), InvalidArgumentException::class);
        }
    }

    public function test_explicit_hardware_backends_and_encoders_compile_for_exports_and_hls_without_leaking_between_inputs(): void
    {
        Process::fake();
        foreach ([['cuda', 'h264_nvenc'], ['cuda', 'hevc_nvenc'], ['vaapi', 'hevc_vaapi'], ['qsv', 'hevc_qsv']] as [$backend, $encoder]) {
            $options = ['-hwaccel', $backend, '-hwaccel_device', '0', '-hwaccel_output_format', $backend];
            $media = FFMpeg::open('hardware.mp4', inputOptions: $options);
            $export = $media->export()->map('0:v:0')->withoutMetadata(chapters: true)->video($encoder);
            $export->addInput(FFMpeg::open('software.mp4'));
            foreach ([$export, $media->hls()->variant('hardware', fn ($variant) => $variant->video($encoder))] as $pending) {
                $command = $pending->inspect();
                $input = array_search('-i', $command, true);
                $hardware = array_search('-hwaccel', $command, true);
                $this->assertSame($options, array_slice($command, $hardware, count($options)));
                $this->assertLessThan($input, $hardware + count($options) - 1);
                $this->assertSame(1, count(array_keys($command, '-hwaccel', true)));
                $codec = array_search('-c:v', $command, true);
                $this->assertNotFalse($codec);
                $this->assertGreaterThan($input, $codec);
                $this->assertSame($encoder, $command[$codec + 1]);
                $this->assertNotContains('libx264', $command);
            }
        }
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_an_unavailable_hardware_encoder_fails_explicitly_without_software_fallback_or_publication(): void
    {
        $binary = $this->useRealFFMpeg();
        $encoders = Process::run([$binary, '-hide_banner', '-encoders']);
        $this->assertProcessSucceeded($encoders);
        if (preg_match('/\bh264_nvenc\b/', $encoders->output())) {
            $this->markTestSkipped('This acceptance case requires a build without h264_nvenc, not an assumption about GPU availability.');
        }
        $path = $this->root.'/source.mp4';
        $source = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=80x48:r=10',
            '-t', '0.4', '-c:v', 'libx264', '-threads', '1', $path]);
        $this->assertProcessSucceeded($source);
        $hash = hash_file('sha256', $path);
        $media = FFMpeg::open(new File($path));
        foreach (['hardware.mp4' => $media->export()->video('h264_nvenc'),
            'hardware/master.m3u8' => $media->hls()->variant('gpu', fn ($variant) => $variant->video('h264_nvenc'))] as $output => $export) {
            $this->assertThrows(fn () => $export->toDisk('input-helpers')->save($output), function (MediaException $exception) {
                $this->assertSame('encoding', $exception->phase);
                $this->assertSame('exit', $exception->category);
                $this->assertStringContainsString("Unknown encoder 'h264_nvenc'", $exception->diagnostics);
                $this->assertStringNotContainsString($this->root, $exception->diagnostics);

                return true;
            });
            $this->assertSame([], Storage::disk('input-helpers')->allFiles());
            $this->assertWorkspacesAreCleanedUp();
            $this->assertSame($hash, hash_file('sha256', $path));
        }
    }

    public function test_an_unavailable_hardware_decoder_fails_instead_of_silently_using_software_decoding(): void
    {
        $binary = $this->useRealFFMpeg();
        $backends = Process::run([$binary, '-hide_banner', '-hwaccels']);
        $this->assertProcessSucceeded($backends);
        if (preg_match('/^cuda$/m', $backends->output())) {
            $this->markTestSkipped('This acceptance case requires a build without CUDA decoding support.');
        }
        $path = __DIR__.'/src/video.mp4';
        $hash = hash_file('sha256', $path);
        $this->assertThrows(function () use ($path) {
            FFMpeg::open(new File($path), inputOptions: ['-hwaccel', 'cuda'])->export()
                ->video('libx264')->toDisk('input-helpers')->save('hardware-decoding.mp4');
        }, function (MediaException $exception) {
            $this->assertSame('encoding', $exception->phase);
            $this->assertSame('exit', $exception->category);
            $this->assertStringContainsString('No device available for decoder: device type cuda', $exception->diagnostics);
            $this->assertStringNotContainsString($this->root, $exception->diagnostics);

            return true;
        });
        $this->assertSame([], Storage::disk('input-helpers')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
        $this->assertSame($hash, hash_file('sha256', $path));
    }

    public function test_rtsp_snapshots_capture_real_tcp_and_udp_frames_and_bound_failed_cameras(): void
    {
        $binary = $this->useRealFFMpeg();
        config(['laravel-ffmpeg.redact_credentials' => true]);
        $encoded = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'color=c=green:s=80x48:r=10',
            '-t', '1', '-c:v', 'libx264', '-preset', 'ultrafast', '-tune', 'zerolatency',
            '-x264-params', 'aud=1:repeat-headers=1:keyint=1', '-f', 'h264', $this->root.'/frames.h264']);
        $this->assertProcessSucceeded($encoded);
        foreach ([['tcp', 'ok'], ['udp', 'ok'], ['tcp', 'denied'], ['tcp', 'stall']] as $index => [$transport, $mode]) {
            $ready = $this->root.'/server-'.$index;
            $server = Process::start([PHP_BINARY, __DIR__.'/fixtures/rtsp.php', $this->root.'/frames.h264', $ready, $mode]);
            try {
                $deadline = microtime(true) + 3;
                while (! is_file($ready) && $server->running() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertFileExists($ready, $server->errorOutput());
                $url = 'rtsp://camera-user:camera-password@'.file_get_contents($ready).'/camera?grant=camera-token';
                $frame = FFMpeg::openRtsp($url, $transport, timeout: 1.5)->frame()->toDisk('input-helpers');
                $inspection = json_encode($frame->inspect());
                foreach (['camera-user', 'camera-password', 'camera-token'] as $secret) {
                    $this->assertStringNotContainsString($secret, $inspection);
                }
                $started = microtime(true);
                try {
                    $frame->save('snapshot-'.$index.'.png');
                    $this->assertSame('ok', $mode, 'A failing camera published a snapshot.');
                    $image = imagecreatefromstring(Storage::disk('input-helpers')->get('snapshot-'.$index.'.png'));
                    $this->assertSame(80, imagesx($image));
                    $this->assertSame(48, imagesy($image));
                    $color = imagecolorsforindex($image, imagecolorat($image, 10, 10));
                    $this->assertGreaterThan(90, $color['green']);
                    $this->assertLessThan(20, $color['red']);
                    imagedestroy($image);
                } catch (MediaException $exception) {
                    $this->assertNotSame('ok', $mode, $exception->diagnostics);
                    $this->assertLessThan(3, microtime(true) - $started);
                    foreach (['camera-user', 'camera-password', 'camera-token'] as $secret) {
                        $this->assertStringNotContainsString($secret, $exception->diagnostics);
                    }
                    $this->assertFalse(Storage::disk('input-helpers')->exists('snapshot-'.$index.'.png'));
                }
                $this->assertWorkspacesAreCleanedUp();
            } finally {
                if ($server->running()) {
                    $server->stop();
                }
                $server->wait();
            }
        }
    }
}

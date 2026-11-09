<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Events;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use RuntimeException;

class NativeExportTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/native-export-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/workspaces', 0700);
        config(['laravel-ffmpeg.temporary_files_root' => $this->root.'/workspaces']);
        Storage::disk('memory')->put('source.mp4', 'original media');
        Event::fake([Events\ExportStarting::class, Events\ExportCompleted::class, Events\ExportFailed::class, Events\ExportCancelled::class]);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    private function metadata(): string
    {
        return '{"format":{"duration":"10.0"},"streams":[{"index":0,"codec_type":"video"},{"index":1,"codec_type":"audio"}],"chapters":[]}';
    }

    private function fakePipeline(?callable $encode = null, ?string $metadata = null): void
    {
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($encode, $metadata) {
            $tool = basename($process->command[0]);

            if (in_array('-version', $process->command, true)) {
                return Process::result(output: $tool.' version 9.0.2');
            }

            if ($tool === 'ffprobe') {
                return Process::result(output: $metadata ?? $this->metadata());
            }

            if ($encode !== null) {
                return $encode($process);
            }

            file_put_contents(end($process->command), 'encoded media');

            return Process::result(output: "out_time_us=4000000\nspeed=2.0x\nprogress=end\n");
        });
    }

    #[Test]
    public function inspection_is_pure_scoped_and_does_not_share_output_settings(): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing-disk')->open('unread.mp4', inputOptions: ['-probesize', '2000000']);
        $export = $media->export()->globalOptions(['-filter_threads', '2'])
            ->video('libx264', crf: 21, preset: 'slow')->audio('aac', bitrate: '96k')
            ->map('0:a:0')->map('0:V:0?')->map('-0:a:1?')
            ->videoFilter('crop=120:80:4:2')->videoFilter('hflip')
            ->clip(12.5, end: 20.5)->metadata('title', 'A "quoted" title; $HOME')
            ->streamMetadata(0, 'title', 'First output stream')->streamMetadata('a:0', 'language', 'eng')
            ->disposition('a:0', 'default+forced')
            ->outputOptions(['-movflags', '+faststart', '-streamid', '0:42', '-streamid', '1:43']);

        $expected = [
            'ffmpeg', '-hide_banner', '-nostdin', '-nostats', '-y', '-xerror', '-loglevel', 'warning', '-progress', 'pipe:1',
            '-filter_threads', '2', '-protocol_whitelist', 'file', '-probesize', '2000000', '-i', '[input:0]',
            '-ss', '12.5', '-t', '8', '-map', '0:a:0', '-map', '0:V:0:?', '-map', '-0:a:1:?',
            '-map_metadata', '0', '-map_chapters', '-1', '-c:v', 'libx264', '-crf:v', '21', '-preset:v', 'slow',
            '-filter:v', 'crop=120:80:4:2,hflip', '-c:a', 'aac', '-b:a', '96k',
            '-metadata', 'title=A "quoted" title; $HOME', '-metadata:s:0', 'title=First output stream',
            '-metadata:s:a:0', 'language=eng', '-disposition:a:0', 'default+forced', '-fps_mode:v', 'passthrough', '-threads', '1',
            '-movflags', '+faststart', '-streamid', '0:42', '-streamid', '1:43', '[output:0]',
        ];
        $this->assertSame($expected, $export->inspect());
        $this->assertSame($expected, $export->inspect());
        $this->assertNotContains('libx264', $media->export()->inspect());
        $this->assertNotContains('-ss', $media->export()->inspect());
        $this->assertNotContains('language=eng', $media->export()->inspect());
        Process::assertNothingRan();
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function geometry_helpers_share_filter_order_output_scopes_and_copy_restrictions(): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('absent.mkv');
        $export = $media->export()->videoFilter('hflip')->resize(width: 80)->videoFilter('vflip');
        $this->assertContains('hflip,scale=w=80:h=-2:reset_sar=1,vflip', $export->inspect());
        $this->assertNotContains('hflip,scale=w=80:h=-2:reset_sar=1,vflip', $media->export()->inspect());
        $command = $media->export()
            ->output('wide.mkv', fn ($out) => $out->resize(height: 40))
            ->output('square.mkv', fn ($out) => $out->fit(80, 80))->inspect();
        $boundary = array_search('[output:0]', $command);
        $this->assertContains('scale=w=-2:h=40:reset_sar=1', array_slice($command, 0, $boundary));
        $this->assertContains('scale=w=80:h=80:force_original_aspect_ratio=decrease:force_divisible_by=2:reset_sar=1,pad=w=80:h=80:x=(ow-iw)/2:y=(oh-ih)/2:color=black', array_slice($command, $boundary));

        foreach ([
            fn () => $media->export()->resize(),
            fn () => $media->export()->resize(width: 0),
            fn () => $media->export()->resize(height: -2),
            fn () => $media->export()->fit(0, 80),
            fn () => $media->export()->fit(81, 80),
            fn () => $media->export()->fit(80, 80, mode: 'stretch'),
            fn () => $media->export()->resize(width: 80)->video('copy')->inspect(),
            fn () => $media->export()->fit(80, 80)->video('copy')->inspect(),
            fn () => $media->export()->output('one.mkv', fn () => null)->resize(width: 80),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Invalid geometry configuration was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        Process::assertNothingRan();
    }

    #[Test]
    public function real_geometry_helpers_preserve_display_aspect_or_explicitly_stretch_and_center(): void
    {
        $probe = getenv('FFMPEG_9_FFPROBE');

        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for real geometry helper acceptance.');
        }

        $ffmpeg = dirname($probe).'/ffmpeg';
        config(['laravel-ffmpeg.ffmpeg.binaries' => $ffmpeg, 'laravel-ffmpeg.ffprobe.binaries' => $probe]);
        $local = $this->fakeLocalDisk();
        $generation = Process::run([$ffmpeg, '-v', 'error', '-f', 'lavfi', '-i',
            'color=red:size=160x80:rate=10:duration=0.3,drawbox=x=0:y=0:w=40:h=80:color=blue:t=fill,drawbox=x=120:y=0:w=40:h=80:color=green:t=fill,setsar=2',
            '-c:v', 'ffv1', $local->path('anamorphic.mkv')]);
        $this->assertTrue($generation->successful(), $generation->errorOutput());
        $media = FFMpeg::disk('local')->open('anamorphic.mkv');
        $media->export()
            ->output('width.mkv', fn ($out) => $out->map('0:v')->resize(width: 80)->video('ffv1')->toDisk('local'))
            ->output('height.mkv', fn ($out) => $out->map('0:v')->resize(height: 40)->video('ffv1')->toDisk('local'))
            ->output('stretch.mkv', fn ($out) => $out->map('0:v')->resize(80, 80)->video('ffv1')->toDisk('local'))
            ->output('contain.mkv', fn ($out) => $out->map('0:v')->fit(80, 80)->video('ffv1')->toDisk('local'))
            ->output('cover.mkv', fn ($out) => $out->map('0:v')->fit(80, 80, mode: 'cover')->video('ffv1')->toDisk('local'))
            ->save();

        foreach (['width' => [80, 20], 'height' => [160, 40], 'stretch' => [80, 80], 'contain' => [80, 80], 'cover' => [80, 80]] as $name => $size) {
            $video = FFMpeg::disk('local')->open($name.'.mkv')->probe()->video();
            $this->assertSame($size, [$video->width(), $video->height()], $name);
            $this->assertSame('1:1', $video->get('sample_aspect_ratio'), $name);
        }

        foreach (['stretch', 'contain', 'cover'] as $name) {
            $frame = Process::run([$ffmpeg, '-v', 'error', '-i', $local->path($name.'.mkv'), '-frames:v', '1', '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-']);
            $this->assertTrue($frame->successful(), $frame->errorOutput());
            $pixel = fn ($x, $y) => array_values(unpack('C*', substr($frame->output(), ($y * 80 + $x) * 3, 3)));
            $middle = $pixel(40, 40);
            $this->assertGreaterThan(220, $middle[0], $name.' must retain the red center');
            $this->assertLessThan(25, $middle[2], $name);
            $top = $pixel(40, 5);
            $left = $pixel(5, 40);
            $right = $pixel(75, 40);

            if ($name === 'contain') {
                $this->assertLessThan(10, max($top), 'Contain pads the top in black.');
                $this->assertGreaterThan(220, $left[2], 'Contain keeps the blue left edge.');
                $this->assertGreaterThan(100, $right[1], 'Contain keeps the green right edge.');
            } elseif ($name === 'cover') {
                $this->assertGreaterThan(220, $top[0], 'Cover has no letterbox.');
                $this->assertGreaterThan(220, $left[0], 'Centered cover crops the blue band.');
                $this->assertGreaterThan(220, $right[0], 'Centered cover crops the green band.');
            } else {
                $this->assertGreaterThan(220, $top[0], 'Explicit resize stretches to fill.');
                $this->assertGreaterThan(220, $left[2]);
                $this->assertGreaterThan(100, $right[1]);
            }
        }

        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    #[DataProvider('unsafeOptions')]
    public function unsafe_or_ambiguous_options_fail_before_any_process(array $tokens): void
    {
        Process::fake();
        $this->expectException(InvalidArgumentException::class);

        FFMpeg::disk('memory')->open('source.mp4')->export()->outputOptions($tokens)->save('output.mp4');
    }

    public static function unsafeOptions(): array
    {
        return [
            [['-progress', 'somewhere']], [['-an', 'extra.mp4']], [['-shortest', 'extra.mp4']],
            [['-/preset', '/secret']], [['-headers', 'Authorization: bearer']],
            [['-f', 'tee']], [['-f', 'hls']], [['-x264-params', 'stats=/outside']],
            [['-movflags', '+faststart', 'extra.mp4']], [['-preset', 'fast', '-preset', 'slow']],
            [['-i', 'other.mp4']], [['-map', '0:a']], [['-passlogfile', '/outside']],
            [['-metadata' => 'title=x']],
        ];
    }

    #[Test]
    public function stream_tag_helpers_reject_invalid_output_selectors_and_values_without_io(): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('absent.mkv');

        foreach ([
            fn () => $media->export()->streamMetadata('0:a:0', 'language', 'eng'),
            fn () => $media->export()->streamMetadata('[audio]', 'language', 'eng'),
            fn () => $media->export()->streamMetadata('a:0?', 'language', 'eng'),
            fn () => $media->export()->streamMetadata('a:0', 'bad=key', 'value'),
            fn () => $media->export()->streamMetadata('a:0', 'title', "bad\0value"),
            fn () => $media->export()->disposition(-1, 'default'),
            fn () => $media->export()->disposition('a', 'not_a_flag'),
            fn () => $media->export()->disposition('a', '+default'),
            fn () => $media->export()->disposition('a', ['default', '-forced']),
            fn () => $media->export()->disposition('a', ['default' => true]),
            fn () => $media->export()->output('one.mkv', fn () => null)->streamMetadata('a', 'language', 'eng'),
            fn () => $media->export()->disposition('a', 'default')->output('one.mkv', fn () => null),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Invalid stream metadata or disposition settings were accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        Process::assertNothingRan();
    }

    #[Test]
    public function rate_control_copy_and_clip_conflicts_fail_before_execution(): void
    {
        Process::fake();
        $media = FFMpeg::disk('memory')->open('source.mp4');

        foreach ([
            fn () => $media->export()->video('libx264', bitrate: 800_000, crf: 23)->inspect(),
            fn () => $media->export()->video('libx264', bitrate: '2500k', crf: 23)->inspect(),
            fn () => $media->export()->video('copy')->videoFilter('hflip')->inspect(),
            fn () => $media->export()->audio('copy')->clip(1, duration: 2)->inspect(),
            fn () => $media->export()->clip(1, duration: 2, end: 3)->inspect(),
            fn () => $media->export()->clip(1, end: 0.5)->inspect(),
            fn () => $media->export()->withoutAudio()->map('0:a:0')->inspect(),
            fn () => $media->export()->map('1:a:0?')->inspect(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A conflicting configuration was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $command = $media->export()->video('copy')->audio('copy')->clip(1.5, duration: 2, seek: 'keyframe')->inspect();
        $this->assertLessThan(array_search('-i', $command), array_search('-ss', $command));
        $this->assertContains('-noaccurate_seek', $command);
        Process::assertNothingRan();
    }

    #[Test]
    public function bitrates_accept_positive_integer_bits_per_second_and_preserve_explicit_units(): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('absent.mkv');

        foreach ([1, 2500, 800_000, PHP_INT_MAX, '800k', '1.5M', '2G', '96K', '0.5m', '0.1g'] as $bitrate) {
            $command = $media->export()->video('libx264', bitrate: $bitrate)->audio('aac', bitrate: $bitrate)->inspect();
            $this->assertSame((string) $bitrate, $command[array_search('-b:v', $command, true) + 1]);
            $this->assertSame((string) $bitrate, $command[array_search('-b:a', $command, true) + 1]);
        }

        foreach ([0, -1, 800_000.0, 0.5, INF, NAN, true, false, [], '', '800000', '0k', '0.0M', '-1k', '1e3k', "800k\n"] as $bitrate) {
            foreach (['video', 'audio'] as $type) {
                try {
                    $media->export()->{$type}(bitrate: $bitrate)->inspect();
                    $this->fail('An invalid bitrate was accepted.');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        Process::assertNothingRan();
    }

    #[Test]
    public function real_integer_bitrates_encode_identically_to_explicit_unit_strings(): void
    {
        $probe = getenv('FFMPEG_9_FFPROBE');

        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for real bitrate encoding.');
        }

        $ffmpeg = dirname($probe).'/ffmpeg';
        config(['laravel-ffmpeg.ffmpeg.binaries' => $ffmpeg, 'laravel-ffmpeg.ffprobe.binaries' => $probe]);
        $fixture = $this->root.'/bitrates.mkv';
        $generation = Process::run([
            $ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=s=96x64:r=10:d=1',
            '-f', 'lavfi', '-i', 'sine=frequency=700:sample_rate=48000:duration=1',
            '-c:v', 'ffv1', '-c:a', 'pcm_s16le', $fixture,
        ]);
        $this->assertTrue($generation->successful(), $generation->errorOutput());
        Storage::disk('memory')->put('bitrates.mkv', file_get_contents($fixture));
        $media = FFMpeg::disk('memory')->open('bitrates.mkv');
        $media->export()->video('libx264', bitrate: 800_000)->audio('aac', bitrate: 64_000)->save('integer.mp4');
        $media->export()->video('libx264', bitrate: '800k')->audio('aac', bitrate: '64k')->save('units.mp4');

        $this->assertSame(Storage::disk('memory')->get('units.mp4'), Storage::disk('memory')->get('integer.mp4'));
        $metadata = FFMpeg::disk('memory')->open('integer.mp4')->probe();
        $this->assertSame('h264', $metadata->video()->codec());
        $this->assertSame('aac', $metadata->audio()->codec());
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function clip_timecodes_normalize_all_positions_and_mix_with_numeric_seconds(): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('absent.mkv');

        foreach ([
            ['01:02:59.75', null, '01:03:01.125', '3779.75', '1.375'],
            [0, '00:00:00.125', null, '0', '0.125'],
            ['100:00:00', 2, null, '360000', '2'],
            ['00:00:13.000001', '00:00:01.000001', null, '13.000001', '1.000001'],
            [1.25, null, '00:00:02.5', '1.25', '1.25'],
            ['00:00:00.000001', '00:00:00.000002', null, '0.000001', '0.000002'],
        ] as [$start, $duration, $end, $expectedStart, $expectedDuration]) {
            $command = $media->export()->clip($start, duration: $duration, end: $end)->inspect();
            $this->assertSame($expectedStart, $command[array_search('-ss', $command, true) + 1]);
            $this->assertSame($expectedDuration, $command[array_search('-t', $command, true) + 1]);
        }

        $command = $media->export()->clip('00:00:00.000001', duration: '00:00:00.000002', seek: 'keyframe')->inspect();
        $this->assertSame('0.000001', $command[array_search('-ss', $command, true) + 1]);
        $this->assertLessThan(array_search('-i', $command, true), array_search('-ss', $command, true));
        Process::assertNothingRan();
    }

    #[Test]
    public function clip_rejects_invalid_timecodes_and_ranges_before_execution(): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('absent.mkv');

        foreach (['', '12.5', '1e2', '1s', '1:02:03', '00:60:00', '00:00:60', '-00:00:01',
            '00:00:01.', '00:00:01.1234567', "00:00:01\n", ' 00:00:01', "00:00:01\0"] as $invalid) {
            foreach ([[0, $invalid, null], [$invalid, 1, null], [0, null, $invalid]] as [$start, $duration, $end]) {
                try {
                    $media->export()->clip($start, duration: $duration, end: $end)->inspect();
                    $this->fail('An invalid timecode was accepted.');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        foreach ([[0, '00:00:00', null], ['00:00:01', null, 1], [2, null, '00:00:01'], [INF, 1, null]] as [$start, $duration, $end]) {
            try {
                $media->export()->clip($start, duration: $duration, end: $end)->inspect();
                $this->fail('An invalid clip range was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        Process::assertNothingRan();
    }

    #[Test]
    public function frame_exports_validate_image_bytes_before_publishing_and_clean_up_failures(): void
    {
        $image = file_get_contents(__DIR__.'/src/logo.png');
        $this->fakePipeline(function (PendingProcess $process) use ($image) {
            file_put_contents(end($process->command), $image);

            return Process::result();
        });
        $media = FFMpeg::disk('memory')->open('source.mp4');
        $result = $media->frame(at: '00:00:01.125')->save('nested/frame.png');
        $this->assertSame($image, Storage::disk('memory')->get('nested/frame.png'));
        $this->assertSame('image', $result->file()->role);
        $this->assertSame([], glob($this->root.'/workspaces/*'));

        $this->fakePipeline();
        try {
            $media->frame(at: 1)->save('invalid.png');
            $this->fail('Non-image output must not be published.');
        } catch (MediaException $exception) {
            $this->assertSame(['validation', 'artifact'], [$exception->phase, $exception->category]);
        }
        $this->assertFalse(Storage::disk('memory')->exists('invalid.png'));
        $this->assertSame([], glob($this->root.'/workspaces/*'));

        foreach ([-1, INF, '12.5'] as $invalid) {
            try {
                $media->frame(at: $invalid);
                $this->fail('An invalid frame timestamp was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $media->frame(at: 0)->save('frame.mp4');
            $this->fail('A non-image destination was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        foreach (['accurate', 'keyframe'] as $seek) {
            try {
                $media->frame(at: 2)->clip(10, duration: 3, seek: $seek);
                $this->fail('A clipped single frame was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('frame(at:)', $exception->getMessage());
            }
        }
        foreach ([$media->frame(), $media->frames(count: 2), $media->waveform()] as $images) {
            try {
                $images->addInput($media);
                $this->fail('An unused image input was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function real_frames_sample_after_the_timestamp_and_return_or_publish_images(): void
    {
        $probe = getenv('FFMPEG_9_FFPROBE');

        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for real frame extraction.');
        }

        $ffmpeg = dirname($probe).'/ffmpeg';
        config(['laravel-ffmpeg.ffmpeg.binaries' => $ffmpeg, 'laravel-ffmpeg.ffprobe.binaries' => $probe]);
        $fixture = $this->root.'/sparse.mkv';
        $generation = Process::run([
            $ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'color=c=red:s=96x64:r=1:d=1',
            '-f', 'lavfi', '-i', 'color=c=blue:s=96x64:r=1:d=1',
            '-filter_complex', '[0:v][1:v]concat=n=2:v=1:a=0,setpts=3*N/TB[v]',
            '-map', '[v]', '-fps_mode', 'vfr', '-c:v', 'ffv1', $fixture,
        ]);
        $this->assertTrue($generation->successful(), $generation->errorOutput());
        Storage::disk('memory')->put('sparse.mkv', file_get_contents($fixture));
        $media = FFMpeg::disk('memory')->open('sparse.mkv');

        $phases = [];
        $bytes = $media->frame(at: '00:00:01.100')->fit(32, 32)->toDisk('not-configured')
            ->onProgress(function (Progress $progress) use (&$phases) {
                $phases[] = $progress->phase;
                $this->assertNull($progress->percentage);
            })->get();
        $info = getimagesizefromstring($bytes);
        $this->assertSame([32, 32, IMAGETYPE_JPEG], array_slice($info, 0, 3));
        $image = imagecreatefromstring($bytes);
        $color = imagecolorsforindex($image, imagecolorat($image, 16, 16));
        $this->assertGreaterThan(200, $color['blue']);
        $this->assertLessThan(30, $color['red']);
        $this->assertNotContains(Progress::PUBLICATION, $phases);
        Event::assertDispatched(Events\ExportCompleted::class, fn ($event) => $event->files === []);

        $result = $media->frame(at: 1.1)->resize(width: 48)->save('nested/%03d.png');
        $this->assertSame('image', $result->file()->role);
        $this->assertSame('memory', $result->file()->disk);
        $png = Storage::disk('memory')->get('nested/%03d.png');
        $this->assertSame([48, 32, IMAGETYPE_PNG], array_slice(getimagesizefromstring($png), 0, 3));
        $image = imagecreatefromstring($png);
        $this->assertGreaterThan(200, imagecolorsforindex($image, imagecolorat($image, 24, 16))['blue']);

        $first = $media->frame()->get();
        $this->assertSame([96, 64, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($first), 0, 3));
        $image = imagecreatefromstring($first);
        $this->assertGreaterThan(200, imagecolorsforindex($image, imagecolorat($image, 48, 32))['red']);

        try {
            $media->frame(at: 99)->save('beyond-end.jpg');
            $this->fail('A timestamp beyond the final frame must not publish an empty image.');
        } catch (MediaException $exception) {
            $this->assertSame([], $exception->published);
        }
        $this->assertFalse(Storage::disk('memory')->exists('beyond-end.jpg'));
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function image_sequences_require_valid_sampling_and_numbered_destinations(): void
    {
        Process::fake();
        $media = FFMpeg::disk('memory')->open('source.mp4');

        foreach ([
            fn () => $media->frames(),
            fn () => $media->frames(every: 1, count: 2),
            fn () => $media->frames(every: 0),
            fn () => $media->frames(every: INF),
            fn () => $media->frames(every: 0.0000001),
            fn () => $media->frames(count: 0),
            fn () => $media->frames(count: -1),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Invalid sequence sampling was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach ([null, 'still.png', 'frame-%s.png', 'frame-%d-%d.png', 'frame-%010d.png',
            'frame-%02d/child.png', 'frame-%02d\\child.png', 'frame-%02d.mp4'] as $path) {
            try {
                $media->frames(count: 2)->save($path);
                $this->fail('An invalid sequence destination was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        Process::assertNothingRan();

        $this->fakePipeline(metadata: '{"format":{},"streams":[{"index":0,"codec_type":"video"}]}');
        try {
            $media->frames(count: 2)->save('unknown-%d.png');
            $this->fail('Count sampling requires a known duration.');
        } catch (MediaException $exception) {
            $this->assertSame(['probing', 'metadata'], [$exception->phase, $exception->category]);
        }
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function image_sequences_validate_every_image_and_inventory_before_any_publication(): void
    {
        $image = file_get_contents(__DIR__.'/src/logo.png');

        foreach (['corrupt', 'missing', 'gap'] as $failure) {
            $this->fakePipeline(function (PendingProcess $process) use ($image, $failure) {
                $pattern = end($process->command);
                file_put_contents(sprintf($pattern, 1), $image);

                if ($failure !== 'missing') {
                    file_put_contents(sprintf($pattern, $failure === 'gap' ? 3 : 2), $failure === 'corrupt' ? 'not an image' : $image);
                }

                return Process::result();
            });
            try {
                FFMpeg::disk('memory')->open('source.mp4')->frames(count: 2)->save('invalid-%02d.png');
                $this->fail('An invalid sequence was published.');
            } catch (MediaException $exception) {
                $this->assertSame(['validation', 'artifact', []], [$exception->phase, $exception->category, $exception->published]);
            }
            $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    #[Test]
    public function image_sequences_report_concrete_siblings_after_partial_publication(): void
    {
        $image = file_get_contents(__DIR__.'/src/logo.png');
        $this->fakePipeline(function (PendingProcess $process) use ($image) {
            $pattern = end($process->command);
            file_put_contents(sprintf($pattern, 1), $image);
            file_put_contents(sprintf($pattern, 2), $image);

            return Process::result();
        });
        $storage = Storage::disk('memory');
        $destination = Mockery::mock(Filesystem::class);
        $destination->shouldReceive('put')->andReturnUsing(fn ($path, $stream, $options) =>
            $path === 'partial/02.png' ? false : $storage->put($path, $stream, $options));
        $destination->shouldReceive('size')->andReturnUsing(fn ($path) => $storage->size($path));

        try {
            FFMpeg::disk('memory')->open('source.mp4')->frames(count: 2)
                ->toDisk($destination)->save('partial/%02d.png');
            $this->fail('A failed sequence upload must not complete.');
        } catch (MediaException $exception) {
            $this->assertSame(['publication', 'transfer'], [$exception->phase, $exception->category]);
            $this->assertSame([['disk' => null, 'path' => 'partial/01.png']], $exception->published);
            $this->assertSame(['disk' => null, 'path' => 'partial/02.png'], $exception->destination);
        }
        $this->assertSame($image, $storage->get('partial/01.png'));
        $this->assertFalse($storage->exists('partial/02.png'));
        $this->assertSame([], glob($this->root.'/workspaces/*'));
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    #[Test]
    public function real_image_sequences_resample_intervals_counts_and_short_clips(): void
    {
        $probe = getenv('FFMPEG_9_FFPROBE');

        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for real image sequences.');
        }

        $ffmpeg = dirname($probe).'/ffmpeg';
        config(['laravel-ffmpeg.ffmpeg.binaries' => $ffmpeg, 'laravel-ffmpeg.ffprobe.binaries' => $probe]);
        $fixture = $this->root.'/colors.mkv';
        $generation = Process::run([
            $ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'color=c=red:s=80x48:r=10:d=0.2',
            '-f', 'lavfi', '-i', 'color=c=lime:s=80x48:r=10:d=0.2',
            '-f', 'lavfi', '-i', 'color=c=blue:s=80x48:r=10:d=0.2',
            '-filter_complex', '[0:v][1:v][2:v]concat=n=3:v=1:a=0[v]', '-map', '[v]', '-c:v', 'ffv1', $fixture,
        ]);
        $this->assertTrue($generation->successful(), $generation->errorOutput());
        Storage::disk('memory')->put('colors.mkv', file_get_contents($fixture));
        $media = FFMpeg::disk('memory')->open('colors.mkv');
        $result = $media->frames(every: 0.2)->resize(width: 40)->save('interval/%03d.png');
        $this->assertSame(['interval/001.png', 'interval/002.png', 'interval/003.png'], $result->files()->pluck('path')->all());

        foreach ($result->files() as $index => $file) {
            $bytes = Storage::disk('memory')->get($file->path);
            $this->assertSame([40, 24, IMAGETYPE_PNG], array_slice(getimagesizefromstring($bytes), 0, 3));
            $this->assertSame('image', $file->role);
            $image = imagecreatefromstring($bytes);
            $color = imagecolorsforindex($image, imagecolorat($image, 20, 12));
            $this->assertGreaterThan(200, $color[['red', 'green', 'blue'][$index]]);
        }

        $counted = $media->frames(count: 5)->save('count/%d.jpg');
        $this->assertSame(['count/1.jpg', 'count/2.jpg', 'count/3.jpg', 'count/4.jpg', 'count/5.jpg'], $counted->files()->pluck('path')->all());
        $last = imagecreatefromstring(Storage::disk('memory')->get('count/5.jpg'));
        $this->assertGreaterThan(200, imagecolorsforindex($last, imagecolorat($last, 40, 24))['blue']);

        $short = $media->frames(count: 4)->clip(0.2, duration: 0.1)->save('short/%02d.jpg');
        $this->assertSame(['short/01.jpg', 'short/02.jpg', 'short/03.jpg', 'short/04.jpg'], $short->files()->pluck('path')->all());
        foreach ($short->files() as $file) {
            $bytes = Storage::disk('memory')->get($file->path);
            $this->assertSame([80, 48, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($bytes), 0, 3));
            $image = imagecreatefromstring($bytes);
            $this->assertGreaterThan(200, imagecolorsforindex($image, imagecolorat($image, 40, 24))['green']);
        }
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function two_pass_encoding_requires_explicit_video_bitrate_without_crf(): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('absent.mp4');

        foreach ([
            fn () => $media->export()->twoPass()->inspect(),
            fn () => $media->export()->video('libx264', crf: 23)->twoPass()->inspect(),
            fn () => $media->export()->video(bitrate: '150k')->twoPass()->inspect(),
            fn () => $media->export()->video('copy', bitrate: '150k')->twoPass()->inspect(),
            fn () => $media->export()->video('libx264', bitrate: '150k')->withoutVideo()->twoPass()->inspect(),
            fn () => $media->frame()->twoPass(),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Invalid two-pass encoding was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNotContains('-pass:v', $media->export()->video('libx264', bitrate: '150k')->inspect());
        Process::assertNothingRan();

        $this->fakePipeline(metadata: '{"format":{"duration":"1"},"streams":[{"index":0,"codec_type":"audio"}]}');
        try {
            FFMpeg::disk('memory')->open('source.mp4')->export()->video('libx264', bitrate: '150k')->twoPass()->save('audio.mp4');
            $this->fail('An audio-only selection cannot produce video pass statistics.');
        } catch (MediaException $exception) {
            $this->assertSame(['probing', 'mapping'], [$exception->phase, $exception->category]);
        }
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function two_pass_failures_and_cancellation_never_publish_or_retain_statistics(): void
    {
        foreach (['missing', 'first', 'second', 'cancel-first', 'cancel-second'] as $failure) {
            $this->fakePipeline(function (PendingProcess $process) use ($failure) {
                $pass = (int) $process->command[array_search('-pass:v', $process->command, true) + 1];

                if ($pass === 1 && $failure !== 'missing') {
                    file_put_contents($process->path.'/pass-0-0.log', 'private statistics');
                } elseif ($pass === 2) {
                    file_put_contents(end($process->command), 'encoded media');
                }

                return Process::result(exitCode: ($failure === 'first' && $pass === 1) || ($failure === 'second' && $pass === 2) ? 7 : 0);
            });

            try {
                FFMpeg::disk('memory')->open('source.mp4')->export()->video('libx264', bitrate: '150k')->twoPass()
                    ->onProgress(function (Progress $progress) use ($failure) {
                        if ($progress->isEncoding() && (($failure === 'cancel-first' && $progress->pass === 1)
                            || ($failure === 'cancel-second' && $progress->pass === 2))) {
                            throw new ExportCancelled('cancel pass');
                        }
                    })->save('failed.mp4');
                $this->fail('A failed or cancelled pass must not publish.');
            } catch (ExportCancelled $exception) {
                $this->assertStringStartsWith('cancel-', $failure);
            } catch (MediaException $exception) {
                $this->assertSame(['encoding', $failure === 'missing' ? 'artifact' : 'exit'], [$exception->phase, $exception->category]);
                $this->assertSame([], $exception->published);
            }
            $this->assertFalse(Storage::disk('memory')->exists('failed.mp4'));
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    #[Test]
    public function real_two_pass_exports_keep_independent_output_statistics_and_report_both_passes(): void
    {
        $probe = getenv('FFMPEG_9_FFPROBE');

        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for real two-pass encoding.');
        }

        $ffmpeg = dirname($probe).'/ffmpeg';
        config(['laravel-ffmpeg.ffmpeg.binaries' => $ffmpeg, 'laravel-ffmpeg.ffprobe.binaries' => $probe]);
        $fixture = $this->root.'/passes.mkv';
        $generation = Process::run([
            $ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=s=96x64:r=10:d=1.2',
            '-f', 'lavfi', '-i', 'sine=frequency=700:sample_rate=48000:duration=1.2',
            '-c:v', 'ffv1', '-c:a', 'pcm_s16le', $fixture,
        ]);
        $this->assertTrue($generation->successful(), $generation->errorOutput());
        Storage::disk('memory')->put('passes.mkv', file_get_contents($fixture));
        $media = FFMpeg::disk('memory')->open('passes.mkv');
        $snapshots = [];
        $nested = false;
        $export = $media->export()->twoPass()->filterComplex('[0:v]split[full][branch];[branch]scale=48:32[small]')
            ->output('full.mp4', fn ($out) => $out->map('[full]')->map('0:a:0')
                ->video('libx264', bitrate: '150k', preset: 'medium')->audio('aac', bitrate: '64k')
                ->clip(0.1, duration: 0.5)->outputOptions(['-movflags', '+faststart'])->toDisk('memory'))
            ->output('small.mkv', fn ($out) => $out->map('[small]')->video('libx264', bitrate: '80k')
                ->clip(0.2, duration: 0.3)->toDisk('memory'))
            ->onProgress(function (Progress $progress) use (&$snapshots, &$nested, $media) {
                $snapshots[] = $progress;
                $this->assertFalse(Storage::disk('memory')->exists('full.mp4'));
                $this->assertFalse(Storage::disk('memory')->exists('small.mkv'));

                if (! $nested && $progress->isEncoding() && $progress->pass === 1 && $progress->elapsedSeconds !== null) {
                    $nested = true;
                    $media->export()->video('libx264', bitrate: '60k')->withoutAudio()->resize(32, 32)
                        ->clip(0, duration: 0.2)->twoPass()->save('nested.mp4');
                }
            });
        $inspection = $export->inspect();
        $this->assertSame($inspection, $export->inspect());
        $this->assertSame([], glob($this->root.'/workspaces/*'));
        $result = $export->save();

        $this->assertCount(2, $result->processes());
        $this->assertSame([0, 0], $result->processes()->pluck('exit_code')->all());
        $encoding = array_values(array_filter($snapshots, fn ($progress) => $progress->isEncoding()));
        $this->assertSame([1, 2], array_values(array_unique(array_column($encoding, 'pass'))));
        $this->assertSame([2], array_values(array_unique(array_column($encoding, 'totalPasses'))));
        $updates = array_values(array_filter($encoding, fn ($progress) => $progress->elapsedSeconds !== null));
        $this->assertSame([1, 2], array_values(array_unique(array_column($updates, 'pass'))));
        $this->assertCount(2, $result->files());
        $full = FFMpeg::disk('memory')->open('full.mp4')->probe();
        $small = FFMpeg::disk('memory')->open('small.mkv')->probe();
        $inner = FFMpeg::disk('memory')->open('nested.mp4')->probe();
        $this->assertSame([32, 32, false], [$inner->video()->width(), $inner->video()->height(), $inner->hasAudio()]);
        $this->assertSame([96, 64, 'h264', 'aac'], [$full->video()->width(), $full->video()->height(), $full->video()->codec(), $full->audio()->codec()]);
        $this->assertSame([48, 32, 'h264', false], [$small->video()->width(), $small->video()->height(), $small->video()->codec(), $small->hasAudio()]);
        $this->assertEqualsWithDelta(0.5, $full->duration(), 0.05);
        $this->assertEqualsWithDelta(0.3, $small->duration(), 0.05);
        $this->assertSame($inspection, $export->inspect());
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function waveform_arguments_and_invalid_images_fail_without_publication(): void
    {
        Process::fake();
        $media = FFMpeg::disk('memory')->open('source.mp4');

        foreach ([
            fn () => $media->waveform(width: 0),
            fn () => $media->waveform(height: -1),
            fn () => $media->waveform(colors: []),
            fn () => $media->waveform(colors: ['red|blue']),
            fn () => $media->waveform(colors: ['red:draw=full']),
            fn () => $media->waveform(colors: ['#fff']),
            fn () => $media->waveform(colors: [123]),
            fn () => $media->waveform(colors: ['color' => 'red']),
            fn () => $media->waveform(colors: ''),
            fn () => $media->waveform(colors: 'red|blue'),
            fn () => $media->waveform(colors: 'red:draw=full'),
            fn () => $media->waveform(colors: '#fff'),
            fn () => $media->waveform(colors: "blue\n"),
            fn () => $media->waveform()->clip(0, duration: 1, seek: 'keyframe')->inspect(),
            fn () => $media->waveform()->videoStream(0, fn ($v) => $v->color('bt709', 'bt709', 'bt709'))->inspect(),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('An invalid waveform request was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        Process::assertNothingRan();

        $this->fakePipeline();
        try {
            $media->waveform()->save('invalid-wave.png');
            $this->fail('Invalid waveform image bytes must not publish.');
        } catch (MediaException $exception) {
            $this->assertSame(['validation', 'artifact'], [$exception->phase, $exception->category]);
        }
        $this->assertFalse(Storage::disk('memory')->exists('invalid-wave.png'));
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function real_waveforms_render_audio_levels_filters_clips_colors_and_geometry(): void
    {
        $probe = getenv('FFMPEG_9_FFPROBE');

        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for real waveform images.');
        }

        $ffmpeg = dirname($probe).'/ffmpeg';
        config(['laravel-ffmpeg.ffmpeg.binaries' => $ffmpeg, 'laravel-ffmpeg.ffprobe.binaries' => $probe]);
        $fixture = $this->root.'/levels.wav';
        $generation = Process::run([
            $ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'aevalsrc=if(lt(t\,0.3)\,0\,0.8):s=8000:d=1.2',
            '-c:a', 'pcm_s16le', $fixture,
        ]);
        $this->assertTrue($generation->successful(), $generation->errorOutput());
        Storage::disk('memory')->put('levels.wav', file_get_contents($fixture));
        $media = FFMpeg::disk('memory')->open('levels.wav');
        $operation = $media->waveform(width: 80, height: 41);
        $inspection = $operation->inspect();
        $this->assertSame($inspection, $operation->inspect());
        $this->assertSame([], glob($this->root.'/workspaces/*'));
        $result = $operation->onProgress(fn (Progress $progress) => $this->assertNull($progress->percentage))->save('wave.png');
        $this->assertSame('image', $result->file()->role);
        $bytes = Storage::disk('memory')->get('wave.png');
        $this->assertSame([80, 41, IMAGETYPE_PNG], array_slice(getimagesizefromstring($bytes), 0, 3));
        $image = imagecreatefromstring($bytes);
        $this->assertSame(0, imagecolorsforindex($image, imagecolorat($image, 5, 10))['blue']);
        $this->assertGreaterThan(200, imagecolorsforindex($image, imagecolorat($image, 60, 10))['blue']);

        $media->waveform(width: 80, height: 41)->audioFilter('volume=0')->save('muted.png');
        $muted = imagecreatefromstring(Storage::disk('memory')->get('muted.png'));
        $this->assertSame(0, imagecolorsforindex($muted, imagecolorat($muted, 60, 10))['blue']);

        foreach (['red', '#ff0000', '0xff0000ff'] as $index => $color) {
            $scalar = $media->waveform(width: 80, height: 41, colors: $color);
            $this->assertSame($media->waveform(width: 80, height: 41, colors: [$color])->inspect(), $scalar->inspect());
            $scalar->save('scalar-'.$index.'.png');
            $colored = imagecreatefromstring(Storage::disk('memory')->get('scalar-'.$index.'.png'));
            $pixel = imagecolorsforindex($colored, imagecolorat($colored, 60, 10));
            $this->assertGreaterThan(200, $pixel['red']);
            $this->assertSame(0, $pixel['blue']);
        }

        $media->waveform(width: 80, height: 41, colors: ['#ff0000'])->clip(0.6, duration: 0.3)
            ->resize(width: 40)->save('clipped.jpg');
        $bytes = Storage::disk('memory')->get('clipped.jpg');
        $this->assertSame([40, 20, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($bytes), 0, 3));
        $clipped = imagecreatefromstring($bytes);
        $this->assertGreaterThan(200, imagecolorsforindex($clipped, imagecolorat($clipped, 2, 5))['red']);
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function generated_files_read_streams_and_delegate_temporary_urls_to_the_retained_filesystem(): void
    {
        $this->fakePipeline();
        $filesystem = Storage::disk('memory');
        $file = FFMpeg::disk($filesystem)->open('source.mp4')->export()->save('nested/access.mp4')->file();
        $this->assertNull($file->disk);

        $stream = $file->readStream();
        try {
            $this->assertSame('encoded media', stream_get_contents($stream));
        } finally {
            fclose($stream);
        }

        $expiration = now()->addMinutes(5);
        try {
            $file->temporaryUrl($expiration);
            $this->fail('Unsupported temporary URLs must not fall back to public URLs.');
        } catch (RuntimeException $exception) {
            $this->assertSame('This driver does not support creating temporary URLs.', $exception->getMessage());
        }

        $arguments = null;
        $filesystem->buildTemporaryUrlsUsing(function ($path, $expires, $options) use (&$arguments) {
            $arguments = [$path, $expires, $options];

            return 'https://example.test/signed-preview';
        });
        $this->assertSame('https://example.test/signed-preview', $file->temporaryUrl($expiration, ['download' => 'preview.mp4']));
        $this->assertSame(['nested/access.mp4', $expiration, ['download' => 'preview.mp4']], $arguments);

        $filesystem->delete($file->path);
        $this->assertNull($file->readStream());
    }

    #[Test]
    public function a_single_export_publishes_only_after_validation_and_cleans_before_completion(): void
    {
        $this->fakePipeline();
        $snapshots = [];
        $completed = null;
        Event::fake([Events\ExportStarting::class, Events\ExportFailed::class, Events\ExportCancelled::class]);
        Event::listen(Events\ExportCompleted::class, function ($event) use (&$completed) {
            $this->assertSame([], glob($this->root.'/workspaces/*'));
            $this->assertSame('encoded media', Storage::disk('memory')->get('nested/encoded.mp4'));
            $completed = $event;
        });
        $result = FFMpeg::disk('memory')->open('source.mp4')->export()
            ->video('libx264', crf: 23)->audio('copy')
            ->onProgress(function (Progress $progress) use (&$snapshots) {
                $snapshots[] = $progress;
                $this->assertFalse(Storage::disk('memory')->exists('nested/encoded.mp4'));

                return false;
            })->save('nested/encoded.mp4');

        $this->assertSame('encoded media', Storage::disk('memory')->get('nested/encoded.mp4'));
        $this->assertSame('original media', Storage::disk('memory')->get('source.mp4'));
        $this->assertSame('memory', $result->file()->disk);
        $this->assertSame('nested/encoded.mp4', $result->file()->path);
        $this->assertSame(13, $result->file()->size);
        $this->assertCount(1, $result->files());
        $this->assertCount(1, $result->processes());
        $this->assertSame(0, $result->processes()->first()['exit_code']);
        $this->assertContains('[input:0]', $result->processes()->first()['command']);
        $this->assertContains('[output:0]', $result->processes()->first()['command']);
        $this->assertSame(['staging', 'probing', 'encoding', 'encoding', 'validation', 'publication'], array_column($snapshots, 'phase'));
        $this->assertSame([
            Progress::STAGING, Progress::PROBING, Progress::ENCODING, Progress::ENCODING,
            Progress::VALIDATION, Progress::PUBLICATION,
        ], array_column($snapshots, 'phase'));
        $this->assertSame([false, false, true, true, false, false], array_map(fn (Progress $progress) => $progress->isEncoding(), $snapshots));
        $encoding = $snapshots[3];
        $this->assertSame(4.0, $encoding->elapsedSeconds);
        $this->assertSame(40.0, $encoding->percentage);
        $this->assertSame(2.0, $encoding->speed);
        $this->assertSame(3.0, $encoding->remainingSeconds);
        Event::assertDispatched(Events\ExportStarting::class);
        $this->assertInstanceOf(Events\ExportCompleted::class, $completed);
        $this->assertSame('nested/encoded.mp4', $completed->files[0]['path']);
        Event::assertNotDispatched(Events\ExportFailed::class);
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function global_progress_events_work_without_a_per_export_callback(): void
    {
        $this->fakePipeline();
        Event::fake();

        FFMpeg::disk('memory')->open('source.mp4')->export()->save('events.mp4');

        $starting = Event::dispatched(Events\ExportStarting::class)->sole()[0];
        $updates = Event::dispatched(Events\ExportProgress::class)->map(fn ($arguments) => $arguments[0]);
        $this->assertSame(['staging', 'probing', 'encoding', 'encoding', 'validation', 'publication'],
            $updates->map(fn ($event) => $event->progress->phase)->all());
        $this->assertSame([$starting->operationId], $updates->pluck('operationId')->unique()->values()->all());
        $this->assertSame([['disk' => 'memory', 'path' => 'events.mp4']], $updates->first()->outputs);
        $encoded = $updates->first(fn ($event) => $event->progress->elapsedSeconds !== null)->progress;
        $this->assertSame(40.0, $encoded->percentage);
        $this->assertSame(2.0, $encoded->speed);
        $this->assertSame(3.0, $encoded->remainingSeconds);
        Event::assertDispatched(Events\ExportCompleted::class,
            fn ($event) => $event->operationId === $starting->operationId);
        $this->assertSame('encoded media', Storage::disk('memory')->get('events.mp4'));
    }

    #[Test]
    public function disabling_events_preserves_callbacks_publication_and_callback_failures(): void
    {
        $this->fakePipeline();
        config(['laravel-ffmpeg.enable_events' => false]);
        Event::fake();
        $phases = [];
        FFMpeg::disk('memory')->open('source.mp4')->export()->onProgress(function ($progress) use (&$phases) {
            $phases[] = $progress->phase;
        })->save('disabled.mp4');

        $this->assertContains('encoding', $phases);
        $this->assertSame('encoded media', Storage::disk('memory')->get('disabled.mp4'));
        $failure = new RuntimeException('Application callback failure');
        try {
            FFMpeg::disk('memory')->open('source.mp4')->export()->onProgress(function () use ($failure) {
                throw $failure;
            })->save('failed.mp4');
            $this->fail('Callback failure must still propagate with events disabled.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertFalse(Storage::disk('memory')->exists('failed.mp4'));
        Event::assertNothingDispatched();
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function global_progress_listeners_can_cancel_before_publication(): void
    {
        $this->fakePipeline();
        $cancelled = new ExportCancelled('Global listener cancellation');
        Event::listen(Events\ExportProgress::class, function ($event) use ($cancelled) {
            if ($event->progress->elapsedSeconds !== null) {
                throw $cancelled;
            }
        });

        try {
            FFMpeg::disk('memory')->open('source.mp4')->export()->save('cancelled.mp4');
            $this->fail('A global listener must be able to cancel an export.');
        } catch (ExportCancelled $exception) {
            $this->assertSame($cancelled, $exception);
        }
        Event::assertDispatched(Events\ExportCancelled::class);
        Event::assertNotDispatched(Events\ExportCompleted::class);
        $this->assertFalse(Storage::disk('memory')->exists('cancelled.mp4'));
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function progress_chunks_are_parsed_without_inventing_unknown_percentages(): void
    {
        $this->fakePipeline(function (PendingProcess $process) {
            file_put_contents(end($process->command), 'encoded media');
            $fake = Process::describe();
            $fake->output = [
                ['type' => 'out', 'buffer' => 'out_ti'],
                ['type' => 'err', 'buffer' => "diagnostic, not progress\n"],
                ['type' => 'out', 'buffer' => "me_us=1250000\r\nspeed=N/A\r\npro"],
                ['type' => 'out', 'buffer' => "gress=end\r\n"],
            ];

            return $fake;
        }, metadata: '{"format":{},"streams":[{"index":0,"codec_type":"video"}]}');
        $snapshots = [];

        FFMpeg::disk('memory')->open('source.mp4')->export()->onProgress(function (Progress $snapshot) use (&$snapshots) {
            $snapshots[] = $snapshot;
        })->save('encoded.mp4');

        $encoding = array_values(array_filter($snapshots, fn (Progress $snapshot) => $snapshot->elapsedSeconds !== null))[0];
        $this->assertSame(1.25, $encoding->elapsedSeconds);
        $this->assertNull($encoding->percentage);
        $this->assertNull($encoding->speed);
        $this->assertNull($encoding->remainingSeconds);
    }

    #[Test]
    public function missing_or_empty_artifacts_never_reach_publication(): void
    {
        foreach ([null, ''] as $contents) {
            $this->fakePipeline(function (PendingProcess $process) use ($contents) {
                if ($contents !== null) {
                    file_put_contents(end($process->command), $contents);
                }

                return Process::result(output: "out_time_us=10000000\nprogress=end\n");
            });

            try {
                FFMpeg::disk('memory')->open('source.mp4')->export()->save('missing.mp4');
                $this->fail('Exit zero without an artifact must not complete.');
            } catch (MediaException $exception) {
                $this->assertSame('validation', $exception->phase);
                $this->assertSame('artifact', $exception->category);
                $this->assertFalse(Storage::disk('memory')->exists('missing.mp4'));
                $this->assertSame([], glob($this->root.'/workspaces/*'));
                Event::assertNotDispatched(Events\ExportCompleted::class);
            }
        }
    }

    #[Test]
    public function encoding_failures_do_not_publish_and_report_the_encoding_phase(): void
    {
        $this->fakePipeline(fn () => Process::result(errorOutput: 'encoder rejected the input', exitCode: 7));

        try {
            FFMpeg::disk('memory')->open('source.mp4')->export()->save('failed.mp4');
            $this->fail('A nonzero encode must not complete.');
        } catch (MediaException $exception) {
            $this->assertSame('encoding', $exception->phase);
            $this->assertStringContainsString('encoder rejected', $exception->diagnostics);
            $this->assertFalse(Storage::disk('memory')->exists('failed.mp4'));
            $this->assertSame([], glob($this->root.'/workspaces/*'));
            Event::assertDispatched(Events\ExportFailed::class, fn ($event) => $event->phase === 'encoding');
            Event::assertNotDispatched(Events\ExportCompleted::class);
        }
    }

    #[Test]
    public function destination_options_merge_and_the_last_explicit_visibility_wins(): void
    {
        $this->fakePipeline();
        $media = FFMpeg::disk('memory')->open('source.mp4');
        $exports = [
            $media->export()->visibility('private')->toDisk('memory', ['ContentType' => 'video/mp4']),
            $media->export()->toDisk('memory', ['visibility' => 'public'])->visibility('private'),
            $media->export()->visibility('public')->toDisk('memory', ['visibility' => 'private']),
            $media->export()->toDisk('memory', ['visibility' => 'private'])->toDisk('memory'),
        ];

        foreach ($exports as $index => $export) {
            $path = "options-{$index}.mp4";
            $export->save($path);

            $this->assertSame('encoded media', Storage::disk('memory')->get($path));
            $this->assertSame('private', Storage::disk('memory')->getVisibility($path));
        }

        $media->export()->save('independent.mp4');
        $this->assertSame('public', Storage::disk('memory')->getVisibility('independent.mp4'));
    }

    #[Test]
    public function false_storage_writes_fail_with_destination_context(): void
    {
        $this->fakePipeline();
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->with('failed.mp4', Mockery::type('resource'), ['visibility' => 'private', 'ContentType' => 'video/mp4'])->andReturn(false);

        try {
            FFMpeg::disk('memory')->open('source.mp4')->export()
                ->toDisk($disk, ['visibility' => 'private', 'ContentType' => 'video/mp4'])->save('failed.mp4');
            $this->fail('A false Storage write must not complete.');
        } catch (MediaException $exception) {
            $this->assertSame('publication', $exception->phase);
            $this->assertSame(['disk' => null, 'path' => 'failed.mp4'], $exception->destination);
            $this->assertSame([], $exception->published);
            $this->assertNull($exception->getPrevious());
            $this->assertSame([], glob($this->root.'/workspaces/*'));
            Event::assertNotDispatched(Events\ExportCompleted::class);
        }
    }

    #[Test]
    public function incomplete_publication_identifies_the_object_already_written(): void
    {
        $this->fakePipeline();
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturn(true);
        $disk->shouldReceive('size')->with('partial.mp4')->andReturn(4);

        try {
            FFMpeg::disk('memory')->open('source.mp4')->export()->toDisk($disk)->save('partial.mp4');
            $this->fail('An incomplete upload must not complete.');
        } catch (MediaException $exception) {
            $this->assertSame('publication', $exception->phase);
            $this->assertSame([['disk' => null, 'path' => 'partial.mp4']], $exception->published);
            $this->assertSame([], glob($this->root.'/workspaces/*'));
            Event::assertDispatched(Events\ExportFailed::class, fn ($event) => $event->published === $exception->published);
            Event::assertNotDispatched(Events\ExportCompleted::class);
        }
    }

    #[Test]
    #[DataProvider('callbackFailures')]
    public function callback_exceptions_stop_the_real_child_and_preserve_the_original_failure(string $class): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('Child reaping verification requires POSIX process inspection.');
        }

        $ffprobe = $this->root.'/ffprobe';
        $ffmpeg = $this->root.'/ffmpeg';
        $pidFile = $this->root.'/pid';
        file_put_contents($ffprobe, '#!'.PHP_BINARY."\n<?php\n".'if (in_array("-version", $argv, true)) { echo "ffprobe version 9.0.2"; } else { echo '.var_export($this->metadata(), true).'; }');
        file_put_contents($ffmpeg, '#!'.PHP_BINARY."\n<?php\n".'if (in_array("-version", $argv, true)) { echo "ffmpeg version 9.0.2"; exit; } file_put_contents('.var_export($pidFile, true).', getmypid()); echo "out_time_us=1000000\nspeed=1x\nprogress=continue\n"; flush(); sleep(30);');
        chmod($ffprobe, 0700);
        chmod($ffmpeg, 0700);
        config(['laravel-ffmpeg.ffmpeg.binaries' => $ffmpeg, 'laravel-ffmpeg.ffprobe.binaries' => $ffprobe]);
        $original = new $class('Stop this export');

        try {
            FFMpeg::disk('memory')->open('source.mp4')->export()->onProgress(function (Progress $progress) use ($original) {
                if ($progress->elapsedSeconds !== null) {
                    throw $original;
                }
            })->save('cancelled.mp4');
            $this->fail('The callback exception must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($original, $exception);
            $this->assertFalse(posix_kill((int) file_get_contents($pidFile), 0));
            $this->assertFalse(Storage::disk('memory')->exists('cancelled.mp4'));
            $this->assertSame([], glob($this->root.'/workspaces/*'));
            Event::assertDispatched($class === ExportCancelled::class ? Events\ExportCancelled::class : Events\ExportFailed::class);
            Event::assertNotDispatched(Events\ExportCompleted::class);
        }
    }

    public static function callbackFailures(): array
    {
        return [[ExportCancelled::class], [RuntimeException::class]];
    }

    #[Test]
    public function real_exports_preserve_copy_metadata_and_apply_filtered_accurate_clips(): void
    {
        $probe = getenv('FFMPEG_9_FFPROBE');

        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE to run real FFmpeg 9.0.x export acceptance.');
        }

        $ffmpeg = dirname($probe).'/ffmpeg';
        config(['laravel-ffmpeg.ffmpeg.binaries' => $ffmpeg, 'laravel-ffmpeg.ffprobe.binaries' => $probe]);
        file_put_contents($this->root.'/chapters.txt', ";FFMETADATA1\ntitle=Original lecture\n[CHAPTER]\nTIMEBASE=1/1000\nSTART=0\nEND=1200\ntitle=First\n[CHAPTER]\nTIMEBASE=1/1000\nSTART=1200\nEND=2400\ntitle=Second\n");
        $fixture = $this->root.'/fixture.mkv';
        $generation = Process::run([
            $ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=160x90:rate=10:duration=2.4',
            '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=48000:duration=2.4',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=2.4',
            '-f', 'ffmetadata', '-i', $this->root.'/chapters.txt', '-map', '0:v:0', '-map', '1:a:0', '-map', '2:a:0',
            '-map_metadata', '3', '-map_chapters', '3', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '18',
            '-g', '10', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-metadata:s:a:0', 'language=fra',
            '-metadata:s:a:1', 'language=eng', $fixture,
        ]);
        $this->assertTrue($generation->successful(), $generation->errorOutput());
        $original = file_get_contents($fixture);
        Storage::disk('memory')->put('fixture.mkv', $original);
        $local = $this->fakeLocalDisk();
        $media = FFMpeg::disk('memory')->open('fixture.mkv');

        $copy = $media->export()->video('copy')->audio('copy')->save('copy.mkv');
        $copyMetadata = FFMpeg::disk('memory')->open($copy->file()->path)->probe();
        $this->assertSame('h264', $copyMetadata->video()->get('codec_name'));
        $this->assertSame('aac', $copyMetadata->audio()->get('codec_name'));
        $this->assertSame('Original lecture', $copyMetadata->get('format.tags.title'));
        $this->assertCount(2, $copyMetadata->get('chapters'));
        $this->assertCount(1, $copyMetadata->audioStreams());

        $omitted = $media->export()->video('copy')->audio('copy')->withoutMetadata(chapters: true)->save('omitted.mkv');
        $omittedMetadata = FFMpeg::disk('memory')->open($omitted->file()->path)->probe();
        $this->assertNull($omittedMetadata->tag('title'));
        $this->assertCount(0, $omittedMetadata->chapters());
        $this->assertSame('h264', $omittedMetadata->video()->codec());
        $this->assertSame('aac', $omittedMetadata->audio()->codec());

        $selection = $media->export()->video('copy')->audio('copy')
            ->map('0:a:m:language:eng')->map('0:V:0')->map('0:a:m:language:fra')->map('-0:a:m:language:fra')
            ->outputOptions(['-disposition:a:0', 'default'])->save('english.mkv');
        $selected = FFMpeg::disk('memory')->open($selection->file()->path)->probe();
        $this->assertSame(['audio', 'video'], $selected->streams()->map(fn ($stream) => $stream->get('codec_type'))->all());
        $this->assertSame('eng', $selected->audio()->language());
        $this->assertTrue($selected->audio()->isDefault());
        $this->assertSame(48000, $selected->audio()->sampleRate());
        $this->assertSame(1, $selected->audio()->channels());

        $probed = $media->probe();
        $english = $probed->audioStreams()->first(fn ($stream) => $stream->language() === 'eng');
        $objectSelection = $media->export()->mapAudio($english)->mapVideo($probed->videoStreams()->first())
            ->audio('copy')->video('copy')->save('probed-selection.mkv');
        $objectMetadata = FFMpeg::disk('memory')->open($objectSelection->file()->path)->probe();
        $this->assertSame(['audio', 'video'], $objectMetadata->streams()->map(fn ($stream) => $stream->get('codec_type'))->all());
        $this->assertSame('eng', $objectMetadata->audio()->get('tags.language'));

        $numericSelection = $media->export()->mapVideo(0)->mapAudio(0, language: 'fra', optional: true)
            ->video('copy')->audio('copy')->save('numeric-selection.mkv');
        $numericMetadata = FFMpeg::disk('memory')->open($numericSelection->file()->path)->probe();
        $this->assertSame(['video', 'audio'], $numericMetadata->streams()->map(fn ($stream) => $stream->get('codec_type'))->all());
        $this->assertSame('fra', $numericMetadata->audio()->get('tags.language'));

        $absentSelection = $media->export()->map('0:v:0:?')->mapAudio(0, language: 'deu', optional: true)
            ->video('copy')->audio('copy')->save('absent-selection.mkv');
        $absentMetadata = FFMpeg::disk('memory')->open($absentSelection->file()->path)->probe();
        $this->assertSame(['video'], $absentMetadata->streams()->map(fn ($stream) => $stream->get('codec_type'))->all());

        $media->export()->video('copy')->audio('copy')->clip('00:00:01.300', duration: '00:00:00.600', seek: 'keyframe')->toDisk('local')->save('keyframe.mkv');
        $keyframe = Process::run([$ffmpeg, '-v', 'error', '-i', $local->path('keyframe.mkv'), '-map', '0:v:0', '-frames:v', '1', '-f', 'md5', '-']);
        $sourceFrame = Process::run([$ffmpeg, '-v', 'error', '-i', $fixture, '-map', '0:v:0', '-vf', 'select=eq(n\\,10)', '-frames:v', '1', '-f', 'md5', '-']);
        $this->assertTrue($keyframe->successful(), $keyframe->errorOutput());
        $this->assertTrue($sourceFrame->successful(), $sourceFrame->errorOutput());
        $this->assertSame($sourceFrame->output(), $keyframe->output());

        $clip = $media->export()->video('libx264', crf: 25, preset: 'ultrafast')->audio('aac', bitrate: '64k')
            ->videoFilter('crop=120:80:20:4')->videoFilter('scale=60:40')->clip('00:00:00.400', end: '00:00:01.600')
            ->metadata('title', 'Trimmed lecture')->toDisk('local', ['visibility' => 'private'])->save('clips/trimmed.mp4');
        $metadata = FFMpeg::disk('local')->open($clip->file()->path)->probe();
        $this->assertEqualsWithDelta(1.2, $metadata->duration(), 0.12);
        $this->assertSame(60, $metadata->video()->width());
        $this->assertSame(40, $metadata->video()->height());
        $this->assertSame('Trimmed lecture', $metadata->get('format.tags.title'));
        $this->assertSame([], $metadata->get('chapters'));
        $this->assertSame('fra', $metadata->audio()->get('tags.language'));
        $this->assertSame('private', $local->getVisibility('clips/trimmed.mp4'));
        $this->assertTrue(Process::run([$ffmpeg, '-v', 'error', '-xerror', '-i', $local->path('clips/trimmed.mp4'), '-f', 'null', '-'])->successful());
        $this->assertSame($original, Storage::disk('memory')->get('fixture.mkv'));

        try {
            $media->export()->map('0:s:0?')->save('unexpected.mp4');
            $this->fail('An absent optional subtitle must not auto-select video/audio.');
        } catch (MediaException $exception) {
            $this->assertSame('mapping', $exception->category);
            $this->assertFalse(Storage::disk('memory')->exists('unexpected.mp4'));
        }

        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }
}

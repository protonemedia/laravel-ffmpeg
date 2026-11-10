<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\Filesystem as LocalFilesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Events;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\PendingExport;
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use RuntimeException;

class ExportTest extends TestCase
{
    /**
     * The directory holding the media fixtures shared by every test in the class.
     */
    protected static ?string $fixtures = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::disk('memory')->put('source.mp4', 'original media');
        Event::fake([Events\ExportStarting::class, Events\ExportCompleted::class, Events\ExportFailed::class, Events\ExportCancelled::class]);
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$fixtures !== null) {
            (new LocalFilesystem)->deleteDirectory(static::$fixtures);

            static::$fixtures = null;
        }

        parent::tearDownAfterClass();
    }

    protected function metadata(): string
    {
        return '{"format":{"duration":"10.0"},"streams":[{"index":0,"codec_type":"video"},{"index":1,"codec_type":"audio"}],"chapters":[]}';
    }

    protected function fakePipeline(?callable $encode = null, ?string $metadata = null): void
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

    /**
     * Get the path of the given fixture in the directory shared by the class.
     */
    protected function fixturePath(string $name): string
    {
        static::$fixtures ??= sys_get_temp_dir().'/laravel-ffmpeg-fixtures-'.bin2hex(random_bytes(8));

        if (! is_dir(static::$fixtures)) {
            mkdir(static::$fixtures, 0700);
        }

        return static::$fixtures.'/'.$name;
    }

    /**
     * Generate the given fixture with real FFmpeg once per class and get its path.
     */
    protected function fixture(string $name, array $arguments): string
    {
        $ffmpeg = $this->useRealFFMpeg();

        // Several tests check different things about one generated file, so we only
        // encode each fixture the first time a test asks for it. The directory is
        // removed once every test in the class has finished.
        if (! is_file($path = $this->fixturePath($name))) {
            $this->assertProcessSucceeded(Process::run([$ffmpeg, '-v', 'error', ...$arguments, $path]));
        }

        return $path;
    }

    public function test_inspection_is_pure_scoped_and_does_not_share_output_settings(): void
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
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_geometry_helpers_share_filter_order_output_scopes_and_copy_restrictions(): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('absent.mkv');
        $export = $media->export()->videoFilter('hflip')->resize(width: 80)->videoFilter('vflip');
        $this->assertContains('hflip,scale=w=80:h=-2:reset_sar=1,vflip', $export->inspect());
        $this->assertNotContains('hflip,scale=w=80:h=-2:reset_sar=1,vflip', $media->export()->inspect());
        $command = $media->export()
            ->output('wide.mkv', fn ($out) => $out->resize(height: 40))
            ->output('square.mkv', fn ($out) => $out->contain(80, 80))->inspect();
        $boundary = array_search('[output:0]', $command);
        $this->assertContains('scale=w=-2:h=40:reset_sar=1', array_slice($command, 0, $boundary));
        $this->assertContains('scale=w=80:h=80:force_original_aspect_ratio=decrease:force_divisible_by=2:reset_sar=1,pad=w=80:h=80:x=(ow-iw)/2:y=(oh-ih)/2:color=black', array_slice($command, $boundary));
        $this->assertContains('scale=w=80:h=80:force_original_aspect_ratio=decrease:force_divisible_by=2:reset_sar=1,pad=w=80:h=80:x=(ow-iw)/2:y=(oh-ih)/2:color=#ffffff',
            $media->export()->contain(80, 80, background: '#ffffff')->inspect());
        Process::assertNothingRan();
    }

    #[DataProvider('invalidGeometryProvider')]
    public function test_invalid_geometry_is_rejected_before_any_process(Closure $configure): void
    {
        Process::fake();

        $this->assertThrows(
            fn () => $configure(FFMpeg::disk('missing')->open('absent.mkv')->export()),
            InvalidArgumentException::class,
        );

        Process::assertNothingRan();
    }

    public static function invalidGeometryProvider(): array
    {
        return [
            'resize without dimensions' => [fn (PendingExport $export) => $export->resize()],
            'zero width' => [fn (PendingExport $export) => $export->resize(width: 0)],
            'negative height' => [fn (PendingExport $export) => $export->resize(height: -2)],
            'zero contain width' => [fn (PendingExport $export) => $export->contain(0, 80)],
            'odd cover width' => [fn (PendingExport $export) => $export->cover(81, 80)],
            'filter syntax in the background' => [fn (PendingExport $export) => $export->contain(80, 80, background: 'red:movie=/secret')],
            'resize with copied video' => [fn (PendingExport $export) => $export->resize(width: 80)->video('copy')->inspect()],
            'cover with copied video' => [fn (PendingExport $export) => $export->cover(80, 80)->video('copy')->inspect()],
            'resize after outputs' => [fn (PendingExport $export) => $export->output('one.mkv', fn () => null)->resize(width: 80)],
        ];
    }

    public function test_real_geometry_helpers_preserve_display_aspect_or_explicitly_stretch_and_center(): void
    {
        $ffmpeg = $this->useRealFFMpeg();
        $local = $this->fakeLocalDisk();
        $generation = Process::run([$ffmpeg, '-v', 'error', '-f', 'lavfi', '-i',
            'color=red:size=160x80:rate=10:duration=0.3,drawbox=x=0:y=0:w=40:h=80:color=blue:t=fill,drawbox=x=120:y=0:w=40:h=80:color=green:t=fill,setsar=2',
            '-c:v', 'ffv1', $local->path('anamorphic.mkv')]);
        $this->assertProcessSucceeded($generation);
        $media = FFMpeg::disk('local')->open('anamorphic.mkv');
        $media->export()
            ->output('width.mkv', fn ($out) => $out->map('0:v')->resize(width: 80)->video('ffv1')->toDisk('local'))
            ->output('height.mkv', fn ($out) => $out->map('0:v')->resize(height: 40)->video('ffv1')->toDisk('local'))
            ->output('stretch.mkv', fn ($out) => $out->map('0:v')->resize(80, 80)->video('ffv1')->toDisk('local'))
            ->output('contain.mkv', fn ($out) => $out->map('0:v')->contain(80, 80)->video('ffv1')->toDisk('local'))
            ->output('cover.mkv', fn ($out) => $out->map('0:v')->cover(80, 80)->video('ffv1')->toDisk('local'))
            ->save();

        foreach (['width' => [80, 20], 'height' => [160, 40], 'stretch' => [80, 80], 'contain' => [80, 80], 'cover' => [80, 80]] as $name => $size) {
            $video = FFMpeg::disk('local')->open($name.'.mkv')->probe()->video();
            $this->assertSame($size, [$video->width(), $video->height()], $name);
            $this->assertSame('1:1', $video->get('sample_aspect_ratio'), $name);
        }

        foreach (['stretch', 'contain', 'cover'] as $name) {
            $frame = Process::run([$ffmpeg, '-v', 'error', '-i', $local->path($name.'.mkv'), '-frames:v', '1', '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-']);
            $this->assertProcessSucceeded($frame);
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

        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('unsafeOptions')]
    public function test_unsafe_or_ambiguous_options_fail_before_any_process(array $tokens): void
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

    #[DataProvider('invalidStreamTagProvider')]
    public function test_stream_tag_helpers_reject_invalid_output_selectors_and_values_without_io(Closure $configure): void
    {
        Process::fake();

        $this->assertThrows(
            fn () => $configure(FFMpeg::disk('missing')->open('absent.mkv')->export()),
            InvalidArgumentException::class,
        );

        Process::assertNothingRan();
    }

    public static function invalidStreamTagProvider(): array
    {
        return [
            'input stream selector' => [fn (PendingExport $export) => $export->streamMetadata('0:a:0', 'language', 'eng')],
            'graph label selector' => [fn (PendingExport $export) => $export->streamMetadata('[audio]', 'language', 'eng')],
            'optional selector' => [fn (PendingExport $export) => $export->streamMetadata('a:0?', 'language', 'eng')],
            'key with an equals sign' => [fn (PendingExport $export) => $export->streamMetadata('a:0', 'bad=key', 'value')],
            'value with a null byte' => [fn (PendingExport $export) => $export->streamMetadata('a:0', 'title', "bad\0value")],
            'negative disposition index' => [fn (PendingExport $export) => $export->disposition(-1, 'default')],
            'unknown disposition flag' => [fn (PendingExport $export) => $export->disposition('a', 'not_a_flag')],
            'relative disposition flag' => [fn (PendingExport $export) => $export->disposition('a', '+default')],
            'negated flag in a list' => [fn (PendingExport $export) => $export->disposition('a', ['default', '-forced'])],
            'keyed flag list' => [fn (PendingExport $export) => $export->disposition('a', ['default' => true])],
            'stream metadata after outputs' => [fn (PendingExport $export) => $export->output('one.mkv', fn () => null)->streamMetadata('a', 'language', 'eng')],
            'outputs after a disposition' => [fn (PendingExport $export) => $export->disposition('a', 'default')->output('one.mkv', fn () => null)],
        ];
    }

    #[DataProvider('conflictingConfigurationProvider')]
    public function test_rate_control_copy_and_clip_conflicts_fail_before_execution(Closure $configure): void
    {
        Process::fake();

        $this->assertThrows(
            fn () => $configure(FFMpeg::disk('memory')->open('source.mp4')->export()),
            InvalidArgumentException::class,
        );

        Process::assertNothingRan();
    }

    public static function conflictingConfigurationProvider(): array
    {
        return [
            'integer bitrate and crf' => [fn (PendingExport $export) => $export->video('libx264', bitrate: 800_000, crf: 23)->inspect()],
            'unit bitrate and crf' => [fn (PendingExport $export) => $export->video('libx264', bitrate: '2500k', crf: 23)->inspect()],
            'filter on copied video' => [fn (PendingExport $export) => $export->video('copy')->videoFilter('hflip')->inspect()],
            'accurate clip of copied audio' => [fn (PendingExport $export) => $export->audio('copy')->clip(1, duration: 2)->inspect()],
            'clip duration and end' => [fn (PendingExport $export) => $export->clip(1, duration: 2, end: 3)->inspect()],
            'clip ending before its start' => [fn (PendingExport $export) => $export->clip(1, end: 0.5)->inspect()],
            'audio map without audio' => [fn (PendingExport $export) => $export->withoutAudio()->map('0:a:0')->inspect()],
            'map from a missing input' => [fn (PendingExport $export) => $export->map('1:a:0?')->inspect()],
        ];
    }

    public function test_keyframe_clips_of_copied_streams_seek_before_the_input(): void
    {
        Process::fake();

        $command = FFMpeg::disk('memory')->open('source.mp4')->export()
            ->video('copy')->audio('copy')->clip(1.5, duration: 2, seek: 'keyframe')->inspect();

        $this->assertLessThan(array_search('-i', $command), array_search('-ss', $command));
        $this->assertContains('-noaccurate_seek', $command);
        Process::assertNothingRan();
    }

    #[DataProvider('validBitrateProvider')]
    public function test_bitrates_accept_positive_integer_bits_per_second_and_preserve_explicit_units(int|string $bitrate): void
    {
        Process::fake();

        $command = FFMpeg::disk('missing')->open('absent.mkv')->export()
            ->video('libx264', bitrate: $bitrate)->audio('aac', bitrate: $bitrate)->inspect();

        $this->assertSame((string) $bitrate, $command[array_search('-b:v', $command, true) + 1]);
        $this->assertSame((string) $bitrate, $command[array_search('-b:a', $command, true) + 1]);
        Process::assertNothingRan();
    }

    public static function validBitrateProvider(): array
    {
        return [
            'one bit per second' => [1],
            'bits per second' => [2500],
            'large bits per second' => [800_000],
            'largest integer' => [PHP_INT_MAX],
            'kilobits' => ['800k'],
            'fractional megabits' => ['1.5M'],
            'gigabits' => ['2G'],
            'uppercase kilobits' => ['96K'],
            'lowercase megabits' => ['0.5m'],
            'lowercase gigabits' => ['0.1g'],
        ];
    }

    #[DataProvider('invalidBitrateProvider')]
    public function test_invalid_bitrates_are_rejected_for_video_and_audio(mixed $bitrate): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('absent.mkv');

        $this->assertThrows(fn () => $media->export()->video(bitrate: $bitrate)->inspect(), InvalidArgumentException::class);
        $this->assertThrows(fn () => $media->export()->audio(bitrate: $bitrate)->inspect(), InvalidArgumentException::class);

        Process::assertNothingRan();
    }

    public static function invalidBitrateProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'integral float' => [800_000.0],
            'fraction' => [0.5],
            'infinity' => [INF],
            'not a number' => [NAN],
            'true' => [true],
            'false' => [false],
            'array' => [[]],
            'empty string' => [''],
            'unitless string' => ['800000'],
            'zero kilobits' => ['0k'],
            'zero megabits' => ['0.0M'],
            'negative kilobits' => ['-1k'],
            'exponent' => ['1e3k'],
            'trailing newline' => ["800k\n"],
        ];
    }

    public function test_real_integer_bitrates_encode_identically_to_explicit_unit_strings(): void
    {
        $ffmpeg = $this->useRealFFMpeg();
        $fixture = $this->root.'/bitrates.mkv';
        $generation = Process::run([
            $ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=s=96x64:r=10:d=1',
            '-f', 'lavfi', '-i', 'sine=frequency=700:sample_rate=48000:duration=1',
            '-c:v', 'ffv1', '-c:a', 'pcm_s16le', $fixture,
        ]);
        $this->assertProcessSucceeded($generation);
        Storage::disk('memory')->put('bitrates.mkv', file_get_contents($fixture));
        $media = FFMpeg::disk('memory')->open('bitrates.mkv');
        $media->export()->video('libx264', bitrate: 800_000)->audio('aac', bitrate: 64_000)->save('integer.mp4');
        $media->export()->video('libx264', bitrate: '800k')->audio('aac', bitrate: '64k')->save('units.mp4');

        $this->assertSame(Storage::disk('memory')->get('units.mp4'), Storage::disk('memory')->get('integer.mp4'));
        $metadata = FFMpeg::disk('memory')->open('integer.mp4')->probe();
        $this->assertSame('h264', $metadata->video()->codec());
        $this->assertSame('aac', $metadata->audio()->codec());
        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('clipTimecodeProvider')]
    public function test_clip_timecodes_normalize_all_positions_and_mix_with_numeric_seconds(
        int|float|string $start,
        int|string|null $duration,
        ?string $end,
        string $expectedStart,
        string $expectedDuration,
    ): void {
        Process::fake();

        $command = FFMpeg::disk('missing')->open('absent.mkv')->export()
            ->clip($start, duration: $duration, end: $end)->inspect();

        $this->assertSame($expectedStart, $command[array_search('-ss', $command, true) + 1]);
        $this->assertSame($expectedDuration, $command[array_search('-t', $command, true) + 1]);
        Process::assertNothingRan();
    }

    public static function clipTimecodeProvider(): array
    {
        return [
            'hours with an end timecode' => ['01:02:59.75', null, '01:03:01.125', '3779.75', '1.375'],
            'zero start' => [0, '00:00:00.125', null, '0', '0.125'],
            'one hundred hours' => ['100:00:00', 2, null, '360000', '2'],
            'microseconds' => ['00:00:13.000001', '00:00:01.000001', null, '13.000001', '1.000001'],
            'numeric start with an end timecode' => [1.25, null, '00:00:02.5', '1.25', '1.25'],
            'smallest timecodes' => ['00:00:00.000001', '00:00:00.000002', null, '0.000001', '0.000002'],
        ];
    }

    public function test_keyframe_clips_keep_microsecond_timecodes_before_the_input(): void
    {
        Process::fake();

        $command = FFMpeg::disk('missing')->open('absent.mkv')->export()
            ->clip('00:00:00.000001', duration: '00:00:00.000002', seek: 'keyframe')->inspect();

        $this->assertSame('0.000001', $command[array_search('-ss', $command, true) + 1]);
        $this->assertLessThan(array_search('-i', $command, true), array_search('-ss', $command, true));
        Process::assertNothingRan();
    }

    #[DataProvider('invalidTimecodeProvider')]
    public function test_clip_rejects_invalid_timecodes_in_every_position(string $timecode): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('absent.mkv');

        $this->assertThrows(fn () => $media->export()->clip(0, duration: $timecode)->inspect(), InvalidArgumentException::class);
        $this->assertThrows(fn () => $media->export()->clip($timecode, duration: 1)->inspect(), InvalidArgumentException::class);
        $this->assertThrows(fn () => $media->export()->clip(0, end: $timecode)->inspect(), InvalidArgumentException::class);

        Process::assertNothingRan();
    }

    public static function invalidTimecodeProvider(): array
    {
        return [
            'empty' => [''],
            'bare seconds' => ['12.5'],
            'exponent' => ['1e2'],
            'unit suffix' => ['1s'],
            'single digit hours' => ['1:02:03'],
            'sixty minutes' => ['00:60:00'],
            'sixty seconds' => ['00:00:60'],
            'negative' => ['-00:00:01'],
            'trailing dot' => ['00:00:01.'],
            'seven fractional digits' => ['00:00:01.1234567'],
            'trailing newline' => ["00:00:01\n"],
            'leading space' => [' 00:00:01'],
            'null byte' => ["00:00:01\0"],
        ];
    }

    #[DataProvider('invalidClipRangeProvider')]
    public function test_clip_rejects_invalid_ranges_before_execution(
        int|float|string $start,
        int|string|null $duration,
        int|string|null $end,
    ): void {
        Process::fake();

        $this->assertThrows(
            fn () => FFMpeg::disk('missing')->open('absent.mkv')->export()->clip($start, duration: $duration, end: $end)->inspect(),
            InvalidArgumentException::class,
        );

        Process::assertNothingRan();
    }

    public static function invalidClipRangeProvider(): array
    {
        return [
            'zero duration' => [0, '00:00:00', null],
            'end at the start' => ['00:00:01', null, 1],
            'end before the start' => [2, null, '00:00:01'],
            'infinite start' => [INF, 1, null],
        ];
    }

    public function test_frame_exports_publish_validated_images(): void
    {
        $image = file_get_contents(__DIR__.'/src/logo.png');
        $this->fakePipeline(function (PendingProcess $process) use ($image) {
            file_put_contents(end($process->command), $image);

            return Process::result();
        });

        $result = FFMpeg::disk('memory')->open('source.mp4')->frame(at: '00:00:01.125')->save('nested/frame.png');

        $this->assertSame($image, Storage::disk('memory')->get('nested/frame.png'));
        $this->assertSame('image', $result->file()->role);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_frame_exports_do_not_publish_non_image_bytes(): void
    {
        $this->fakePipeline();

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->frame(at: 1)->save('invalid.png'),
            fn (MediaException $exception) => $exception->phase === 'validation' && $exception->category === 'artifact',
        );

        $this->assertFalse(Storage::disk('memory')->exists('invalid.png'));
        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('invalidFrameProvider')]
    public function test_frames_require_a_valid_timestamp_and_an_image_destination(Closure $capture): void
    {
        $this->fakePipeline();

        $this->assertThrows(
            fn () => $capture(FFMpeg::disk('memory')->open('source.mp4')),
            InvalidArgumentException::class,
        );
    }

    public static function invalidFrameProvider(): array
    {
        return [
            'negative timestamp' => [fn (Media $media) => $media->frame(at: -1)],
            'infinite timestamp' => [fn (Media $media) => $media->frame(at: INF)],
            'bare seconds string' => [fn (Media $media) => $media->frame(at: '12.5')],
            'video destination' => [fn (Media $media) => $media->frame(at: 0)->save('frame.mp4')],
        ];
    }

    public function test_real_frames_return_the_first_frame_after_the_timestamp(): void
    {
        $media = $this->openSparseVideo();
        $phases = [];

        $bytes = $media->frame(at: '00:00:01.100')->contain(32, 32)->toDisk('not-configured')
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
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_real_frames_publish_resized_images(): void
    {
        $result = $this->openSparseVideo()->frame(at: 1.1)->resize(width: 48)->save('nested/%03d.png');

        $this->assertSame('image', $result->file()->role);
        $this->assertSame('memory', $result->file()->disk);
        $png = Storage::disk('memory')->get('nested/%03d.png');
        $this->assertSame([48, 32, IMAGETYPE_PNG], array_slice(getimagesizefromstring($png), 0, 3));
        $image = imagecreatefromstring($png);
        $this->assertGreaterThan(200, imagecolorsforindex($image, imagecolorat($image, 24, 16))['blue']);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_real_frames_default_to_the_first_frame(): void
    {
        $first = $this->openSparseVideo()->frame()->get();

        $this->assertSame([96, 64, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($first), 0, 3));
        $image = imagecreatefromstring($first);
        $this->assertGreaterThan(200, imagecolorsforindex($image, imagecolorat($image, 48, 32))['red']);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_real_frames_beyond_the_final_frame_are_not_published(): void
    {
        $media = $this->openSparseVideo();

        $this->assertThrows(
            fn () => $media->frame(at: 99)->save('beyond-end.jpg'),
            fn (MediaException $exception) => $exception->published === [],
        );

        $this->assertFalse(Storage::disk('memory')->exists('beyond-end.jpg'));
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Open a red frame followed three seconds later by a blue frame.
     */
    protected function openSparseVideo(): Media
    {
        $fixture = $this->fixture('sparse.mkv', [
            '-f', 'lavfi', '-i', 'color=c=red:s=96x64:r=1:d=1',
            '-f', 'lavfi', '-i', 'color=c=blue:s=96x64:r=1:d=1',
            '-filter_complex', '[0:v][1:v]concat=n=2:v=1:a=0,setpts=3*N/TB[v]',
            '-map', '[v]', '-fps_mode', 'vfr', '-c:v', 'ffv1',
        ]);

        Storage::disk('memory')->put('sparse.mkv', file_get_contents($fixture));

        return FFMpeg::disk('memory')->open('sparse.mkv');
    }

    #[DataProvider('invalidSamplingProvider')]
    public function test_image_sequences_require_valid_sampling(Closure $sample): void
    {
        Process::fake();

        $this->assertThrows(
            fn () => $sample(FFMpeg::disk('memory')->open('source.mp4')),
            InvalidArgumentException::class,
        );

        Process::assertNothingRan();
    }

    public static function invalidSamplingProvider(): array
    {
        return [
            'no sampling' => [fn (Media $media) => $media->frames()],
            'interval and count' => [fn (Media $media) => $media->frames(every: 1, count: 2)],
            'zero interval' => [fn (Media $media) => $media->frames(every: 0)],
            'infinite interval' => [fn (Media $media) => $media->frames(every: INF)],
            'interval below a microsecond' => [fn (Media $media) => $media->frames(every: 0.0000001)],
            'zero count' => [fn (Media $media) => $media->frames(count: 0)],
            'negative count' => [fn (Media $media) => $media->frames(count: -1)],
        ];
    }

    #[DataProvider('invalidSequenceDestinationProvider')]
    public function test_image_sequences_require_numbered_image_destinations(?string $path): void
    {
        Process::fake();

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->frames(count: 2)->save($path),
            InvalidArgumentException::class,
        );

        Process::assertNothingRan();
    }

    public static function invalidSequenceDestinationProvider(): array
    {
        return [
            'no destination' => [null],
            'no placeholder' => ['still.png'],
            'string placeholder' => ['frame-%s.png'],
            'two placeholders' => ['frame-%d-%d.png'],
            'ten digit placeholder' => ['frame-%010d.png'],
            'placeholder in a directory' => ['frame-%02d/child.png'],
            'placeholder before a backslash' => ['frame-%02d\\child.png'],
            'video extension' => ['frame-%02d.mp4'],
        ];
    }

    public function test_count_sampling_requires_a_known_duration(): void
    {
        $this->fakePipeline(metadata: '{"format":{},"streams":[{"index":0,"codec_type":"video"}]}');

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->frames(count: 2)->save('unknown-%d.png'),
            fn (MediaException $exception) => $exception->phase === 'probing' && $exception->category === 'metadata',
        );

        $this->assertWorkspacesAreCleanedUp();
    }

    #[TestWith(['corrupt'], 'corrupt image')]
    #[TestWith(['missing'], 'missing image')]
    #[TestWith(['gap'], 'gap in the numbering')]
    public function test_image_sequences_validate_every_image_and_inventory_before_any_publication(string $failure): void
    {
        $image = file_get_contents(__DIR__.'/src/logo.png');
        $this->fakePipeline(function (PendingProcess $process) use ($image, $failure) {
            $pattern = end($process->command);
            file_put_contents(sprintf($pattern, 1), $image);

            if ($failure !== 'missing') {
                file_put_contents(sprintf($pattern, $failure === 'gap' ? 3 : 2), $failure === 'corrupt' ? 'not an image' : $image);
            }

            return Process::result();
        });

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->frames(count: 2)->save('invalid-%02d.png'),
            fn (MediaException $exception) => $exception->phase === 'validation'
                && $exception->category === 'artifact'
                && $exception->published === [],
        );

        $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_image_sequences_report_concrete_siblings_after_partial_publication(): void
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
        $destination->shouldReceive('put')->andReturnUsing(fn ($path, $stream, $options) => $path === 'partial/02.png' ? false : $storage->put($path, $stream, $options));
        $destination->shouldReceive('size')->andReturnUsing(fn ($path) => $storage->size($path));

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->frames(count: 2)
                ->toDisk($destination)->save('partial/%02d.png'),
            fn (MediaException $exception) => $exception->phase === 'publication'
                && $exception->category === 'transfer'
                && $exception->published === [['disk' => null, 'path' => 'partial/01.png']]
                && $exception->destination === ['disk' => null, 'path' => 'partial/02.png'],
        );
        $this->assertSame($image, $storage->get('partial/01.png'));
        $this->assertFalse($storage->exists('partial/02.png'));
        $this->assertWorkspacesAreCleanedUp();
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    public function test_real_image_sequences_sample_at_intervals(): void
    {
        $result = $this->openColorBars()->frames(every: 0.2)->resize(width: 40)->save('interval/%03d.png');

        $this->assertSame(['interval/001.png', 'interval/002.png', 'interval/003.png'], $result->files()->pluck('path')->all());

        foreach ($result->files() as $index => $file) {
            $bytes = Storage::disk('memory')->get($file->path);
            $this->assertSame([40, 24, IMAGETYPE_PNG], array_slice(getimagesizefromstring($bytes), 0, 3));
            $this->assertSame('image', $file->role);
            $image = imagecreatefromstring($bytes);
            $color = imagecolorsforindex($image, imagecolorat($image, 20, 12));
            $this->assertGreaterThan(200, $color[['red', 'green', 'blue'][$index]]);
        }

        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_real_image_sequences_sample_a_count_across_the_duration(): void
    {
        $counted = $this->openColorBars()->frames(count: 5)->save('count/%d.jpg');

        $this->assertSame(['count/1.jpg', 'count/2.jpg', 'count/3.jpg', 'count/4.jpg', 'count/5.jpg'], $counted->files()->pluck('path')->all());
        $last = imagecreatefromstring(Storage::disk('memory')->get('count/5.jpg'));
        $this->assertGreaterThan(200, imagecolorsforindex($last, imagecolorat($last, 40, 24))['blue']);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_real_image_sequences_resample_short_clips(): void
    {
        $short = $this->openColorBars()->frames(count: 4)->clip(0.2, duration: 0.1)->save('short/%02d.jpg');

        $this->assertSame(['short/01.jpg', 'short/02.jpg', 'short/03.jpg', 'short/04.jpg'], $short->files()->pluck('path')->all());

        foreach ($short->files() as $file) {
            $bytes = Storage::disk('memory')->get($file->path);
            $this->assertSame([80, 48, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($bytes), 0, 3));
            $image = imagecreatefromstring($bytes);
            $this->assertGreaterThan(200, imagecolorsforindex($image, imagecolorat($image, 40, 24))['green']);
        }

        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Open a video that shows red, green and blue for 0.2 seconds each.
     */
    protected function openColorBars(): Media
    {
        $fixture = $this->fixture('colors.mkv', [
            '-f', 'lavfi', '-i', 'color=c=red:s=80x48:r=10:d=0.2',
            '-f', 'lavfi', '-i', 'color=c=lime:s=80x48:r=10:d=0.2',
            '-f', 'lavfi', '-i', 'color=c=blue:s=80x48:r=10:d=0.2',
            '-filter_complex', '[0:v][1:v][2:v]concat=n=3:v=1:a=0[v]', '-map', '[v]', '-c:v', 'ffv1',
        ]);

        Storage::disk('memory')->put('colors.mkv', file_get_contents($fixture));

        return FFMpeg::disk('memory')->open('colors.mkv');
    }

    #[DataProvider('invalidTwoPassProvider')]
    public function test_two_pass_encoding_requires_explicit_video_bitrate_without_crf(Closure $configure): void
    {
        Process::fake();

        $this->assertThrows(
            fn () => $configure(FFMpeg::disk('missing')->open('absent.mp4')->export()),
            InvalidArgumentException::class,
        );

        Process::assertNothingRan();
    }

    public static function invalidTwoPassProvider(): array
    {
        return [
            'no bitrate' => [fn (PendingExport $export) => $export->twoPass()->inspect()],
            'crf' => [fn (PendingExport $export) => $export->video('libx264', crf: 23)->twoPass()->inspect()],
            'implicit encoder' => [fn (PendingExport $export) => $export->video(bitrate: '150k')->twoPass()->inspect()],
            'copied video' => [fn (PendingExport $export) => $export->video('copy', bitrate: '150k')->twoPass()->inspect()],
            'without video' => [fn (PendingExport $export) => $export->video('libx264', bitrate: '150k')->withoutVideo()->twoPass()->inspect()],
        ];
    }

    public function test_single_pass_encoding_has_no_pass_arguments(): void
    {
        Process::fake();

        $command = FFMpeg::disk('missing')->open('absent.mp4')->export()->video('libx264', bitrate: '150k')->inspect();

        $this->assertNotContains('-pass:v', $command);
        Process::assertNothingRan();
    }

    public function test_two_pass_encoding_requires_a_video_stream(): void
    {
        $this->fakePipeline(metadata: '{"format":{"duration":"1"},"streams":[{"index":0,"codec_type":"audio"}]}');

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->export()->video('libx264', bitrate: '150k')->twoPass()->save('audio.mp4'),
            fn (MediaException $exception) => $exception->phase === 'probing' && $exception->category === 'mapping',
        );

        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('failedPassProvider')]
    public function test_failed_passes_never_publish_or_retain_statistics(string $failure, string $category): void
    {
        $this->fakeTwoPassPipeline($failure);

        $this->assertThrows(
            fn () => $this->twoPassExport()->save('failed.mp4'),
            fn (MediaException $exception) => $exception->phase === 'encoding'
                && $exception->category === $category
                && $exception->published === [],
        );

        $this->assertFalse(Storage::disk('memory')->exists('failed.mp4'));
        $this->assertWorkspacesAreCleanedUp();
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    public static function failedPassProvider(): array
    {
        return [
            'missing statistics' => ['missing', 'artifact'],
            'failed first pass' => ['first', 'exit'],
            'failed second pass' => ['second', 'exit'],
        ];
    }

    #[TestWith([1], 'first pass')]
    #[TestWith([2], 'second pass')]
    public function test_cancelled_passes_never_publish_or_retain_statistics(int $pass): void
    {
        $this->fakeTwoPassPipeline();

        $this->assertThrows(
            fn () => $this->twoPassExport()->onProgress(function (Progress $progress) use ($pass) {
                if ($progress->isEncoding() && $progress->pass === $pass) {
                    throw new ExportCancelled('cancel pass');
                }
            })->save('failed.mp4'),
            ExportCancelled::class,
        );

        $this->assertFalse(Storage::disk('memory')->exists('failed.mp4'));
        $this->assertWorkspacesAreCleanedUp();
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    /**
     * Fake a two-pass encode in which the given step fails.
     */
    protected function fakeTwoPassPipeline(?string $failure = null): void
    {
        $this->fakePipeline(function (PendingProcess $process) use ($failure) {
            $pass = (int) $process->command[array_search('-pass:v', $process->command, true) + 1];

            if ($pass === 1 && $failure !== 'missing') {
                file_put_contents($process->path.'/pass-0-0.log', 'private statistics');
            } elseif ($pass === 2) {
                file_put_contents(end($process->command), 'encoded media');
            }

            return Process::result(exitCode: ($failure === 'first' && $pass === 1) || ($failure === 'second' && $pass === 2) ? 7 : 0);
        });
    }

    /**
     * Get a two-pass export of the source file.
     */
    protected function twoPassExport(): PendingExport
    {
        return FFMpeg::disk('memory')->open('source.mp4')->export()->video('libx264', bitrate: '150k')->twoPass();
    }

    public function test_real_two_pass_exports_keep_independent_output_statistics_and_report_both_passes(): void
    {
        $ffmpeg = $this->useRealFFMpeg();
        $fixture = $this->root.'/passes.mkv';
        $generation = Process::run([
            $ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=s=96x64:r=10:d=1.2',
            '-f', 'lavfi', '-i', 'sine=frequency=700:sample_rate=48000:duration=1.2',
            '-c:v', 'ffv1', '-c:a', 'pcm_s16le', $fixture,
        ]);
        $this->assertProcessSucceeded($generation);
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
        $this->assertWorkspacesAreCleanedUp();
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
        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('invalidWaveformProvider')]
    public function test_invalid_waveform_requests_are_rejected_before_any_process(Closure $configure): void
    {
        Process::fake();

        $this->assertThrows(
            fn () => $configure(FFMpeg::disk('memory')->open('source.mp4')),
            InvalidArgumentException::class,
        );

        Process::assertNothingRan();
    }

    public static function invalidWaveformProvider(): array
    {
        return [
            'zero width' => [fn (Media $media) => $media->waveform(width: 0)],
            'negative height' => [fn (Media $media) => $media->waveform(height: -1)],
            'empty color list' => [fn (Media $media) => $media->waveform(colors: [])],
            'separator in a color list' => [fn (Media $media) => $media->waveform(colors: ['red|blue'])],
            'filter options in a color list' => [fn (Media $media) => $media->waveform(colors: ['red:draw=full'])],
            'short hex color in a list' => [fn (Media $media) => $media->waveform(colors: ['#fff'])],
            'integer color' => [fn (Media $media) => $media->waveform(colors: [123])],
            'keyed color list' => [fn (Media $media) => $media->waveform(colors: ['color' => 'red'])],
            'empty color' => [fn (Media $media) => $media->waveform(colors: '')],
            'separator in a color' => [fn (Media $media) => $media->waveform(colors: 'red|blue')],
            'filter options in a color' => [fn (Media $media) => $media->waveform(colors: 'red:draw=full')],
            'short hex color' => [fn (Media $media) => $media->waveform(colors: '#fff')],
            'color with a newline' => [fn (Media $media) => $media->waveform(colors: "blue\n")],
            'keyframe clip' => [fn (Media $media) => $media->waveform()->clip(0, duration: 1, seek: 'keyframe')->inspect()],
            'video color settings' => [fn (Media $media) => $media->waveform()->videoStream(0, fn ($v) => $v->color('bt709', 'bt709', 'bt709'))->inspect()],
        ];
    }

    public function test_invalid_waveform_images_are_not_published(): void
    {
        $this->fakePipeline();

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->waveform()->save('invalid-wave.png'),
            fn (MediaException $exception) => $exception->phase === 'validation' && $exception->category === 'artifact',
        );

        $this->assertFalse(Storage::disk('memory')->exists('invalid-wave.png'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_real_waveforms_draw_the_audio_levels(): void
    {
        $operation = $this->openLevels()->waveform(width: 80, height: 41);
        $inspection = $operation->inspect();
        $this->assertSame($inspection, $operation->inspect());
        $this->assertWorkspacesAreCleanedUp();

        $result = $operation->onProgress(fn (Progress $progress) => $this->assertNull($progress->percentage))->save('wave.png');

        $this->assertSame('image', $result->file()->role);
        $bytes = Storage::disk('memory')->get('wave.png');
        $this->assertSame([80, 41, IMAGETYPE_PNG], array_slice(getimagesizefromstring($bytes), 0, 3));
        $image = imagecreatefromstring($bytes);
        $this->assertSame(0, imagecolorsforindex($image, imagecolorat($image, 5, 10))['blue']);
        $this->assertGreaterThan(200, imagecolorsforindex($image, imagecolorat($image, 60, 10))['blue']);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_real_waveforms_apply_audio_filters(): void
    {
        $this->openLevels()->waveform(width: 80, height: 41)->audioFilter('volume=0')->save('muted.png');

        $muted = imagecreatefromstring(Storage::disk('memory')->get('muted.png'));
        $this->assertSame(0, imagecolorsforindex($muted, imagecolorat($muted, 60, 10))['blue']);
        $this->assertWorkspacesAreCleanedUp();
    }

    #[TestWith(['red'], 'name')]
    #[TestWith(['#ff0000'], 'hex')]
    #[TestWith(['0xff0000ff'], 'hex with alpha')]
    public function test_real_waveform_colors_may_be_a_single_string(string $color): void
    {
        $media = $this->openLevels();
        $scalar = $media->waveform(width: 80, height: 41, colors: $color);

        $this->assertSame($media->waveform(width: 80, height: 41, colors: [$color])->inspect(), $scalar->inspect());

        $scalar->save('scalar.png');

        $colored = imagecreatefromstring(Storage::disk('memory')->get('scalar.png'));
        $pixel = imagecolorsforindex($colored, imagecolorat($colored, 60, 10));
        $this->assertGreaterThan(200, $pixel['red']);
        $this->assertSame(0, $pixel['blue']);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_real_waveforms_can_be_clipped_and_resized(): void
    {
        $this->openLevels()->waveform(width: 80, height: 41, colors: ['#ff0000'])->clip(0.6, duration: 0.3)
            ->resize(width: 40)->save('clipped.jpg');

        $bytes = Storage::disk('memory')->get('clipped.jpg');
        $this->assertSame([40, 20, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($bytes), 0, 3));
        $clipped = imagecreatefromstring($bytes);
        $this->assertGreaterThan(200, imagecolorsforindex($clipped, imagecolorat($clipped, 2, 5))['red']);
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Open audio that is silent for 0.3 seconds and loud afterwards.
     */
    protected function openLevels(): Media
    {
        $fixture = $this->fixture('levels.wav', [
            '-f', 'lavfi', '-i', 'aevalsrc=if(lt(t\,0.3)\,0\,0.8):s=8000:d=1.2',
            '-c:a', 'pcm_s16le',
        ]);

        Storage::disk('memory')->put('levels.wav', file_get_contents($fixture));

        return FFMpeg::disk('memory')->open('levels.wav');
    }

    public function test_generated_files_read_streams_from_the_retained_filesystem(): void
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

        $filesystem->delete($file->path);
        $this->assertNull($file->readStream());
    }

    public function test_generated_files_delegate_temporary_urls_to_the_retained_filesystem(): void
    {
        $this->fakePipeline();
        $filesystem = Storage::disk('memory');
        $file = FFMpeg::disk($filesystem)->open('source.mp4')->export()->save('nested/access.mp4')->file();
        $expiration = now()->addMinutes(5);

        $this->assertThrows(
            fn () => $file->temporaryUrl($expiration),
            fn (RuntimeException $exception) => $exception->getMessage() === 'This driver does not support creating temporary URLs.',
        );

        $arguments = null;
        $filesystem->buildTemporaryUrlsUsing(function ($path, $expires, $options) use (&$arguments) {
            $arguments = [$path, $expires, $options];

            return 'https://example.test/signed-preview';
        });

        $this->assertSame('https://example.test/signed-preview', $file->temporaryUrl($expiration, ['download' => 'preview.mp4']));
        $this->assertSame(['nested/access.mp4', $expiration, ['download' => 'preview.mp4']], $arguments);
    }

    public function test_a_single_export_publishes_only_after_validation_and_cleans_before_completion(): void
    {
        $this->fakePipeline();
        $snapshots = [];
        $completed = null;
        Event::fake([Events\ExportStarting::class, Events\ExportFailed::class, Events\ExportCancelled::class]);
        Event::listen(Events\ExportCompleted::class, function ($event) use (&$completed) {
            $this->assertWorkspacesAreCleanedUp();
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
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_global_progress_events_work_without_a_per_export_callback(): void
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

    public function test_disabling_events_preserves_callbacks_and_publication(): void
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
        Event::assertNothingDispatched();
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_disabling_events_still_propagates_callback_failures(): void
    {
        $this->fakePipeline();
        config(['laravel-ffmpeg.enable_events' => false]);
        Event::fake();
        $failure = new RuntimeException('Application callback failure');

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->export()->onProgress(function () use ($failure) {
                throw $failure;
            })->save('failed.mp4'),
            fn (RuntimeException $exception) => $exception === $failure,
        );

        $this->assertFalse(Storage::disk('memory')->exists('failed.mp4'));
        Event::assertNothingDispatched();
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_global_progress_listeners_can_cancel_before_publication(): void
    {
        $this->fakePipeline();
        $cancelled = new ExportCancelled('Global listener cancellation');
        Event::listen(Events\ExportProgress::class, function ($event) use ($cancelled) {
            if ($event->progress->elapsedSeconds !== null) {
                throw $cancelled;
            }
        });

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->export()->save('cancelled.mp4'),
            fn (ExportCancelled $exception) => $exception === $cancelled,
        );

        Event::assertDispatched(Events\ExportCancelled::class);
        Event::assertNotDispatched(Events\ExportCompleted::class);
        $this->assertFalse(Storage::disk('memory')->exists('cancelled.mp4'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_progress_chunks_are_parsed_without_inventing_unknown_percentages(): void
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

    #[TestWith([null], 'missing artifact')]
    #[TestWith([''], 'empty artifact')]
    public function test_missing_or_empty_artifacts_never_reach_publication(?string $contents): void
    {
        $this->fakePipeline(function (PendingProcess $process) use ($contents) {
            if ($contents !== null) {
                file_put_contents(end($process->command), $contents);
            }

            return Process::result(output: "out_time_us=10000000\nprogress=end\n");
        });

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->export()->save('missing.mp4'),
            fn (MediaException $exception) => $exception->phase === 'validation' && $exception->category === 'artifact',
        );

        $this->assertFalse(Storage::disk('memory')->exists('missing.mp4'));
        $this->assertWorkspacesAreCleanedUp();
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    public function test_encoding_failures_do_not_publish_and_report_the_encoding_phase(): void
    {
        $this->fakePipeline(fn () => Process::result(errorOutput: 'encoder rejected the input', exitCode: 7));

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->export()->save('failed.mp4'),
            fn (MediaException $exception) => $exception->phase === 'encoding'
                && str_contains($exception->diagnostics, 'encoder rejected'),
        );

        $this->assertFalse(Storage::disk('memory')->exists('failed.mp4'));
        $this->assertWorkspacesAreCleanedUp();
        Event::assertDispatched(Events\ExportFailed::class, fn ($event) => $event->phase === 'encoding');
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    #[DataProvider('privateDestinationProvider')]
    public function test_destination_options_merge_and_the_last_explicit_visibility_wins(Closure $configure): void
    {
        $this->fakePipeline();
        $media = FFMpeg::disk('memory')->open('source.mp4');

        $configure($media->export())->save('options.mp4');
        $media->export()->save('independent.mp4');

        $this->assertSame('encoded media', Storage::disk('memory')->get('options.mp4'));
        $this->assertSame('private', Storage::disk('memory')->getVisibility('options.mp4'));
        $this->assertSame('public', Storage::disk('memory')->getVisibility('independent.mp4'));
    }

    public static function privateDestinationProvider(): array
    {
        return [
            'visibility before options' => [fn (PendingExport $export) => $export->visibility('private')->toDisk('memory', ['ContentType' => 'video/mp4'])],
            'visibility after options' => [fn (PendingExport $export) => $export->toDisk('memory', ['visibility' => 'public'])->visibility('private')],
            'options after visibility' => [fn (PendingExport $export) => $export->visibility('public')->toDisk('memory', ['visibility' => 'private'])],
            'options kept by a later disk' => [fn (PendingExport $export) => $export->toDisk('memory', ['visibility' => 'private'])->toDisk('memory')],
        ];
    }

    public function test_false_storage_writes_fail_with_destination_context(): void
    {
        $this->fakePipeline();
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->with('failed.mp4', Mockery::type('resource'), ['visibility' => 'private', 'ContentType' => 'video/mp4'])->andReturn(false);

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->export()
                ->toDisk($disk, ['visibility' => 'private', 'ContentType' => 'video/mp4'])->save('failed.mp4'),
            fn (MediaException $exception) => $exception->phase === 'publication'
                && $exception->destination === ['disk' => null, 'path' => 'failed.mp4']
                && $exception->published === []
                && $exception->getPrevious() === null,
        );

        $this->assertWorkspacesAreCleanedUp();
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    public function test_incomplete_publication_identifies_the_object_already_written(): void
    {
        $this->fakePipeline();
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturn(true);
        $disk->shouldReceive('size')->with('partial.mp4')->andReturn(4);

        $published = [['disk' => null, 'path' => 'partial.mp4']];

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->export()->toDisk($disk)->save('partial.mp4'),
            fn (MediaException $exception) => $exception->phase === 'publication' && $exception->published === $published,
        );

        $this->assertWorkspacesAreCleanedUp();
        Event::assertDispatched(Events\ExportFailed::class, fn ($event) => $event->published === $published);
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    #[DataProvider('callbackFailures')]
    public function test_callback_exceptions_stop_the_real_child_and_preserve_the_original_failure(string $class): void
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

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('source.mp4')->export()->onProgress(function (Progress $progress) use ($original) {
                if ($progress->elapsedSeconds !== null) {
                    throw $original;
                }
            })->save('cancelled.mp4'),
            fn (RuntimeException $exception) => $exception === $original,
        );

        $this->assertFalse(posix_kill((int) file_get_contents($pidFile), 0));
        $this->assertFalse(Storage::disk('memory')->exists('cancelled.mp4'));
        $this->assertWorkspacesAreCleanedUp();
        Event::assertDispatched($class === ExportCancelled::class ? Events\ExportCancelled::class : Events\ExportFailed::class);
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    public static function callbackFailures(): array
    {
        return [[ExportCancelled::class], [RuntimeException::class]];
    }

    public function test_copied_streams_keep_the_container_metadata_and_chapters(): void
    {
        $copy = $this->openLecture()->export()->video('copy')->audio('copy')->save('copy.mkv');

        $metadata = FFMpeg::disk('memory')->open($copy->file()->path)->probe();
        $this->assertSame('h264', $metadata->video()->get('codec_name'));
        $this->assertSame('aac', $metadata->audio()->get('codec_name'));
        $this->assertSame('Original lecture', $metadata->get('format.tags.title'));
        $this->assertCount(2, $metadata->get('chapters'));
        $this->assertCount(1, $metadata->audioStreams());
        $this->assertLectureIsUnchanged();
    }

    public function test_metadata_and_chapters_can_be_omitted(): void
    {
        $omitted = $this->openLecture()->export()->video('copy')->audio('copy')->withoutMetadata(chapters: true)->save('omitted.mkv');

        $metadata = FFMpeg::disk('memory')->open($omitted->file()->path)->probe();
        $this->assertNull($metadata->tag('title'));
        $this->assertCount(0, $metadata->chapters());
        $this->assertSame('h264', $metadata->video()->codec());
        $this->assertSame('aac', $metadata->audio()->codec());
        $this->assertLectureIsUnchanged();
    }

    public function test_stream_specifiers_select_and_order_streams(): void
    {
        $selection = $this->openLecture()->export()->video('copy')->audio('copy')
            ->map('0:a:m:language:eng')->map('0:V:0')->map('0:a:m:language:fra')->map('-0:a:m:language:fra')
            ->outputOptions(['-disposition:a:0', 'default'])->save('english.mkv');

        $selected = FFMpeg::disk('memory')->open($selection->file()->path)->probe();
        $this->assertSame(['audio', 'video'], $selected->streams()->map(fn ($stream) => $stream->get('codec_type'))->all());
        $this->assertSame('eng', $selected->audio()->language());
        $this->assertTrue($selected->audio()->isDefault());
        $this->assertSame(48000, $selected->audio()->sampleRate());
        $this->assertSame(1, $selected->audio()->channels());
        $this->assertLectureIsUnchanged();
    }

    public function test_probed_streams_can_be_mapped(): void
    {
        $media = $this->openLecture();
        $probed = $media->probe();
        $english = $probed->audioStreams()->first(fn ($stream) => $stream->language() === 'eng');

        $selection = $media->export()->mapAudio($english)->mapVideo($probed->videoStreams()->first())
            ->audio('copy')->video('copy')->save('probed-selection.mkv');

        $metadata = FFMpeg::disk('memory')->open($selection->file()->path)->probe();
        $this->assertSame(['audio', 'video'], $metadata->streams()->map(fn ($stream) => $stream->get('codec_type'))->all());
        $this->assertSame('eng', $metadata->audio()->get('tags.language'));
        $this->assertLectureIsUnchanged();
    }

    public function test_numeric_maps_select_streams_by_language(): void
    {
        $selection = $this->openLecture()->export()->mapVideo(0)->mapAudio(0, language: 'fra', optional: true)
            ->video('copy')->audio('copy')->save('numeric-selection.mkv');

        $metadata = FFMpeg::disk('memory')->open($selection->file()->path)->probe();
        $this->assertSame(['video', 'audio'], $metadata->streams()->map(fn ($stream) => $stream->get('codec_type'))->all());
        $this->assertSame('fra', $metadata->audio()->get('tags.language'));
        $this->assertLectureIsUnchanged();
    }

    public function test_optional_maps_skip_absent_streams(): void
    {
        $selection = $this->openLecture()->export()->map('0:v:0:?')->mapAudio(0, language: 'deu', optional: true)
            ->video('copy')->audio('copy')->save('absent-selection.mkv');

        $metadata = FFMpeg::disk('memory')->open($selection->file()->path)->probe();
        $this->assertSame(['video'], $metadata->streams()->map(fn ($stream) => $stream->get('codec_type'))->all());
        $this->assertLectureIsUnchanged();
    }

    public function test_keyframe_clips_start_at_the_preceding_keyframe(): void
    {
        $ffmpeg = $this->useRealFFMpeg();
        $local = $this->fakeLocalDisk();

        $this->openLecture()->export()->video('copy')->audio('copy')->clip('00:00:01.300', duration: '00:00:00.600', seek: 'keyframe')->toDisk('local')->save('keyframe.mkv');

        $keyframe = Process::run([$ffmpeg, '-v', 'error', '-i', $local->path('keyframe.mkv'), '-map', '0:v:0', '-frames:v', '1', '-f', 'md5', '-']);
        $sourceFrame = Process::run([$ffmpeg, '-v', 'error', '-i', $this->lecture(), '-map', '0:v:0', '-vf', 'select=eq(n\\,10)', '-frames:v', '1', '-f', 'md5', '-']);
        $this->assertProcessSucceeded($keyframe);
        $this->assertProcessSucceeded($sourceFrame);
        $this->assertSame($sourceFrame->output(), $keyframe->output());
        $this->assertLectureIsUnchanged();
    }

    public function test_accurate_clips_apply_filters_metadata_and_visibility(): void
    {
        $ffmpeg = $this->useRealFFMpeg();
        $local = $this->fakeLocalDisk();

        $clip = $this->openLecture()->export()->video('libx264', crf: 25, preset: 'ultrafast')->audio('aac', bitrate: '64k')
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
        $this->assertLectureIsUnchanged();
    }

    public function test_absent_optional_maps_do_not_select_other_streams(): void
    {
        $media = $this->openLecture();

        $this->assertThrows(
            fn () => $media->export()->map('0:s:0?')->save('unexpected.mp4'),
            fn (MediaException $exception) => $exception->category === 'mapping',
        );

        $this->assertFalse(Storage::disk('memory')->exists('unexpected.mp4'));
        $this->assertLectureIsUnchanged();
    }

    /**
     * Get the path of a lecture with two chapters, a French and an English audio track.
     */
    protected function lecture(): string
    {
        if (! is_file($chapters = $this->fixturePath('chapters.txt'))) {
            file_put_contents($chapters, ";FFMETADATA1\ntitle=Original lecture\n[CHAPTER]\nTIMEBASE=1/1000\nSTART=0\nEND=1200\ntitle=First\n[CHAPTER]\nTIMEBASE=1/1000\nSTART=1200\nEND=2400\ntitle=Second\n");
        }

        return $this->fixture('lecture.mkv', [
            '-y', '-f', 'lavfi', '-i', 'testsrc2=size=160x90:rate=10:duration=2.4',
            '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=48000:duration=2.4',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=2.4',
            '-f', 'ffmetadata', '-i', $chapters, '-map', '0:v:0', '-map', '1:a:0', '-map', '2:a:0',
            '-map_metadata', '3', '-map_chapters', '3', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '18',
            '-g', '10', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-metadata:s:a:0', 'language=fra',
            '-metadata:s:a:1', 'language=eng',
        ]);
    }

    /**
     * Copy the lecture to the memory disk and open it.
     */
    protected function openLecture(): Media
    {
        Storage::disk('memory')->put('fixture.mkv', file_get_contents($this->lecture()));

        return FFMpeg::disk('memory')->open('fixture.mkv');
    }

    /**
     * Assert that exporting left the lecture untouched and cleaned up its workspaces.
     */
    protected function assertLectureIsUnchanged(): void
    {
        $this->assertSame(file_get_contents($this->lecture()), Storage::disk('memory')->get('fixture.mkv'));
        $this->assertWorkspacesAreCleanedUp();
    }
}

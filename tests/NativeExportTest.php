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
            'ffmpeg', '-hide_banner', '-nostdin', '-nostats', '-y', '-loglevel', 'warning', '-progress', 'pipe:1',
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
            fn () => $media->export()->video('libx264', bitrate: 2500)->inspect(),
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

        $media->export()->video('copy')->audio('copy')->clip(1.3, duration: 0.6, seek: 'keyframe')->toDisk('local')->save('keyframe.mkv');
        $keyframe = Process::run([$ffmpeg, '-v', 'error', '-i', $local->path('keyframe.mkv'), '-map', '0:v:0', '-frames:v', '1', '-f', 'md5', '-']);
        $sourceFrame = Process::run([$ffmpeg, '-v', 'error', '-i', $fixture, '-map', '0:v:0', '-vf', 'select=eq(n\\,10)', '-frames:v', '1', '-f', 'md5', '-']);
        $this->assertTrue($keyframe->successful(), $keyframe->errorOutput());
        $this->assertTrue($sourceFrame->successful(), $sourceFrame->errorOutput());
        $this->assertSame($sourceFrame->output(), $keyframe->output());

        $clip = $media->export()->video('libx264', crf: 25, preset: 'ultrafast')->audio('aac', bitrate: '64k')
            ->videoFilter('crop=120:80:20:4')->videoFilter('scale=60:40')->clip(0.4, duration: 1.2)
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

<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class NativeProbeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/native-probe-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/workspaces', 0700);
        config(['laravel-ffmpeg.temporary_files_root' => $this->root.'/workspaces']);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    private function metadataJson(): string
    {
        return json_encode([
            'format' => ['duration' => '12.375', 'tags' => ['title' => 'Lecture']],
            'chapters' => [['start_time' => '1.250', 'end_time' => '7.500']],
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'disposition' => ['attached_pic' => 1], 'width' => 80, 'height' => 80],
                ['index' => 1, 'codec_type' => 'audio', 'tags' => ['language' => 'fra'], 'disposition' => ['default' => 0]],
                ['index' => 2, 'codec_type' => 'video', 'width' => 854, 'height' => 480, 'avg_frame_rate' => '30000/1001', 'sample_aspect_ratio' => '4:3', 'color_transfer' => 'smpte2084'],
                ['index' => 3, 'codec_type' => 'audio', 'tags' => ['language' => 'eng'], 'disposition' => ['default' => 1]],
            ],
        ]);
    }

    private function fakeProbe(mixed $response = null, ?callable $inspect = null): void
    {
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($response, $inspect) {
            if (in_array('-version', $process->command, true)) {
                return Process::result(output: 'ffprobe version 9.0.2');
            }

            $inspect && $inspect($process);

            return $response ?? Process::result(output: $this->metadataJson());
        });
    }

    private function binary(string $body): string
    {
        $path = $this->root.'/ffprobe';
        file_put_contents($path, '#!'.PHP_BINARY."\n<?php\n".
            'if (in_array("-version", $argv, true)) { echo "ffprobe version 9.0.2\n"; exit; }'."\n".$body);
        chmod($path, 0700);
        config(['laravel-ffmpeg.ffprobe.binaries' => $path]);

        return $path;
    }

    #[Test]
    public function it_probes_a_local_input_without_mutating_or_staging_it(): void
    {
        $disk = $this->fakeLocalDisk();
        $key = 'nested/lecture ; $HOME.mp4';
        $disk->put($key, 'source bytes');
        $this->fakeProbe(inspect: function (PendingProcess $process) use ($disk, $key) {
            $this->assertSame([
                'ffprobe', '-v', 'error', '-print_format', 'json',
                '-show_format', '-show_streams', '-show_chapters', $disk->path($key),
            ], $process->command);
            $this->assertSame([], glob($process->path.'/*'));
        });

        $media = FFMpeg::disk('local')->open($key);
        Process::assertNothingRan();
        $this->assertSame([], glob($this->root.'/workspaces/*'));

        $metadata = $media->probe();

        $this->assertSame(12.375, $metadata->duration());
        $this->assertCount(4, $metadata->streams());
        $this->assertCount(1, $metadata->videoStreams());
        $this->assertSame(854, $metadata->video()->width());
        $this->assertSame(480, $metadata->video()->height());
        $this->assertSame('30000/1001', $metadata->video()->get('avg_frame_rate'));
        $this->assertEqualsWithDelta(29.97003, $metadata->video()->frameRate(), 0.00001);
        $this->assertSame('smpte2084', $metadata->video()->get('color_transfer'));
        $this->assertSame('fra', $metadata->audio()->get('tags.language'));
        $this->assertSame('1.250', $metadata->get('chapters.0.start_time'));
        $this->assertSame($key, $metadata->toArray()['format']['filename']);
        $this->assertSame('fallback', $metadata->get('missing', 'fallback'));
        $this->assertSame('source bytes', $disk->get($key));
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function it_stages_non_local_disks_and_supplied_filesystems_privately(): void
    {
        $disk = Storage::disk('memory');
        $disk->put('private/video.mp4', 'private media');
        $this->fakeProbe(inspect: function (PendingProcess $process) {
            $path = end($process->command);
            $this->assertSame('private media', file_get_contents($path));
            $this->assertSame(0700, fileperms(dirname($path)) & 0777);
            $this->assertSame(0600, fileperms($path) & 0777);
        });

        $this->assertSame(12.375, FFMpeg::disk('memory')->open('private/video.mp4')->probe()->duration());
        $this->assertSame(12.375, FFMpeg::disk($disk)->open('private/video.mp4')->probe()->duration());
        $this->assertSame([], glob($this->root.'/workspaces/*'));
        $this->assertSame('private media', $disk->get('private/video.mp4'));
    }

    #[Test]
    public function it_preserves_unknown_values_and_handles_audio_only_media(): void
    {
        $this->fakeProbe(Process::result(output: '{"format":{},"streams":[{"codec_type":"audio","avg_frame_rate":"0/0"}]}'));
        $metadata = FFMpeg::disk('local')->open(new File(__DIR__.'/src/guitar.m4a'))->probe();

        $this->assertNull($metadata->duration());
        $this->assertNull($metadata->video());
        $this->assertNull($metadata->audio()->width());
        $this->assertNull($metadata->audio()->frameRate());
    }

    #[Test]
    #[DataProvider('invalidMetadata')]
    public function it_rejects_invalid_probe_output_and_cleans_staging(string $output): void
    {
        Storage::disk('memory')->put('video.mp4', 'private media');
        $this->fakeProbe(Process::result(output: $output));

        try {
            FFMpeg::disk('memory')->open('video.mp4')->probe();
            $this->fail('Invalid metadata must not become an empty successful probe.');
        } catch (MediaException $exception) {
            $this->assertSame('metadata', $exception->category);
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    public static function invalidMetadata(): array
    {
        return [['{'], ['null'], ['{}'], ['{"format":{},"streams":[null]}']];
    }

    #[Test]
    public function it_does_not_launch_a_process_when_a_storage_read_fails(): void
    {
        $this->fakeProbe();
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('size')->with('missing.mp4')->andReturn(100);
        $disk->shouldReceive('readStream')->with('missing.mp4')->andReturn(false);

        try {
            FFMpeg::disk($disk)->open('missing.mp4')->probe();
            $this->fail('A failed read must not reach FFprobe.');
        } catch (MediaException $exception) {
            $this->assertSame('staging', $exception->phase);
            $this->assertSame('transfer', $exception->category);
            Process::assertNothingRan();
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    #[Test]
    public function it_rejects_a_truncated_transfer_before_launch(): void
    {
        $this->fakeProbe();
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, 'short');
        rewind($stream);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('size')->andReturn(100);
        $disk->shouldReceive('readStream')->andReturn($stream);

        try {
            FFMpeg::disk($disk)->open('truncated.mp4')->probe();
            $this->fail('A truncated transfer must not reach FFprobe.');
        } catch (MediaException $exception) {
            $this->assertSame('transfer', $exception->category);
            $this->assertFalse(is_resource($stream));
            Process::assertNothingRan();
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    #[Test]
    public function it_rejects_a_remote_manifest_instead_of_losing_its_dependencies(): void
    {
        $this->fakeProbe();

        try {
            FFMpeg::disk('memory')->open('playlist.m3u8')->probe();
            $this->fail('A manifest needs a separate dependency-staging contract.');
        } catch (MediaException $exception) {
            $this->assertSame('manifest', $exception->category);
            Process::assertNothingRan();
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    #[Test]
    public function it_detects_a_manifest_with_a_non_manifest_extension(): void
    {
        $this->fakeProbe();
        Storage::disk('memory')->put('input.bin', "#EXTM3U\n#EXTINF:6,\n../private.ts");

        try {
            FFMpeg::disk('memory')->open('input.bin')->probe();
            $this->fail('Renaming a manifest must not bypass dependency validation.');
        } catch (MediaException $exception) {
            $this->assertSame('manifest', $exception->category);
            Process::assertNothingRan();
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    #[Test]
    public function it_accepts_an_upload_without_using_its_untrusted_original_name(): void
    {
        $this->fakeProbe();
        $upload = new UploadedFile(__DIR__.'/src/video.mp4', '../../outside.mp4', test: true);

        $metadata = FFMpeg::disk('memory')->open($upload)->probe();

        $this->assertSame($upload->getPathname(), $metadata->get('format.filename'));
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function it_rejects_absolute_urls_and_escaping_keys(string $key, string $exception): void
    {
        $this->expectException($exception);

        FFMpeg::disk('local')->open($key);
    }

    public static function invalidKeys(): array
    {
        return [
            ['', \InvalidArgumentException::class],
            ['/etc/passwd', \InvalidArgumentException::class],
            ['../outside.mp4', \League\Flysystem\PathTraversalDetected::class],
            ['nested/../../outside.mp4', \League\Flysystem\PathTraversalDetected::class],
            ['https://example.com/video.mp4', \InvalidArgumentException::class],
            ['C:\\video.mp4', \InvalidArgumentException::class],
            ['\\\\server\\video.mp4', \InvalidArgumentException::class],
        ];
    }

    #[Test]
    public function it_sanitizes_a_launch_failure_and_releases_its_workspace(): void
    {
        Process::fake(function () {
            throw new \RuntimeException('Upstream private connection details');
        });

        try {
            FFMpeg::disk('local')->open(new File(__DIR__.'/src/video.mp4'))->probe();
            $this->fail('A launch failure must propagate without upstream details.');
        } catch (MediaException $exception) {
            $this->assertSame('process', $exception->category);
            $this->assertStringNotContainsString('Upstream private', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    #[Test]
    public function it_removes_application_secrets_from_the_real_child_environment(): void
    {
        putenv('NATIVE_PROBE_SECRET=do-not-inherit');

        try {
            $this->binary('echo json_encode(["format" => ["tags" => ["secret" => getenv("NATIVE_PROBE_SECRET"), "temporary" => getenv("TMPDIR") === getcwd()]], "streams" => []]);');
            $metadata = FFMpeg::disk('local')->open(new File(__DIR__.'/src/video.mp4'))->probe();

            $this->assertFalse($metadata->get('format.tags.secret'));
            $this->assertTrue($metadata->get('format.tags.temporary'));
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        } finally {
            putenv('NATIVE_PROBE_SECRET');
        }
    }

    #[Test]
    public function it_drains_noisy_children_and_keeps_only_a_diagnostic_tail(): void
    {
        $this->binary('for ($i = 0; $i < 512; $i++) { fwrite(STDERR, str_repeat("N", 65536)); } fwrite(STDERR, end($argv)." THE-END"); exit(7);');

        try {
            FFMpeg::disk('local')->open(new File(__DIR__.'/src/video.mp4'))->probe();
            $this->fail('A nonzero exit must fail.');
        } catch (MediaException $exception) {
            $this->assertSame('exit', $exception->category);
            $this->assertLessThanOrEqual(65536, strlen($exception->diagnostics));
            $this->assertStringEndsWith('[input] THE-END', $exception->diagnostics);
            $this->assertNull($exception->getPrevious());
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    #[Test]
    #[DataProvider('timeoutModes')]
    public function it_stops_and_reaps_a_timed_out_child_before_cleanup(string $setting): void
    {
        $pidFile = $this->root.'/pid';
        $this->binary('file_put_contents('.var_export($pidFile, true).', getmypid()); sleep(30);');
        config(['laravel-ffmpeg.'.$setting => 1]);

        try {
            FFMpeg::disk('local')->open(new File(__DIR__.'/src/video.mp4'))->probe();
            $this->fail('A silent child must time out.');
        } catch (MediaException $exception) {
            $this->assertSame('timeout', $exception->category);
            $this->assertFileExists($pidFile);
            $this->assertFalse(posix_kill((int) file_get_contents($pidFile), 0));
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    public static function timeoutModes(): array
    {
        return [['timeout'], ['idle_timeout']];
    }

    #[Test]
    public function it_caps_probe_output_and_reaps_the_child(): void
    {
        $pidFile = $this->root.'/pid';
        $this->binary('file_put_contents('.var_export($pidFile, true).', getmypid()); for ($i = 0; $i < 300; $i++) { echo str_repeat("X", 65536); } sleep(30);');

        try {
            FFMpeg::disk('local')->open(new File(__DIR__.'/src/video.mp4'))->probe();
            $this->fail('An oversized JSON response must fail.');
        } catch (MediaException $exception) {
            $this->assertSame('output_limit', $exception->category);
            $this->assertFalse(posix_kill((int) file_get_contents($pidFile), 0));
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    #[Test]
    public function it_requires_the_supported_ffprobe_release_line(): void
    {
        Process::fake(fn () => Process::result(output: 'ffprobe version 5.1.9'));

        $this->expectException(MediaException::class);
        $this->expectExceptionMessage('FFprobe 9.0.x is required.');

        FFMpeg::disk('local')->open('video.mp4')->probe();
    }

    #[Test]
    public function it_reads_real_media_through_local_and_non_local_storage(): void
    {
        $binary = getenv('FFMPEG_9_FFPROBE');

        if (! $binary) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE to a matching FFprobe 9.0.x binary for real-media acceptance.');
        }

        config(['laravel-ffmpeg.ffprobe.binaries' => $binary]);
        $this->fakeLocalVideoFile();
        $this->addTestFile('video.mp4', 'memory');

        foreach (['local', 'memory'] as $disk) {
            $metadata = FFMpeg::disk($disk)->open('video.mp4')->probe();
            $this->assertEqualsWithDelta(4.72, $metadata->duration(), 0.001);
            $this->assertSame(1920, $metadata->video()->width());
            $this->assertSame(1080, $metadata->video()->height());
            $this->assertSame('h264', $metadata->video()->get('codec_name'));
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }
}

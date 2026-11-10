<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Stream;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class ProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $this->root.'/local']]);
    }

    protected function metadataJson(): string
    {
        return json_encode([
            'format' => [
                'duration' => '12.375',
                'tags' => ['title' => 'Lecture', 'com.example.title' => 'Literal key'],
            ],
            'chapters' => [['start_time' => '1.250', 'end_time' => '7.500']],
            'streams' => [
                [
                    'index' => 0, 'codec_type' => 'video', 'disposition' => ['attached_pic' => 1],
                    'width' => 80, 'height' => 80,
                ],
                [
                    'index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 6,
                    'sample_rate' => '48000', 'bit_rate' => '192000',
                    'tags' => ['language' => 'fra', 'com.example.title' => 'Commentary'],
                    'disposition' => ['default' => 0],
                ],
                [
                    'index' => 2, 'codec_type' => 'video', 'width' => 854, 'height' => 480,
                    'avg_frame_rate' => '30000/1001', 'sample_aspect_ratio' => '4:3', 'color_transfer' => 'smpte2084',
                ],
                [
                    'index' => 3, 'codec_type' => 'audio', 'tags' => ['language' => 'eng'],
                    'disposition' => ['default' => 1],
                ],
                [
                    'index' => 4, 'codec_type' => 'subtitle', 'codec_name' => 'subrip', 'tags' => ['language' => 'eng'],
                    'disposition' => ['default' => 0, 'forced' => 1],
                ],
                [
                    'index' => 5, 'codec_type' => 'subtitle', 'codec_name' => 'ass', 'tags' => ['language' => 'fra'],
                    'disposition' => ['default' => 1, 'forced' => 0],
                ],
            ],
        ]);
    }

    protected function fakeProbe(mixed $response = null, ?callable $inspect = null): void
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

    protected function binary(string $body): string
    {
        $path = $this->root.'/ffprobe';
        file_put_contents($path, '#!'.PHP_BINARY."\n<?php\n".
            'if (in_array("-version", $argv, true)) { echo "ffprobe version 9.0.2\n"; exit; }'."\n".$body);
        chmod($path, 0700);
        config(['laravel-ffmpeg.ffprobe.binaries' => $path]);

        return $path;
    }

    public function test_disk_defaults_to_the_current_configuration_without_changing_existing_descriptors(): void
    {
        $local = Storage::disk('local');
        $local->put('source.mp4', 'local source');
        Storage::disk('memory')->put('source.mp4', 'memory source');
        $sources = [];
        $this->fakeProbe(inspect: function (PendingProcess $process) use (&$sources) {
            $sources[] = file_get_contents(end($process->command));
        });

        config(['filesystems.default' => 'memory']);
        $factory = FFMpeg::getFacadeRoot();
        $memory = FFMpeg::open('source.mp4');
        config(['filesystems.default' => 'local']);
        FFMpeg::disk('other');
        $local = FFMpeg::open('source.mp4', inputOptions: ['-probesize', '2048']);
        $explicit = FFMpeg::disk('memory')->open('source.mp4');

        $this->assertSame($factory, FFMpeg::getFacadeRoot());
        $this->assertSame('memory', $memory->defaultOutputDisk());
        $this->assertSame('local', $local->defaultOutputDisk());
        $this->assertSame('memory', $explicit->defaultOutputDisk());
        $this->assertContains('2048', $local->export()->inspect());
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();

        foreach ([$memory, $local, $explicit] as $media) {
            $this->assertSame(12.375, $media->probe()->duration());
        }

        $this->assertSame(['memory source', 'local source', 'memory source'], $sources);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_it_probes_a_local_input_without_mutating_or_staging_it(): void
    {
        $disk = Storage::disk('local');
        $key = 'nested/lecture ; $HOME.mp4';
        $disk->put($key, 'source bytes');
        $seen = null;
        $this->fakeProbe(inspect: function (PendingProcess $process) use (&$seen) {
            $seen = [$process->command, glob($process->path.'/*')];
        });

        $media = FFMpeg::disk('local')->open($key);

        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();

        $metadata = $media->probe();

        $this->assertSame([[
            'ffprobe', '-v', 'error', '-print_format', 'json',
            '-show_format', '-show_streams', '-show_chapters', '-protocol_whitelist', 'file', $disk->path($key),
        ], []], $seen);

        $this->assertSame(12.375, $metadata->duration());
        $this->assertSame($key, $metadata->toArray()['format']['filename']);
        $this->assertSame('source bytes', $disk->get($key));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_probe_results_expose_streams_tags_and_chapters(): void
    {
        Storage::disk('local')->put('lecture.mp4', 'source bytes');
        $this->fakeProbe();

        $metadata = FFMpeg::disk('local')
            ->open('lecture.mp4')
            ->probe();

        $this->assertCount(6, $metadata->streams());
        $this->assertCount(1, $metadata->videoStreams());
        $this->assertSame(854, $metadata->video()->width());
        $this->assertSame(480, $metadata->video()->height());
        $this->assertSame('30000/1001', $metadata->video()->get('avg_frame_rate'));
        $this->assertEqualsWithDelta(29.97003, $metadata->video()->frameRate(), 0.00001);
        $this->assertSame('smpte2084', $metadata->video()->get('color_transfer'));
        $this->assertSame('fra', $metadata->audio()->get('tags.language'));
        $this->assertSame('1.250', $metadata->get('chapters.0.start_time'));
        $this->assertSame('Lecture', $metadata->tag('title'));
        $this->assertSame('Literal key', $metadata->tag('com.example.title'));
        $this->assertNull($metadata->tag('TITLE'));
        $this->assertSame([['start_time' => '1.250', 'end_time' => '7.500']], $metadata->chapters()->all());
        $this->assertTrue($metadata->hasVideo());
        $this->assertTrue($metadata->hasAudio());
        $this->assertSame('audio', $metadata->audio()->type());
        $this->assertSame('aac', $metadata->audio()->codec());
        $this->assertSame('fra', $metadata->audio()->language());
        $this->assertSame('Commentary', $metadata->audio()->tag('com.example.title'));
        $this->assertNull($metadata->audio()->tag('LANGUAGE'));
        $this->assertSame(6, $metadata->audio()->channels());
        $this->assertSame(48000, $metadata->audio()->sampleRate());
        $this->assertSame(192000, $metadata->audio()->bitrate());
        $this->assertFalse($metadata->audio()->isDefault());
        $this->assertTrue($metadata->audioStreams()->last()->isDefault());
        $this->assertSame([4, 5], $metadata->subtitleStreams()->map->index()->all());
        $this->assertSame('subrip', $metadata->subtitle()->codec());
        $this->assertTrue($metadata->subtitle()->isForced());
        $this->assertFalse($metadata->subtitle()->isDefault());
        $this->assertFalse($metadata->subtitleStreams()->last()->isForced());
        $this->assertTrue($metadata->subtitleStreams()->last()->isDefault());
        $this->assertSame([0], $metadata->coverArt()->map->index()->all());
        $this->assertTrue($metadata->coverArt()->first()->isCoverArt());
        $this->assertFalse($metadata->video()->isCoverArt());
        $this->assertSame('fallback', $metadata->get('missing', 'fallback'));
    }

    public function test_it_stages_non_local_disks_and_supplied_filesystems_privately(): void
    {
        $disk = Storage::disk('memory');
        $disk->put('private/video.mp4', 'private media');
        $staged = [];
        $this->fakeProbe(inspect: function (PendingProcess $process) use (&$staged) {
            $path = end($process->command);
            $staged[] = [file_get_contents($path), fileperms(dirname($path)) & 0777, fileperms($path) & 0777];
        });

        $this->assertSame(12.375, FFMpeg::disk('memory')->open('private/video.mp4')->probe()->duration());
        $this->assertSame(12.375, FFMpeg::disk($disk)->open('private/video.mp4')->probe()->duration());
        $this->assertSame(array_fill(0, 2, ['private media', 0700, 0600]), $staged);
        $this->assertWorkspacesAreCleanedUp();
        $this->assertSame('private media', $disk->get('private/video.mp4'));
    }

    public function test_it_preserves_unknown_values_and_handles_audio_only_media(): void
    {
        $json = '{"format":{},"streams":[{"codec_type":"audio","avg_frame_rate":"0/0"}]}';
        $this->fakeProbe(Process::result(output: $json));
        $media = FFMpeg::open(new File(__DIR__.'/src/guitar.m4a'));
        $media->export()
            ->inspect();

        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();

        $metadata = $media->probe();

        $this->assertNull($metadata->duration());
        $this->assertNull($metadata->video());
        $this->assertNull($metadata->audio()->width());
        $this->assertNull($metadata->audio()->frameRate());
        $this->assertTrue($metadata->hasAudio());
        $this->assertFalse($metadata->hasVideo());
        $this->assertNull($metadata->subtitle());
        $this->assertTrue($metadata->subtitleStreams()->isEmpty());
        $this->assertTrue($metadata->coverArt()->isEmpty());
        $this->assertTrue($metadata->chapters()->isEmpty());
        $this->assertNull($metadata->tag('title'));
        $this->assertNull($metadata->audio()->codec());
        $this->assertNull($metadata->audio()->language());
        $this->assertNull($metadata->audio()->channels());
        $this->assertNull($metadata->audio()->sampleRate());
        $this->assertNull($metadata->audio()->bitrate());
        $this->assertFalse($metadata->audio()->isDefault());
        $this->assertFalse($metadata->audio()->isForced());
        $this->assertFalse($metadata->audio()->isCoverArt());
    }

    public function test_typed_probe_accessors_keep_cover_art_separate(): void
    {
        $stream = ['codec_type' => 'video', 'disposition' => ['attached_pic' => 1]];
        $this->fakeProbe(Process::result(output: json_encode(['format' => [], 'streams' => [$stream]])));
        $metadata = FFMpeg::disk('local')
            ->open(new File(__DIR__.'/src/guitar.m4a'))
            ->probe();

        $this->assertFalse($metadata->hasVideo());
        $this->assertFalse($metadata->hasAudio());
        $this->assertCount(1, $metadata->coverArt());
        $this->assertSame($stream, $metadata->coverArt()->first()->toArray());
    }

    #[DataProvider('unknownNumberProvider')]
    public function test_typed_probe_accessors_do_not_coerce_unknown_values(mixed $unknown): void
    {
        $stream = new Stream([
            'channels' => $unknown, 'sample_rate' => $unknown, 'bit_rate' => $unknown,
        ]);

        $this->assertNull($stream->channels());
        $this->assertNull($stream->sampleRate());
        $this->assertNull($stream->bitrate());
        $this->assertSame($unknown, $stream->get('bit_rate'));
    }

    public static function unknownNumberProvider(): array
    {
        return [
            'null' => [null],
            'not available' => ['N/A'],
            'zero' => [0],
            'negative' => [-1],
            'fraction' => ['1.5'],
            'exponent' => ['1e3'],
            'overflowing integer' => [(string) PHP_INT_MAX.'0'],
            'boolean' => [true],
            'array' => [[]],
        ];
    }

    #[DataProvider('invalidMetadata')]
    public function test_it_rejects_invalid_probe_output_and_cleans_staging(string $output): void
    {
        Storage::disk('memory')->put('video.mp4', 'private media');
        $this->fakeProbe(Process::result(output: $output));

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('video.mp4')->probe(),
            fn (MediaException $exception) => $exception->category === 'metadata',
        );

        $this->assertWorkspacesAreCleanedUp();
    }

    public static function invalidMetadata(): array
    {
        return [['{'], ['null'], ['{}'], ['{"format":{},"streams":[null]}']];
    }

    public function test_it_does_not_launch_a_process_when_a_storage_read_fails(): void
    {
        $this->fakeProbe();
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('size')
            ->with('missing.mp4')
            ->andReturn(100);
        $disk->shouldReceive('readStream')
            ->with('missing.mp4')
            ->andReturn(false);

        $this->assertThrows(
            fn () => FFMpeg::disk($disk)->open('missing.mp4')->probe(),
            fn (MediaException $exception) => $exception->phase === 'staging' && $exception->category === 'transfer',
        );

        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_it_rejects_a_truncated_transfer_before_launch(): void
    {
        $this->fakeProbe();
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, 'short');
        rewind($stream);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('size')
            ->andReturn(100);
        $disk->shouldReceive('readStream')
            ->andReturn($stream);

        $this->assertThrows(
            fn () => FFMpeg::disk($disk)->open('truncated.mp4')->probe(),
            fn (MediaException $exception) => $exception->category === 'transfer',
        );

        $this->assertFalse(is_resource($stream));
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_it_rejects_a_remote_manifest_instead_of_losing_its_dependencies(): void
    {
        $this->fakeProbe();

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('playlist.m3u8')->probe(),
            fn (MediaException $exception) => $exception->category === 'manifest',
        );

        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_it_detects_a_manifest_with_a_non_manifest_extension(): void
    {
        $this->fakeProbe();
        Storage::disk('memory')->put('input.bin', <<<'M3U8'
            #EXTM3U
            #EXTINF:6,
            ../private.ts
            M3U8);

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')->open('input.bin')->probe(),
            fn (MediaException $exception) => $exception->category === 'manifest',
        );

        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_it_accepts_an_upload_without_using_its_untrusted_original_name(): void
    {
        $this->fakeProbe();
        $upload = new UploadedFile(__DIR__.'/src/video.mp4', '../../outside.mp4', test: true);

        FFMpeg::disk('other');
        $media = FFMpeg::open($upload, inputOptions: ['-probesize', '2048']);
        $command = $media->export()
            ->inspect();

        $this->assertContains('2048', $command);
        $this->assertStringNotContainsString('outside.mp4', json_encode($command));
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();

        $metadata = $media->probe();

        $this->assertSame($upload->getPathname(), $metadata->get('format.filename'));
        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('invalidKeys')]
    public function test_it_rejects_absolute_urls_and_escaping_keys(string $key, string $exception): void
    {
        $this->expectException($exception);

        FFMpeg::open($key);
    }

    public static function invalidKeys(): array
    {
        return [
            ['', \InvalidArgumentException::class],
            ['/etc/passwd', \InvalidArgumentException::class],
            ['../outside.mp4', PathTraversalDetected::class],
            ['nested/../../outside.mp4', PathTraversalDetected::class],
            ['https://example.com/video.mp4', \InvalidArgumentException::class],
            ['C:\\video.mp4', \InvalidArgumentException::class],
            ['\\\\server\\video.mp4', \InvalidArgumentException::class],
        ];
    }

    public function test_it_sanitizes_a_launch_failure_and_releases_its_workspace(): void
    {
        Process::fake(function () {
            throw new \RuntimeException('Upstream private connection details');
        });

        $this->assertThrows(
            fn () => FFMpeg::disk('local')->open(new File(__DIR__.'/src/video.mp4'))->probe(),
            fn (MediaException $exception) => $exception->category === 'process'
                && ! str_contains($exception->getMessage(), 'Upstream private')
                && $exception->getPrevious() === null,
        );

        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_it_removes_application_secrets_from_the_real_child_environment(): void
    {
        putenv('NATIVE_PROBE_SECRET=do-not-inherit');

        try {
            $this->binary('echo json_encode(["format" => ["tags" => ['
                .'"secret" => getenv("NATIVE_PROBE_SECRET"), "temporary" => getenv("TMPDIR") === getcwd()'
                .']], "streams" => []]);');
            $metadata = FFMpeg::disk('local')
                ->open(new File(__DIR__.'/src/video.mp4'))
                ->probe();

            $this->assertFalse($metadata->get('format.tags.secret'));
            $this->assertTrue($metadata->get('format.tags.temporary'));
            $this->assertWorkspacesAreCleanedUp();
        } finally {
            putenv('NATIVE_PROBE_SECRET');
        }
    }

    public function test_it_drains_noisy_children_and_keeps_only_a_diagnostic_tail(): void
    {
        $this->binary('for ($i = 0; $i < 512; $i++) { fwrite(STDERR, str_repeat("N", 65536)); } '
            .'fwrite(STDERR, end($argv)." THE-END"); exit(7);');

        $this->assertThrows(
            fn () => FFMpeg::disk('local')->open(new File(__DIR__.'/src/video.mp4'))->probe(),
            fn (MediaException $exception) => $exception->category === 'exit'
                && strlen($exception->diagnostics) <= 65536
                && str_ends_with($exception->diagnostics, '[input] THE-END')
                && $exception->getPrevious() === null,
        );

        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('timeoutModes')]
    public function test_it_stops_and_reaps_a_timed_out_child_before_cleanup(string $setting): void
    {
        $pidFile = $this->root.'/pid';
        $this->binary('file_put_contents('.var_export($pidFile, true).', getmypid()); sleep(30);');
        config(['laravel-ffmpeg.'.$setting => 1]);

        $this->assertThrows(
            fn () => FFMpeg::disk('local')->open(new File(__DIR__.'/src/video.mp4'))->probe(),
            fn (MediaException $exception) => $exception->category === 'timeout',
        );

        $this->assertFileExists($pidFile);
        $this->assertFalse(posix_kill((int) file_get_contents($pidFile), 0));
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function timeoutModes(): array
    {
        return [['timeout'], ['idle_timeout']];
    }

    public function test_it_caps_probe_output_and_reaps_the_child(): void
    {
        $pidFile = $this->root.'/pid';
        $this->binary('file_put_contents('.var_export($pidFile, true).', getmypid()); '
            .'for ($i = 0; $i < 300; $i++) { echo str_repeat("X", 65536); } sleep(30);');

        $this->assertThrows(
            fn () => FFMpeg::disk('local')->open(new File(__DIR__.'/src/video.mp4'))->probe(),
            fn (MediaException $exception) => $exception->category === 'output_limit',
        );

        $this->assertFalse(posix_kill((int) file_get_contents($pidFile), 0));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_it_requires_the_supported_ffprobe_release_line(): void
    {
        Process::fake(fn () => Process::result(output: 'ffprobe version 5.1.9'));
        Storage::disk('memory')->put('video.mp4', 'media');

        $this->expectException(MediaException::class);
        $this->expectExceptionMessage('FFprobe 9.0.x is required.');

        FFMpeg::disk('memory')
            ->open('video.mp4')
            ->probe();
    }

    #[TestWith(['local'])]
    #[TestWith(['memory'])]
    public function test_it_reads_real_media_through_local_and_non_local_storage(string $disk): void
    {
        $this->useRealFFMpeg();
        $this->addTestFile('video.mp4', $disk);
        config(['filesystems.default' => $disk]);

        $metadata = FFMpeg::open('video.mp4')->probe();

        $this->assertEqualsWithDelta(4.72, $metadata->duration(), 0.001);
        $this->assertSame(1920, $metadata->video()->width());
        $this->assertSame(1080, $metadata->video()->height());
        $this->assertSame('h264', $metadata->video()->codec());
        $this->assertSame('video', $metadata->video()->type());
        $this->assertTrue($metadata->hasVideo());
        $this->assertWorkspacesAreCleanedUp();
    }
}

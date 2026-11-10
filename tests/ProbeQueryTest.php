<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\PendingProbe;
use ProtoneMedia\LaravelFFMpeg\ProbeResult;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class ProbeQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.disks.query' => ['driver' => 'local', 'root' => $this->root.'/media']]);
        Storage::disk('memory')->put('source.mp4', 'source');
    }

    protected function pendingQuery(): PendingProbe
    {
        return FFMpeg::disk('memory')->open('source.mp4')->probeQuery();
    }

    protected function fakeProbe(callable $response): void
    {
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($response) {
            if (in_array('-version', $process->command, true)) {
                return Process::result(output: 'ffprobe version 9.0.2');
            }

            return $response($process);
        });
    }

    public function test_unsupported_ffprobe_versions_are_rejected_before_querying(): void
    {
        Process::preventStrayProcesses();
        Process::fake(fn (PendingProcess $process) => in_array('-version', $process->command, true)
            ? Process::result(output: 'ffprobe version 5.1.9') : throw new \LogicException('Only the version check may run.'));
        $this->assertThrows(
            fn () => $this->pendingQuery()->get(),
            fn (MediaException $exception) => [$exception->phase, $exception->category] === ['probing', 'version'],
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_queries_run_lazily_and_return_raw_sections(): void
    {
        $this->fakeProbe(function (PendingProcess $process) {
            $command = $process->command;
            $this->assertSame('0%+10', $command[array_search('-read_intervals', $command, true) + 1]);
            $this->assertSame('file', $command[array_search('-protocol_whitelist', $command, true) + 1]);
            $this->assertSame('source', file_get_contents(end($command)));

            return Process::result(output: json_encode(['streams' => [['index' => 0, 'nb_read_frames' => '12']],
                'frames' => [['pts_time' => '0.040', 'key_frame' => 1]], 'format' => ['filename' => end($command)]]));
        });
        $query = $this->pendingQuery()->selectStreams('v:0')->keyframes()->countFrames()
            ->entries(['stream' => ['index', 'nb_read_frames'], 'frame' => ['pts_time', 'key_frame'], 'format' => ['filename']]);
        $this->assertInstanceOf(PendingProbe::class, $query);
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
        $result = $query->get();
        $this->assertInstanceOf(ProbeResult::class, $result);
        $this->assertSame('12', $result->streams()->first()['nb_read_frames']);
        $this->assertSame('0.040', $result->frames()->first()['pts_time']);
        $this->assertTrue($result->packets()->isEmpty());
        $this->assertSame('[input]', $result->get('format.filename'));
        $this->assertSame($result->toArray(), json_decode(json_encode($result), true));
        $this->assertSame(10.0, $result->bounds()['duration']);
        $this->assertWorkspacesAreCleanedUp();
        Process::assertRan(fn (PendingProcess $process) => in_array('-skip_frame', $process->command, true)
            && in_array('-count_frames', $process->command, true) && in_array('v:0', $process->command, true));
    }

    public function test_packet_and_time_bounds_replace_each_other(): void
    {
        $intervals = [];
        $this->fakeProbe(function (PendingProcess $process) use (&$intervals) {
            $intervals[] = $process->command[array_search('-read_intervals', $process->command, true) + 1];

            return Process::result(output: '{"packets":[],"streams":[]}');
        });
        $query = $this->pendingQuery()->packets()->countPackets()->readInterval(1.25, 2.5)->readPackets(7, 3.5);
        $this->assertSame(7, $query->get()->bounds()['packets']);
        $this->assertNull($query->readInterval(0, 1)->get()->bounds()['packets']);
        $query->readInterval(0.000001, 0.000001)->get();
        $this->assertSame(['3.5%+#7', '0%+1', '0.000001%+0.000001'], $intervals);
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_unbounded_queries_are_rejected_before_any_process(Closure $configure): void
    {
        Process::fake();
        $this->assertThrows(fn () => $configure($this->pendingQuery()), InvalidArgumentException::class);
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function invalidQueryProvider(): array
    {
        return [
            'infinite interval' => [fn (PendingProbe $query) => $query->readInterval(0, INF)],
            'interval start not a number' => [fn (PendingProbe $query) => $query->readInterval(NAN, 1)],
            'interval too long' => [fn (PendingProbe $query) => $query->readInterval(0, 301)],
            'no packets' => [fn (PendingProbe $query) => $query->readPackets(0)],
            'too many packets' => [fn (PendingProbe $query) => $query->readPackets(100001)],
            'option in stream specifier' => [fn (PendingProbe $query) => $query->selectStreams('v:0 -i evil')],
            'nested entry section' => [fn (PendingProbe $query) => $query->entries(['packet' => ['pts:stream=codec_name']])],
            'unsupported entry section' => [fn (PendingProbe $query) => $query->entries(['program' => ['id']])],
            'no entries' => [fn (PendingProbe $query) => $query->entries([])],
            'output limit too high' => [fn (PendingProbe $query) => $query->limits(16777217)],
            'timeout too long' => [fn (PendingProbe $query) => $query->limits(timeout: 121)],
            'timeout not a number' => [fn (PendingProbe $query) => $query->limits(timeout: NAN)],
        ];
    }

    #[DataProvider('malformedOutputProvider')]
    public function test_malformed_query_output_is_rejected(string $output): void
    {
        $this->fakeProbe(fn () => Process::result(output: $output));
        $this->assertThrows(
            fn () => $this->pendingQuery()->frames()->get(),
            fn (MediaException $exception) => $exception->category === 'metadata',
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function malformedOutputProvider(): array
    {
        return [
            'not json' => ['not json'],
            'list' => ['[]'],
            'scalar frame' => ['{"frames":[1]}'],
            'null section' => ['{"frames":null}'],
        ];
    }

    public function test_failed_queries_are_sanitized(): void
    {
        $this->fakeProbe(fn (PendingProcess $process) => Process::result(exitCode: 1, errorOutput: end($process->command)));
        $this->assertThrows(
            fn () => $this->pendingQuery()->get(),
            fn (MediaException $exception) => $exception->category === 'exit'
                && trim($exception->diagnostics) === '[input]',
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_url_queries_redact_the_decoded_json(): void
    {
        config(['laravel-ffmpeg.redact_credentials' => true]);
        $url = 'https://example.test/video.mp4?token=private-value';
        $this->fakeProbe(function (PendingProcess $process) use ($url) {
            if (in_array('-protocols', $process->command, true)) {
                return Process::result(output: "Input:\n https\n tls\n tcp\nOutput:\n file\n");
            }
            $command = $process->command;
            $this->assertSame('https,tls,tcp', $command[array_search('-protocol_whitelist', $command, true) + 1]);
            $this->assertContains('-tls_verify', $command);

            return Process::result(output: json_encode(['format' => ['filename' => $url,
                'tags' => ['comment' => 'private-value', 'purl' => $url]], 'streams' => []]));
        });
        $result = FFMpeg::openUrl($url)->probeQuery()->entries(['format' => ['filename'], 'format_tags' => ['comment', 'purl']])->get();
        $this->assertSame('[input]', $result->get('format.filename'));
        $this->assertSame('[redacted]', $result->get('format.tags.comment'));
        $this->assertStringNotContainsString('private-value', json_encode($result));
        // probe() applies the same rules to its decoded data.
        $metadata = FFMpeg::openUrl($url)->probe();
        $this->assertSame(['comment' => '[redacted]', 'purl' => '[input]'], $metadata->get('format.tags'));
        $this->assertWorkspacesAreCleanedUp();
    }

    #[TestWith(['echo str_repeat("X", 100000);', 'output_limit'])]
    #[TestWith(['usleep(400000); echo "{}";', 'timeout'])]
    public function test_resource_limits_are_enforced_on_real_processes(string $body, string $category): void
    {
        $binary = $this->root.'/probe';
        file_put_contents($binary, sprintf(<<<'PHP'
            #!%s
            <?php
            if (in_array("-version", $argv, true)) { echo "ffprobe version 9.0.2"; exit; }%s
            PHP, PHP_BINARY, $body));
        chmod($binary, 0700);
        config(['laravel-ffmpeg.ffprobe.binaries' => $binary]);
        $this->assertThrows(
            fn () => $this->pendingQuery()->limits(1024, 0.15)->get(),
            fn (MediaException $exception) => $exception->category === $category
                && ! str_contains($exception->getMessage(), $this->root),
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_frame_queries_keep_native_timestamps(): void
    {
        $frames = $this->frameQuery($this->vfrMedia());
        $this->assertGreaterThan(20, $frames->frames()->count());
        $this->assertContains('B', $frames->frames()->pluck('pict_type')->all());
        $times = $frames->frames()->pluck('pts_time')->map(fn ($time) => (float) $time)->all();
        $deltas = [];
        for ($i = 1; $i < count($times); $i++) {
            $deltas[] = round($times[$i] - $times[$i - 1], 3);
        }
        $this->assertGreaterThan(1, count(array_unique($deltas)));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_packet_queries_read_the_requested_number_of_packets(): void
    {
        $packets = $this->vfrMedia()->probeQuery()->selectStreams('v:0')->packets()->readPackets(7)
            ->entries(['packet' => ['pts_time', 'dts_time', 'flags'], 'stream' => ['index']])->get();
        $this->assertCount(7, $packets->packets());
        $this->assertTrue($packets->packets()->contains(fn ($packet) => $packet['pts_time'] !== $packet['dts_time']));
        Storage::disk('memory')->put('vfr.mp4', Storage::disk('query')->get('vfr.mp4'));
        $this->assertCount(3, FFMpeg::disk('memory')->open('vfr.mp4')->probeQuery()->packets()->readPackets(3)->get()->packets());
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_keyframe_queries_return_only_keyframes(): void
    {
        $media = $this->vfrMedia();
        $keys = $media->probeQuery()->selectStreams('v:0')->keyframes()->readInterval(0, 4)
            ->entries(['frame' => ['key_frame', 'pts_time']])->get();
        $this->assertGreaterThan(0, $keys->frames()->count());
        $this->assertLessThan($this->frameQuery($media)->frames()->count(), $keys->frames()->count());
        $this->assertTrue($keys->frames()->every(fn ($frame) => $frame['key_frame'] === 1));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_count_queries_match_the_decoded_frames(): void
    {
        $media = $this->vfrMedia();
        $counts = $media->probeQuery()->selectStreams('v:0')->countPackets()->countFrames()->readInterval(0, 4)
            ->entries(['stream' => ['nb_read_frames', 'nb_read_packets']])->get();
        $frames = $this->frameQuery($media)->frames()->count();
        $this->assertSame($frames, (int) $counts->get('streams.0.nb_read_frames'));
        $this->assertSame($frames, (int) $counts->get('streams.0.nb_read_packets'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_interval_queries_read_part_of_the_stream(): void
    {
        $media = $this->vfrMedia();
        $partial = $media->probeQuery()->selectStreams('v:0')->packets()->readInterval(1, 0.25)->get();
        $this->assertGreaterThan(0, $partial->packets()->count());
        $this->assertLessThan($this->frameQuery($media)->frames()->count(), $partial->packets()->count());
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Write a variable frame rate video with B-frames to the query disk.
     */
    protected function vfrMedia(): Media
    {
        $ffmpeg = $this->useRealFFMpeg();
        $disk = Storage::disk('query');
        $disk->put('.keep', '');
        $generated = Process::run([$ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=s=64x48:r=24:d=3',
            '-vf', 'select=if(lt(n\,12)\,1\,not(mod(n\,3)))', '-fps_mode', 'vfr', '-c:v', 'mpeg4', '-bf', '2', '-g', '12',
            '-q:v', '3', $disk->path('vfr.mp4')]);
        $this->assertProcessSucceeded($generated);

        return FFMpeg::disk('query')->open('vfr.mp4');
    }

    /**
     * Query every frame of the first four seconds of the given media's first video stream.
     */
    protected function frameQuery(Media $media): ProbeResult
    {
        return $media->probeQuery()->selectStreams('v:0')->frames()->readInterval(0, 4)
            ->entries(['frame' => ['pts_time', 'key_frame', 'pict_type'], 'stream' => ['index']])->get();
    }
}

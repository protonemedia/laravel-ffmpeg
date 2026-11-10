<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
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
        try {
            $this->pendingQuery()->get();
            $this->fail('An unsupported FFprobe must be rejected.');
        } catch (MediaException $exception) {
            $this->assertSame(['probing', 'version'], [$exception->phase, $exception->category]);
        }
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    public function test_public_query_is_lazy_bounded_and_returns_separate_raw_sections(): void
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
        $this->assertSame([], glob($this->root.'/workspaces/*'));
        $result = $query->get();
        $this->assertInstanceOf(ProbeResult::class, $result);
        $this->assertSame('12', $result->streams()->first()['nb_read_frames']);
        $this->assertSame('0.040', $result->frames()->first()['pts_time']);
        $this->assertTrue($result->packets()->isEmpty());
        $this->assertSame('[input]', $result->get('format.filename'));
        $this->assertSame($result->toArray(), json_decode(json_encode($result), true));
        $this->assertSame(10.0, $result->bounds()['duration']);
        $this->assertSame([], glob($this->root.'/workspaces/*'));
        Process::assertRan(fn (PendingProcess $process) => in_array('-skip_frame', $process->command, true)
            && in_array('-count_frames', $process->command, true) && in_array('v:0', $process->command, true));
    }

    public function test_packet_and_time_bounds_are_typed_and_mutually_replace_each_other(): void
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

    public function test_invalid_or_unbounded_requests_are_rejected_before_any_process(): void
    {
        Process::fake();
        foreach ([fn () => $this->pendingQuery()->readInterval(0, INF), fn () => $this->pendingQuery()->readInterval(NAN, 1),
            fn () => $this->pendingQuery()->readInterval(0, 301), fn () => $this->pendingQuery()->readPackets(0),
            fn () => $this->pendingQuery()->readPackets(100001), fn () => $this->pendingQuery()->selectStreams('v:0 -i evil'),
            fn () => $this->pendingQuery()->entries(['packet' => ['pts:stream=codec_name']]),
            fn () => $this->pendingQuery()->entries(['program' => ['id']]), fn () => $this->pendingQuery()->entries([]),
            fn () => $this->pendingQuery()->limits(16777217), fn () => $this->pendingQuery()->limits(timeout: 121),
            fn () => $this->pendingQuery()->limits(timeout: NAN)] as $invalid) {
            try {
                $invalid();
                $this->fail('Expected invalid query rejection.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        Process::assertNothingRan();
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    public function test_malformed_and_failed_queries_are_sanitized_and_cleaned(): void
    {
        foreach (['not json', '[]', '{"frames":[1]}', '{"frames":null}'] as $output) {
            $this->fakeProbe(fn () => Process::result(output: $output));
            try {
                $this->pendingQuery()->frames()->get();
                $this->fail('Expected invalid JSON sections rejection.');
            } catch (MediaException $exception) {
                $this->assertSame('metadata', $exception->category);
            }
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
        $this->fakeProbe(fn (PendingProcess $process) => Process::result(exitCode: 1, errorOutput: end($process->command)));
        try {
            $this->pendingQuery()->get();
            $this->fail('Expected failed process rejection.');
        } catch (MediaException $exception) {
            $this->assertSame('exit', $exception->category);
            $this->assertSame('[input]', trim($exception->diagnostics));
        }
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    public function test_url_queries_use_the_source_protocol_policy_and_redact_decoded_json(): void
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
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    public function test_real_process_output_and_total_timeout_caps_are_enforced(): void
    {
        $binary = $this->root.'/probe';
        foreach (['echo str_repeat("X", 100000);' => 'output_limit', 'usleep(400000); echo "{}";' => 'timeout'] as $body => $category) {
            file_put_contents($binary, '#!'.PHP_BINARY."\n<?php\n".
                'if (in_array("-version", $argv, true)) { echo "ffprobe version 9.0.2"; exit; }'.$body);
            chmod($binary, 0700);
            config(['laravel-ffmpeg.ffprobe.binaries' => $binary]);
            try {
                $this->pendingQuery()->limits(1024, 0.15)->get();
                $this->fail('Expected query resource limit.');
            } catch (MediaException $exception) {
                $this->assertSame($category, $exception->category);
                $this->assertStringNotContainsString($this->root, $exception->getMessage());
            }
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    public function test_real_vfr_b_frames_keyframes_intervals_and_counts_preserve_native_timestamps(): void
    {
        $ffmpeg = $this->useRealFFMpeg();
        $disk = Storage::disk('query');
        $disk->put('.keep', '');
        $generated = Process::run([$ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=s=64x48:r=24:d=3',
            '-vf', 'select=if(lt(n\,12)\,1\,not(mod(n\,3)))', '-fps_mode', 'vfr', '-c:v', 'mpeg4', '-bf', '2', '-g', '12',
            '-q:v', '3', $disk->path('vfr.mp4')]);
        $this->assertTrue($generated->successful(), $generated->errorOutput());
        $media = FFMpeg::disk('query')->open('vfr.mp4');
        $frames = $media->probeQuery()->selectStreams('v:0')->frames()->readInterval(0, 4)
            ->entries(['frame' => ['pts_time', 'key_frame', 'pict_type'], 'stream' => ['index']])->get();
        $this->assertGreaterThan(20, $frames->frames()->count());
        $this->assertContains('B', $frames->frames()->pluck('pict_type')->all());
        $times = $frames->frames()->pluck('pts_time')->map(fn ($time) => (float) $time)->all();
        $deltas = [];
        for ($i = 1; $i < count($times); $i++) {
            $deltas[] = round($times[$i] - $times[$i - 1], 3);
        }
        $this->assertGreaterThan(1, count(array_unique($deltas)));
        $packets = $media->probeQuery()->selectStreams('v:0')->packets()->readPackets(7)
            ->entries(['packet' => ['pts_time', 'dts_time', 'flags'], 'stream' => ['index']])->get();
        $this->assertCount(7, $packets->packets());
        $this->assertTrue($packets->packets()->contains(fn ($packet) => $packet['pts_time'] !== $packet['dts_time']));
        $keys = $media->probeQuery()->selectStreams('v:0')->keyframes()->readInterval(0, 4)
            ->entries(['frame' => ['key_frame', 'pts_time']])->get();
        $this->assertGreaterThan(0, $keys->frames()->count());
        $this->assertLessThan($frames->frames()->count(), $keys->frames()->count());
        $this->assertTrue($keys->frames()->every(fn ($frame) => $frame['key_frame'] === 1));
        $counts = $media->probeQuery()->selectStreams('v:0')->countPackets()->countFrames()->readInterval(0, 4)
            ->entries(['stream' => ['nb_read_frames', 'nb_read_packets']])->get();
        $this->assertSame($frames->frames()->count(), (int) $counts->get('streams.0.nb_read_frames'));
        $this->assertSame($frames->frames()->count(), (int) $counts->get('streams.0.nb_read_packets'));
        $partial = $media->probeQuery()->selectStreams('v:0')->packets()->readInterval(1, 0.25)->get();
        $this->assertGreaterThan(0, $partial->packets()->count());
        $this->assertLessThan($frames->frames()->count(), $partial->packets()->count());
        Storage::disk('memory')->put('vfr.mp4', $disk->get('vfr.mp4'));
        $this->assertCount(3, FFMpeg::disk('memory')->open('vfr.mp4')->probeQuery()->packets()->readPackets(3)->get()->packets());
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }
}

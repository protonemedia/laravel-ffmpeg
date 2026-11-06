<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Events;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\ExportOutput;
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class NativeCompositionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/native-composition-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/workspaces', 0700);
        config(['laravel-ffmpeg.temporary_files_root' => $this->root.'/workspaces']);
        Storage::disk('memory')->put('source.mkv', 'source media');
        Event::fake([Events\ExportStarting::class, Events\ExportCompleted::class, Events\ExportFailed::class, Events\ExportCancelled::class]);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function fakePipeline(?callable $encode = null, ?callable $probe = null): void
    {
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($encode, $probe) {
            $tool = basename($process->command[0]);

            if (in_array('-version', $process->command, true)) {
                return Process::result(output: $tool.' version 9.0.2');
            }

            if ($tool === 'ffprobe') {
                return Process::result(output: $probe ? $probe($process) : '{"format":{"duration":"2"},"streams":[{"index":0,"codec_type":"video"}]}');
            }

            if ($encode) {
                return $encode($process);
            }

            foreach ($process->command as $argument) {
                if (str_starts_with($argument, $process->path.'/output-')) {
                    file_put_contents($argument, 'encoded');
                }
            }

            return Process::result();
        });
    }

    #[Test]
    public function inspection_preserves_input_positions_and_independent_output_scopes_without_io(): void
    {
        Process::fake();
        $media = FFMpeg::disk('unavailable')->open('absent.mkv');
        $export = $media->export();
        $this->assertSame(1, $export->addInput($media));
        $this->assertSame(2, $export->addInput(FFMpeg::disk('other')->open('absent.wav', ['-probesize', '2048'])));
        $export->filterComplex('[0:v]split[left][right]')
            ->output('left.mp4', fn ($out) => $out->map('[left]')->video('libx264', crf: 21)->withoutMetadata()->chaptersFrom(null))
            ->output('right.mkv', fn ($out) => $out->map('[right]')->map('2:a?')->video('ffv1')->audio('flac')->metadataFrom(2)->chaptersFrom(1));

        $command = $export->inspect();
        $this->assertSame($command, $export->inspect());
        $positions = array_keys($command, '-i');
        $this->assertSame(['[input:0]', '[input:1]', '[input:2]'], array_map(fn ($i) => $command[$i + 1], $positions));
        $boundary = array_search('[output:0]', $command);
        $this->assertContains('21', array_slice($command, 0, $boundary));
        $this->assertNotContains('ffv1', array_slice($command, 0, $boundary));
        $this->assertContains('ffv1', array_slice($command, $boundary));
        Process::assertNothingRan();
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function conflicting_composition_settings_fail_before_execution(): void
    {
        Process::fake();
        $media = FFMpeg::disk('memory')->open('source.mkv');

        foreach ([
            fn () => $media->export()->video('ffv1')->output('one.mkv', fn () => null),
            fn () => $media->export()->output('one.mkv', fn () => null)->video('ffv1'),
            fn () => $media->export()->output('one.mkv', fn () => null)->save('two.mkv'),
            fn () => $media->export()->filterComplex('[0:v]null[out]')->inspect(),
            fn () => $media->export()->filterComplex('[0:v]null[out]')->map('[missing]')->inspect(),
            fn () => $media->export()->filterComplex('[9:v]null[out]')->map('[out]')->inspect(),
            fn () => $media->export()->filterComplex('[0:v]null[out]')->map('[out]')->map('[out]')->inspect(),
            fn () => $media->export()->output('same.mkv', fn () => null)->output('x/../same.mkv', fn () => null)->save(),
            fn () => $media->export()->output('source.mkv', fn ($out) => $out->toDisk('memory'))->output('other.mkv', fn () => null)->save(),
            fn () => $media->export()->output('one.mkv', fn ($out) => $out->clip(1, duration: 1, seek: 'keyframe'))->output('two.mkv', fn () => null)->inspect(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Ambiguous composition was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $export = $media->export();
        $export->addInput($media);

        try {
            $export->map('1:v')->inspect();
            $this->fail('Multi-input metadata sources must be explicit.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        Process::assertNothingRan();
    }

    #[Test]
    public function resources_use_whole_unquoted_generated_values_and_inspection_does_not_read_them(): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('source.mkv');
        $lut = FFMpeg::disk('missing')->open("a 'quoted', 日本語: look.cube");
        $export = $media->export()->resource('look', $lut)->videoFilter('lut3d=file={{look}}');
        $this->assertContains('lut3d=file=resource-0.cube', $export->inspect());

        foreach (["lut3d=file='{{look}}'", 'lut3d=file=prefix{{look}}', 'lut3d=file={{look}}suffix', 'movie=filename={{look}}', 'subtitles=filename={{look}}', 'lut3d=file={{missing}}', 'hflip'] as $filter) {
            try {
                $media->export()->resource('look', $lut)->videoFilter($filter)->inspect();
                $this->fail('An unsafe placeholder context was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // Brackets, commas and semicolons in a quoted filter value are not labels or chains.
        $this->assertContains("[0:v]drawtext=text='[not_a_label],;':fontfile=resource-0.cube[out]", $media->export()
            ->resource('font', $lut)->filterComplex("[0:v]drawtext=text='[not_a_label],;':fontfile={{font}}[out]")
            ->map('[out]')->inspect());
        Process::assertNothingRan();
    }

    #[Test]
    public function repeated_inputs_share_a_transfer_but_keep_their_input_ids(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('size')->once()->with('same.mkv')->andReturn(5);
        $disk->shouldReceive('readStream')->once()->with('same.mkv')->andReturnUsing(function () {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, 'media');
            rewind($stream);

            return $stream;
        });
        $this->fakePipeline();
        $media = FFMpeg::disk($disk)->open('same.mkv');
        $export = $media->export();
        $export->addInput($media);
        $result = $export->output('selected.mkv', fn ($out) => $out->map('1:v')->withoutMetadata()->chaptersFrom(null)->toDisk('memory'))->save();
        $this->assertSame('encoded', Storage::disk('memory')->get('selected.mkv'));
        $command = $result->processes()->first()['command'];
        $this->assertCount(2, array_keys($command, '-i'));
        $this->assertContains('1:v', $command);
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function every_output_is_validated_before_the_first_publication(): void
    {
        foreach (['missing', 'extra-stream', 'wrong-type'] as $failure) {
            $this->fakePipeline(function (PendingProcess $process) use ($failure) {
                file_put_contents($process->path.'/output-0.mkv', 'valid');

                if ($failure !== 'missing') {
                    file_put_contents($process->path.'/output-1.mkv', 'invalid');
                }

                return Process::result();
            }, function (PendingProcess $process) use ($failure) {
                $second = str_ends_with(end($process->command), 'output-1.mkv');

                return json_encode(['format' => ['duration' => '2'], 'streams' => $second && $failure === 'extra-stream'
                    ? [['index' => 0, 'codec_type' => 'video'], ['index' => 1, 'codec_type' => 'audio']]
                    : [['index' => 0, 'codec_type' => $second && $failure === 'wrong-type' ? 'audio' : 'video']]]);
            });

            try {
                FFMpeg::disk('memory')->open('source.mkv')->export()
                    ->output('first.mkv', fn ($out) => $out->map('0:v')->toDisk('memory'))
                    ->output('second.mkv', fn ($out) => $out->map('0:v')->toDisk('memory'))->save();
                $this->fail('Invalid sibling output reached publication.');
            } catch (MediaException $exception) {
                $this->assertSame('validation', $exception->phase);
                $this->assertSame($failure === 'missing' ? 'artifact' : 'mapping', $exception->category);
                $this->assertFalse(Storage::disk('memory')->exists('first.mkv'));
                $this->assertFalse(Storage::disk('memory')->exists('second.mkv'));
                $this->assertSame([], glob($this->root.'/workspaces/*'));
            }
        }
    }

    #[Test]
    public function partial_publication_reports_the_successful_sibling_without_deleting_it(): void
    {
        $this->fakePipeline();
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturn(false);

        try {
            FFMpeg::disk('memory')->open('source.mkv')->export()
                ->output('first.mkv', fn ($out) => $out->toDisk('memory')->map('0:v'))
                ->output('second.mkv', fn ($out) => $out->toDisk($disk)->map('0:v'))->save();
            $this->fail('Publication failure was ignored.');
        } catch (MediaException $exception) {
            $this->assertSame('publication', $exception->phase);
            $this->assertSame([['disk' => 'memory', 'path' => 'first.mkv']], $exception->published);
            $this->assertSame(['disk' => null, 'path' => 'second.mkv'], $exception->destination);
            $this->assertSame('encoded', Storage::disk('memory')->get('first.mkv'));
            $this->assertSame([], glob($this->root.'/workspaces/*'));
            Event::assertNotDispatched(Events\ExportCompleted::class);
        }
    }

    #[Test]
    public function cancellation_cleans_resources_and_outputs_without_publication(): void
    {
        $this->fakePipeline();
        Storage::disk('memory')->put('look.cube', 'a LUT');
        $cancelled = new ExportCancelled('cancel composition');

        try {
            FFMpeg::disk('memory')->open('source.mkv')->export()
                ->resource('look', FFMpeg::disk('memory')->open('look.cube'))
                ->output('first.mkv', fn ($out) => $out->videoFilter('lut3d=file={{look}}')->toDisk('memory'))
                ->output('second.mkv', fn ($out) => $out->toDisk('memory'))
                ->onProgress(function (Progress $progress) use ($cancelled) {
                    if ($progress->phase === 'encoding') {
                        throw $cancelled;
                    }
                })->save();
            $this->fail('Cancellation did not propagate.');
        } catch (ExportCancelled $exception) {
            $this->assertSame($cancelled, $exception);
            $this->assertSame([], glob($this->root.'/workspaces/*'));
            $this->assertFalse(Storage::disk('memory')->exists('first.mkv'));
            $this->assertFalse(Storage::disk('memory')->exists('second.mkv'));
            $this->assertSame('a LUT', Storage::disk('memory')->get('look.cube'));
            Event::assertDispatched(Events\ExportCancelled::class);
        }
    }

    #[Test]
    public function real_graphs_stage_luts_compose_mixed_disks_and_keep_output_timelines_independent(): void
    {
        $probe = getenv('FFMPEG_9_FFPROBE');

        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for real FFmpeg 9.0.x composition acceptance.');
        }

        $ffmpeg = dirname($probe).'/ffmpeg';
        config(['laravel-ffmpeg.ffmpeg.binaries' => $ffmpeg, 'laravel-ffmpeg.ffprobe.binaries' => $probe]);
        $local = $this->fakeLocalDisk();
        $source = $this->root.'/red.mkv';
        $generation = Process::run([$ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'color=red:size=80x60:rate=10:duration=2',
            '-c:v', 'ffv1', $source]);
        $this->assertTrue($generation->successful(), $generation->errorOutput());
        $generation = Process::run([$ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'color=blue:size=16x12:rate=10:duration=2',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=2', '-c:v', 'ffv1', '-c:a', 'pcm_s16le', $local->path('blue.mkv')]);
        $this->assertTrue($generation->successful(), $generation->errorOutput());
        Storage::disk('memory')->put("nested/red 日本語.mkv", file_get_contents($source));
        // Invert RGB, with the red coordinate varying fastest as required by .cube.
        $lut = "LUT_3D_SIZE 2\n1 1 1\n0 1 1\n1 0 1\n0 0 1\n1 1 0\n0 1 0\n1 0 0\n0 0 0\n";
        $lutKey = "looks/a 'quoted', 日本語: invert.cube";
        Storage::disk('memory')->put($lutKey, $lut);
        $export = FFMpeg::disk('memory')->open('nested/red 日本語.mkv')->export();
        $this->assertSame(1, $export->addInput(FFMpeg::disk('local')->open('blue.mkv')));
        $retained = null;
        $result = $export->resource('look', FFMpeg::disk('memory')->open($lutKey))
            ->filterComplex('[0:v]lut3d=file={{look}},split[base][small];[base][1:v]overlay=x=8:y=6[full];[small]scale=40:30[thumb]')
            ->output('composed.mkv', function (ExportOutput $out) use (&$retained) {
                $retained = $out;
                $out->map('[full]')->map('1:a')->video('ffv1')->audio('pcm_s16le')->withoutMetadata()->chaptersFrom(null)->clip(0.4, duration: 0.8)->toDisk('memory');
            })
            ->output('thumb.mkv', fn ($out) => $out->map('[thumb]')->video('ffv1')->metadataFrom(0)->chaptersFrom(null)->toDisk('local'))
            ->output('sound.wav', fn ($out) => $out->map('1:a')->audio('copy')->withoutMetadata()->chaptersFrom(null)->toDisk('local'))
            ->save();

        $this->assertCount(3, $result->files());
        $this->assertCount(1, $result->processes());
        $composed = FFMpeg::disk('memory')->open('composed.mkv')->probe();
        $thumb = FFMpeg::disk('local')->open('thumb.mkv')->probe();
        $sound = FFMpeg::disk('local')->open('sound.wav')->probe();
        $this->assertSame(['video', 'audio'], $composed->streams()->map(fn ($stream) => $stream->get('codec_type'))->all());
        $this->assertSame([80, 60], [$composed->video()->width(), $composed->video()->height()]);
        $this->assertEqualsWithDelta(0.8, $composed->duration(), 0.11);
        $this->assertSame([40, 30], [$thumb->video()->width(), $thumb->video()->height()]);
        $this->assertEqualsWithDelta(2, $thumb->duration(), 0.11);
        $this->assertCount(1, $sound->streams());
        $this->assertEqualsWithDelta(2, $sound->duration(), 0.01);
        $this->assertSame($lut, Storage::disk('memory')->get($lutKey));
        $local->put('composed.mkv', Storage::disk('memory')->get('composed.mkv'));
        $pixels = Process::run([$ffmpeg, '-v', 'error', '-i', $local->path('composed.mkv'), '-map', '0:v', '-frames:v', '1', '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-']);
        $this->assertTrue($pixels->successful(), $pixels->errorOutput());
        $rgb = array_values(unpack('C*', substr($pixels->output(), (30 * 80 + 40) * 3, 3)));
        $this->assertLessThan(25, $rgb[0]);
        $this->assertGreaterThan(225, $rgb[1]);
        $this->assertGreaterThan(225, $rgb[2]);
        $blue = array_values(unpack('C*', substr($pixels->output(), (10 * 80 + 12) * 3, 3)));
        $this->assertLessThan(25, $blue[0]);
        $this->assertLessThan(25, $blue[1]);
        $this->assertGreaterThan(225, $blue[2]);
        $audio = Process::run([$ffmpeg, '-v', 'error', '-i', $local->path('sound.wav'), '-map', '0:a', '-f', 'md5', '-']);
        $original = Process::run([$ffmpeg, '-v', 'error', '-i', $local->path('blue.mkv'), '-map', '0:a', '-f', 'md5', '-']);
        $this->assertTrue($audio->successful(), $audio->errorOutput());
        $this->assertTrue($original->successful(), $original->errorOutput());
        $this->assertSame($original->output(), $audio->output());

        $withCopiedAudio = FFMpeg::disk('memory')->open('nested/red 日本語.mkv')->export();
        $withCopiedAudio->addInput(FFMpeg::disk('local')->open('blue.mkv'));
        $withCopiedAudio->filterComplex('[0:v]null[video]')->map('[video]')->map('1:a')->video('ffv1')->audio('copy')
            ->withoutMetadata()->chaptersFrom(null)->toDisk('local')->save('copied-audio.mkv');
        $copied = FFMpeg::disk('local')->open('copied-audio.mkv')->probe();
        $this->assertCount(2, $copied->streams());
        $this->assertSame('pcm_s16le', $copied->audio()->get('codec_name'));
        $this->assertSame([80, 60], [$copied->video()->width(), $copied->video()->height()]);
        $copiedAudio = Process::run([$ffmpeg, '-v', 'error', '-i', $local->path('copied-audio.mkv'), '-map', '0:a', '-f', 'md5', '-']);
        $this->assertTrue($copiedAudio->successful(), $copiedAudio->errorOutput());
        $this->assertSame($original->output(), $copiedAudio->output());
        $this->assertSame([], glob($this->root.'/workspaces/*'));

        try {
            $retained->video('copy');
            $this->fail('A retained output must be frozen when execution starts.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function real_type_scoped_graph_conflicts_fail_without_publishing(): void
    {
        $probe = getenv('FFMPEG_9_FFPROBE');

        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for real FFmpeg graph conflicts.');
        }

        config(['laravel-ffmpeg.ffmpeg.binaries' => dirname($probe).'/ffmpeg', 'laravel-ffmpeg.ffprobe.binaries' => $probe]);
        $media = FFMpeg::disk('local')->open(new File(__DIR__.'/src/guitar.m4a'));

        foreach (['copy', 'filter'] as $conflict) {
            $export = $media->export()->filterComplex('[0:a]anull[a]')->map('[a]')->toDisk('memory');

            if ($conflict === 'copy') {
                $export->audio('copy');
            } else {
                $export->audio('flac')->audioFilter('volume=0.5');
            }

            try {
                $export->save('conflict.mkv');
                $this->fail('A filtergraph conflict was accepted by FFmpeg.');
            } catch (MediaException $exception) {
                $this->assertSame('encoding', $exception->phase);
                $this->assertSame('configuration', $exception->category, $exception->diagnostics);
                $this->assertStringContainsString($conflict === 'copy'
                    ? 'Streamcopy requested for output stream fed from a complex filtergraph'
                    : 'Simple and complex filtering cannot be used together', $exception->diagnostics);
                $this->assertFalse(Storage::disk('memory')->exists('conflict.mkv'));
                $this->assertSame([], glob($this->root.'/workspaces/*'));
            }
        }
    }

    #[Test]
    public function real_unlabeled_graph_pads_are_rejected_before_publication(): void
    {
        $probe = getenv('FFMPEG_9_FFPROBE');

        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for real FFmpeg graph validation.');
        }

        config(['laravel-ffmpeg.ffmpeg.binaries' => dirname($probe).'/ffmpeg', 'laravel-ffmpeg.ffprobe.binaries' => $probe]);
        $media = FFMpeg::disk('local')->open(new File(__DIR__.'/src/video.mp4'));

        try {
            // split has an unlabeled second pad. FFmpeg silently adds it to output zero.
            $media->export()->filterComplex('[0:v]split=2[named]')->map('[named]')->video('ffv1')->toDisk('memory')->save('unexpected.mkv');
            $this->fail('The unmapped graph pad was published.');
        } catch (MediaException $exception) {
            $this->assertSame('validation', $exception->phase);
            $this->assertSame('mapping', $exception->category);
            $this->assertFalse(Storage::disk('memory')->exists('unexpected.mkv'));
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }
}

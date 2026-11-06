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

            if (in_array('-filters', $process->command, true)) {
                return Process::result(output: " ... drawtext V->V\n ... subtitles V->V\n");
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
    public function typed_resources_reject_overrides_expansion_and_input_seeks_without_io(): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('source.mkv');
        $subtitle = FFMpeg::disk('missing')->open('captions.srt');

        $this->assertContains('drawtext=textfile=resource-0.txt:expansion=none', $media->export()
            ->textResource('title', "100%: café ' [x],; \\ %{not_a_function}")
            ->videoFilter('drawtext=textfile={{title}}:expansion=none')->inspect());
        $this->assertContains('[0:v]subtitles=filename=resource-0.srt[out]', $media->export()
            ->subtitleResource('captions', $subtitle)->filterComplex('[0:v]subtitles=filename={{captions}}[out]')
            ->map('[out]')->inspect());

        foreach ([
            'drawtext=textfile={{title}}',
            'drawtext=expansion=none,drawtext=textfile={{title}}',
            'drawtext=textfile={{title}}:expansion=none:expansion=normal',
            'drawtext=textfile={{title}}:expansion=none:reload=1',
            'drawtext=textfile={{title}}:expansion=none:text=override',
            'drawtext=textfile={{title}}:expansion=none:textfile=override.txt',
            'drawtext=positional:textfile={{title}}:expansion=none',
            'lut3d=file={{title}}',
        ] as $filter) {
            try {
                $media->export()->textResource('title', 'literal')->videoFilter($filter)->inspect();
                $this->fail('Literal text resource restrictions were bypassed.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['subtitles=filename={{captions}}:f=other.ass', 'subtitles=filename={{captions}}:filename=other.ass',
            'subtitles=other.ass:filename={{captions}}', 'subtitles={{captions}}', 'drawtext=textfile={{captions}}:expansion=none'] as $filter) {
            try {
                $media->export()->subtitleResource('captions', $subtitle)->videoFilter($filter)->inspect();
                $this->fail('A subtitle filename or type restriction was bypassed.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach ([
            fn () => $media->export()->textResource('bad', "\xff"),
            fn () => $media->export()->textResource('bad', "a\0b"),
            fn () => $media->export()->textResource('name', 'literal')->subtitleResource('name', $subtitle),
            fn () => $media->export()->subtitleResource('captions', $subtitle)->clip(1, duration: 1, seek: 'keyframe')->inspect(),
            fn () => $media->export()->subtitleResource('captions', FFMpeg::disk('missing')->open('captions.srt', ['-probesize', '2048'])),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Invalid text or subtitle configuration was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        Process::assertNothingRan();
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function literal_text_is_written_unchanged_and_missing_filter_support_is_typed(): void
    {
        $text = "100%: café ' [x],; \\ %{not_a_function}\n";
        $this->fakePipeline(function (PendingProcess $process) use ($text) {
            $this->assertSame($text, file_get_contents($process->path.'/resource-0.txt'));
            $this->assertSame(0600, fileperms($process->path.'/resource-0.txt') & 0777);
            file_put_contents($process->path.'/output-0.mkv', 'encoded');

            return Process::result();
        });
        FFMpeg::disk('memory')->open('source.mkv')->export()->textResource('title', $text)
            ->videoFilter('drawtext=textfile={{title}}:expansion=none')->toDisk('memory')->save('text.mkv');
        $this->assertSame('encoded', Storage::disk('memory')->get('text.mkv'));

        Process::fake(function (PendingProcess $process) {
            return Process::result(output: in_array('-version', $process->command, true) ? basename($process->command[0]).' version 9.0.2' : '');
        });

        try {
            FFMpeg::disk('memory')->open('source.mkv')->export()->textResource('title', $text)
                ->videoFilter('drawtext=textfile={{title}}:expansion=none')->toDisk('memory')->save('unsupported.mkv');
            $this->fail('A build without drawtext was accepted.');
        } catch (MediaException $exception) {
            $this->assertSame('capability', $exception->category);
            $this->assertFalse(Storage::disk('memory')->exists('unsupported.mkv'));
            $this->assertSame([], glob($this->root.'/workspaces/*'));
        }
    }

    #[Test]
    public function probed_streams_resolve_to_their_source_and_absolute_index_without_more_io(): void
    {
        $this->fakePipeline(probe: fn () => '{"format":{"duration":"2"},"streams":[{"index":2,"codec_type":"audio","tags":{"language":"fra"}},{"index":7,"codec_type":"video"},{"index":9,"codec_type":"audio","tags":{"language":"eng"}}]}');
        $media = FFMpeg::disk('memory')->open('source.mkv');
        $metadata = $media->probe();
        $video = $metadata->videoStreams()->first();
        $audio = $metadata->audioStreams()->last();
        $this->assertSame(7, $video->index());
        Process::swap(new \Illuminate\Process\Factory);
        Process::fake();
        $command = $media->export()->mapAudio($audio)->mapVideo($video)->inspect();
        $positions = array_keys($command, '-map');
        $this->assertSame(['0:9', '0:7'], array_map(fn ($i) => $command[$i + 1], $positions));
        $this->assertContains('0:a:m:language:eng:?', $media->export()->mapAudio(language: 'eng', optional: true)->inspect());
        $this->assertContains('0:v:1', $media->export()->mapVideo(track: 1)->inspect());
        $this->assertContains('0:a:0', $media->export()->mapAudio()->inspect());
        $this->assertSame($media->export()->mapVideo(input: 0)->mapAudio(input: 0)->inspect(),
            $media->export()->mapVideo(0)->mapAudio(0)->inspect());
        $other = FFMpeg::disk('missing')->open('other.mkv');
        $export = $other->export();
        $export->addInput($media);
        $this->assertContains('1:7', $export->mapVideo($video)->withoutMetadata()->chaptersFrom(null)->inspect());
        $this->assertContains('1:v:2', $export->mapVideo(1, track: 2)->inspect());
        $this->assertContains('1:a:m:language:eng:?', $export->mapAudio(1, language: 'eng', optional: true)->inspect());
        $repeated = $media->export();
        $repeated->addInput($media);
        $this->assertContains('1:7:?', $repeated->output('one.mkv', fn ($out) => $out
            ->mapVideo($video, input: 1, optional: true)->withoutMetadata()->chaptersFrom(null))->inspect());

        foreach ([
            fn () => $media->export()->mapVideo($audio),
            fn () => $media->export()->mapAudio($video),
            fn () => $media->export()->mapVideo($video, track: 1),
            fn () => $media->export()->mapAudio($audio, language: 'eng'),
            fn () => $media->export()->mapAudio(track: 0, language: 'eng'),
            fn () => $media->export()->mapAudio(language: 'eng:0'),
            fn () => $media->export()->mapVideo(0, input: 1),
            fn () => $media->export()->mapAudio(0, input: 1),
            fn () => $media->export()->mapAudio(-1),
            fn () => $media->export()->mapVideo(input: -1),
            fn () => $media->export()->mapAudio(track: -1),
            fn () => $other->export()->mapVideo($video, optional: true)->inspect(),
            fn () => $export->output('one.mkv', fn () => null),
            fn () => $media->export()->mapVideo($video, input: 9)->inspect(),
            fn () => $other->export()->mapVideo($video, input: 0)->inspect(),
            function () use ($media, $video) {
                $ambiguous = $media->export();
                $ambiguous->addInput($media);
                $ambiguous->mapVideo($video)->withoutMetadata()->chaptersFrom(null)->inspect();
            },
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An ambiguous or invalid stream selection was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        Process::assertNothingRan();
    }

    #[Test]
    public function filter_shortcuts_keep_order_and_output_owned_resources_private_and_shared(): void
    {
        $font = FFMpeg::disk('memory')->open('font.ttf');
        $captions = FFMpeg::disk('missing')->open('captions.srt');
        $media = FFMpeg::disk('memory')->open('source.mkv');
        Process::fake();
        $export = $media->export()
            ->output('one.mkv', fn ($out) => $out->burnSubtitles($captions)->resize(width: 80))
            ->output('two.mkv', fn ($out) => $out->resize(width: 80)->burnSubtitles($captions));
        $command = $export->inspect();
        $this->assertSame($command, $export->inspect());
        $this->assertContains('subtitles=filename=resource-0.srt,scale=w=80:h=-2:reset_sar=1', $command);
        $this->assertContains('scale=w=80:h=-2:reset_sar=1,subtitles=filename=resource-0.srt', $command);

        foreach ([
            fn () => $media->export()->drawText("\xff", $font),
            fn () => $media->export()->drawText('text', $font, size: 0),
            fn () => $media->export()->drawText('text', $font, color: 'white:reload=1'),
            fn () => $media->export()->burnSubtitles($captions)->clip(1, duration: 1, seek: 'keyframe')->inspect(),
            fn () => $media->export()->burnSubtitles($captions)->video('copy')->inspect(),
            fn () => $media->export()->output('one.mkv', fn () => null)->drawText('text', $font),
            fn () => $media->export()->output('one.mkv', fn () => null)->burnSubtitles($captions),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Invalid resource helper configuration was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        Process::assertNothingRan();

        Storage::disk('memory')->put('font.ttf', 'font bytes');
        $texts = ["100% {{font}}: café \\ ' [x],; %{not_a_function}", 'different output'];
        $retained = null;
        $this->fakePipeline(function (PendingProcess $process) use ($texts) {
            $this->assertCount(1, glob($process->path.'/resource-*.ttf'));
            $files = glob($process->path.'/resource-*.txt');
            $this->assertCount(2, $files);
            $this->assertEqualsCanonicalizing($texts, array_map('file_get_contents', $files));
            foreach (glob($process->path.'/resource-*') as $resource) {
                $this->assertSame(0600, fileperms($resource) & 0777);
            }
            file_put_contents($process->path.'/output-0.mkv', 'encoded');
            file_put_contents($process->path.'/output-1.mkv', 'encoded');

            return Process::result();
        });
        $media->export()->output('one.mkv', function ($out) use (&$retained, $texts, $font) {
            $retained = $out;
            $out->drawText($texts[0], $font)->mapVideo()->toDisk('memory');
        })->output('two.mkv', fn ($out) => $out->drawText($texts[1], $font)->mapVideo()->toDisk('memory'))->save();
        $this->assertSame('encoded', Storage::disk('memory')->get('one.mkv'));
        $this->assertSame('encoded', Storage::disk('memory')->get('two.mkv'));

        try {
            $retained->drawText('late change', $font);
            $this->fail('A saved output was mutable.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([], glob($this->root.'/workspaces/*'));

        Storage::disk('memory')->put('invalid.srt', "\xff");
        $this->fakePipeline();
        foreach (['drawText', 'burnSubtitles'] as $helper) {
            try {
                $media->export()->output('failed-'.$helper.'.mkv', function ($out) use ($helper) {
                    $out->toDisk('memory');
                    if ($helper === 'drawText') {
                        $out->drawText('text', FFMpeg::disk('memory')->open('missing-font.ttf'));
                    } else {
                        $out->burnSubtitles(FFMpeg::disk('memory')->open('invalid.srt'));
                    }
                })->save();
                $this->fail('An unreadable or invalid helper resource was accepted.');
            } catch (MediaException $exception) {
                $this->assertStringContainsString('output 0 '.$helper, $exception->getMessage());
                $this->assertSame($helper === 'drawText' ? 'staging' : 'probing', $exception->phase);
                $this->assertFalse(Storage::disk('memory')->exists('failed-'.$helper.'.mkv'));
                $this->assertSame([], glob($this->root.'/workspaces/*'));
            }
        }
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

        $title = "English 'mix'; 日本語 = literal";
        FFMpeg::disk('local')->open('blue.mkv')->export()->filterComplex('[0:a]asplit[english][german]')
            ->output('tagged-first.mkv', fn ($out) => $out->map('[english]')->mapVideo(0)
                ->video('copy')->audio('pcm_s16le')->withoutMetadata()->chaptersFrom(null)
                ->streamMetadata(0, 'language', 'eng')->streamMetadata(0, 'title', $title)
                ->disposition(0, ['default', 'forced'])->disposition('v:0', '0')->toDisk('memory'))
            ->output('tagged-second.mkv', fn ($out) => $out->mapVideo(0)->map('[german]')
                ->video('copy')->audio('pcm_s16le')->withoutMetadata()->chaptersFrom(null)
                ->streamMetadata('a', 'language', 'fra')->streamMetadata('a:0', 'language', 'spa')
                ->streamMetadata('a', 'language', 'deu')->streamMetadata('a:0', 'title', 'Remove me')
                ->streamMetadata('a:0', 'title', '')
                ->disposition('a', [])->disposition('a:0', 'forced')->disposition('a', [])->toDisk('local'))
            ->save();
        $first = FFMpeg::disk('memory')->open('tagged-first.mkv')->probe();
        $second = FFMpeg::disk('local')->open('tagged-second.mkv')->probe();
        $this->assertSame(['audio', 'video'], $first->streams()->map->type()->all());
        $this->assertSame(['video', 'audio'], $second->streams()->map->type()->all());
        $this->assertSame('eng', $first->audio()->language());
        $this->assertSame($title, $first->audio()->tag('title'));
        $this->assertTrue($first->audio()->isDefault());
        $this->assertTrue($first->audio()->isForced());
        $this->assertSame('deu', $second->audio()->language());
        $this->assertNull($second->audio()->tag('title'));
        $this->assertFalse($second->audio()->isDefault());
        $this->assertFalse($second->audio()->isForced());

        foreach ([
            fn () => $retained->video('copy'),
            fn () => $retained->streamMetadata('a', 'language', 'eng'),
            fn () => $retained->disposition('a', 'default'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A retained output must be frozen when execution starts.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function real_fonts_and_literal_text_render_without_filter_or_percent_expansion(): void
    {
        $probe = getenv('FFMPEG_9_FFPROBE');

        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for real font rendering.');
        }

        $ffmpeg = dirname($probe).'/ffmpeg';
        config(['laravel-ffmpeg.ffmpeg.binaries' => $ffmpeg, 'laravel-ffmpeg.ffprobe.binaries' => $probe]);
        $local = $this->fakeLocalDisk();
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        $this->assertFileExists($font, 'Native resource acceptance requires DejaVu Sans.');
        $text = "100% 'quoted': \\ [x],; café\n%{not_a_function}";
        Storage::disk('memory')->put("fonts/a 'quoted', café.ttf", file_get_contents($font));
        $generation = Process::run([$ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'color=black:size=480x120:rate=10:duration=0.2',
            '-c:v', 'ffv1', $local->path('black.mkv')]);
        $this->assertTrue($generation->successful(), $generation->errorOutput());
        FFMpeg::disk('local')->open('black.mkv')->export()
            ->resource('font', FFMpeg::disk('memory')->open("fonts/a 'quoted', café.ttf"))->textResource('title', $text)
            ->videoFilter('drawtext=fontfile={{font}}:textfile={{title}}:expansion=none:fontcolor=white:fontsize=20:x=8:y=8')
            ->video('ffv1')->toDisk('local')->save('title.mkv');

        // Compare against a direct render from the unchanged literal file, not PHP escaping.
        file_put_contents($this->root.'/literal.txt', $text);
        $reference = Process::run([$ffmpeg, '-v', 'error', '-i', $local->path('black.mkv'), '-vf',
            'drawtext=fontfile='.$font.':textfile='.$this->root.'/literal.txt:expansion=none:fontcolor=white:fontsize=20:x=8:y=8',
            '-frames:v', '1', '-pix_fmt', 'gray', '-f', 'rawvideo', '-']);
        $actual = Process::run([$ffmpeg, '-v', 'error', '-i', $local->path('title.mkv'), '-frames:v', '1', '-pix_fmt', 'gray', '-f', 'rawvideo', '-']);
        $this->assertTrue($reference->successful(), $reference->errorOutput());
        $this->assertTrue($actual->successful(), $actual->errorOutput());
        $this->assertSame($reference->output(), $actual->output());
        $this->assertGreaterThan(200, count(array_filter(unpack('C*', $actual->output()), fn ($pixel) => $pixel > 200)));

        FFMpeg::disk('local')->open('black.mkv')->export()
            ->drawText($text, font: FFMpeg::disk('memory')->open("fonts/a 'quoted', café.ttf"), size: 20, x: 8, y: 8)
            ->video('ffv1')->toDisk('local')->save('title-helper.mkv');
        $helper = Process::run([$ffmpeg, '-v', 'error', '-i', $local->path('title-helper.mkv'), '-frames:v', '1', '-pix_fmt', 'gray', '-f', 'rawvideo', '-']);
        $this->assertTrue($helper->successful(), $helper->errorOutput());
        $this->assertSame($reference->output(), $helper->output());
        $this->assertSame([], glob($this->root.'/workspaces/*'));
    }

    #[Test]
    public function real_subtitles_validate_content_and_keep_cue_timing_through_accurate_clips(): void
    {
        $probe = getenv('FFMPEG_9_FFPROBE');

        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for real subtitle resources.');
        }

        $ffmpeg = dirname($probe).'/ffmpeg';
        config(['laravel-ffmpeg.ffmpeg.binaries' => $ffmpeg, 'laravel-ffmpeg.ffprobe.binaries' => $probe]);
        $local = $this->fakeLocalDisk();
        $generation = Process::run([$ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'color=black:size=160x90:rate=10:duration=6',
            '-c:v', 'ffv1', $local->path('black.mkv')]);
        $this->assertTrue($generation->successful(), $generation->errorOutput());
        $formats = [
            'srt' => "1\n00:00:05,000 --> 00:00:05,500\nCaption café\n",
            'vtt' => "WEBVTT\n\n00:05.000 --> 00:05.500\nCaption café\n",
            'ass' => "[Script Info]\nScriptType: v4.00+\nPlayResX: 160\nPlayResY: 90\n[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, Alignment\nStyle: Default,DejaVu Sans,14,&H00FFFFFF,2\n[Events]\nFormat: Layer, Start, End, Style, Text\nDialogue: 0,0:00:05.00,0:00:05.50,Default,Caption café\n",
        ];

        foreach ($formats as $extension => $content) {
            $key = "captions/a 'quoted', café.".$extension;
            Storage::disk('memory')->put($key, $content);
            FFMpeg::disk('local')->open('black.mkv')->export()
                ->subtitleResource('captions', FFMpeg::disk('memory')->open($key))
                ->videoFilter('subtitles=filename={{captions}}')->video('ffv1')->clip(4, duration: 2)
                ->toDisk('local')->save('caption-'.$extension.'.mkv');
            $frames = Process::run([$ffmpeg, '-v', 'error', '-i', $local->path('caption-'.$extension.'.mkv'), '-pix_fmt', 'gray', '-f', 'rawvideo', '-']);
            $this->assertTrue($frames->successful(), $frames->errorOutput());
            $this->assertSame(20 * 160 * 90, strlen($frames->output()));
            foreach ([2 => false, 12 => true, 18 => false] as $index => $visible) {
                $pixels = unpack('C*', substr($frames->output(), $index * 160 * 90, 160 * 90));
                $this->assertSame($visible, count(array_filter($pixels, fn ($pixel) => $pixel > 100)) > 10, $extension.' frame '.$index);
            }
            $this->assertSame($content, Storage::disk('memory')->get($key));

            FFMpeg::disk('local')->open('black.mkv')->export()
                ->burnSubtitles(FFMpeg::disk('memory')->open($key))->video('ffv1')->clip(4, duration: 2)
                ->toDisk('local')->save('caption-helper-'.$extension.'.mkv');
            $helper = Process::run([$ffmpeg, '-v', 'error', '-i', $local->path('caption-helper-'.$extension.'.mkv'), '-pix_fmt', 'gray', '-f', 'rawvideo', '-']);
            $this->assertTrue($helper->successful(), $helper->errorOutput());
            $this->assertSame($frames->output(), $helper->output());
        }

        foreach (["#EXTM3U\n#EXT-X-TARGETDURATION:1\n#EXTINF:1,\nsibling.ts\n", "ffconcat version 1.0\nfile 'sibling.ts'\n", "1\n00:00:00,000 --> 00:00:01,000\n\xff\n"] as $invalid) {
            Storage::disk('memory')->put('forged.srt', $invalid);
            try {
                FFMpeg::disk('local')->open('black.mkv')->export()
                    ->subtitleResource('captions', FFMpeg::disk('memory')->open('forged.srt'))
                    ->videoFilter('subtitles=filename={{captions}}')->video('ffv1')->toDisk('memory')->save('rejected.mkv');
                $this->fail('A non-subtitle resource was accepted.');
            } catch (MediaException $exception) {
                $this->assertSame('probing', $exception->phase);
                $this->assertSame('resource', $exception->category);
                $this->assertFalse(Storage::disk('memory')->exists('rejected.mkv'));
                $this->assertSame([], glob($this->root.'/workspaces/*'));
            }
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

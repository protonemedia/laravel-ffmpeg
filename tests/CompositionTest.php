<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\Filesystem as LocalFilesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\MultipleItemsFoundException;
use InvalidArgumentException;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Events;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\ExportOutput;
use ProtoneMedia\LaravelFFMpeg\ExportResult;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\PendingExport;
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Stream;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Watermark;

class CompositionTest extends TestCase
{
    /**
     * The directory with the videos FFmpeg generated once for the whole class.
     */
    protected static ?string $fixtures = null;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::disk('memory')->put('source.mkv', 'source media');

        Event::fake([
            Events\ExportStarting::class,
            Events\ExportCompleted::class,
            Events\ExportFailed::class,
            Events\ExportCancelled::class,
        ]);
    }

    /**
     * Fake FFmpeg and FFprobe 9, with optional callbacks for the encode and the probe output.
     */
    protected function fakePipeline(?callable $encode = null, ?callable $probe = null): void
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
                $video = '{"format":{"duration":"2"},"streams":[{"index":0,"codec_type":"video"}]}';

                return Process::result(output: $probe ? $probe($process) : $video);
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

    /**
     * Remove the media FFmpeg generated for the class after its last test.
     */
    public static function tearDownAfterClass(): void
    {
        if (static::$fixtures !== null) {
            (new LocalFilesystem)->deleteDirectory(static::$fixtures);

            static::$fixtures = null;
        }

        parent::tearDownAfterClass();
    }

    /**
     * Get the path of the given generated video, running FFmpeg only the first time.
     */
    protected function fixture(string $name): string
    {
        $ffmpeg = $this->useRealFFMpeg();

        // Several tests start from the same small synthetic videos. Generating each one
        // once per class keeps the real FFmpeg tests fast, and every test still copies
        // the video to its own disk so it can never change what the next test reads.
        static::$fixtures ??= sys_get_temp_dir().'/laravel-ffmpeg-composition-'.bin2hex(random_bytes(8));

        $path = static::$fixtures.'/'.$name;

        if (is_file($path)) {
            return $path;
        }

        if (! is_dir(static::$fixtures)) {
            mkdir(static::$fixtures, 0700);
        }

        $arguments = match ($name) {
            'red.mkv' => ['-f', 'lavfi', '-i', 'color=red:size=80x60:rate=10:duration=2', '-c:v', 'ffv1'],
            'red-short.mkv' => ['-f', 'lavfi', '-i', 'color=red:size=80x60:rate=10:duration=1', '-c:v', 'ffv1'],
            'red-with-audio.mkv' => [
                '-f', 'lavfi', '-i', 'color=red:size=80x60:rate=10:duration=2',
                '-f', 'lavfi', '-i', 'sine=sample_rate=48000:duration=2',
                '-c:v', 'ffv1', '-c:a', 'pcm_s16le',
            ],
            'blue-with-tone.mkv' => [
                '-f', 'lavfi', '-i', 'color=blue:size=16x12:rate=10:duration=2',
                '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=2',
                '-c:v', 'ffv1', '-c:a', 'pcm_s16le',
            ],
            'black-captions.mkv' => ['-f', 'lavfi', '-i', 'color=black:size=160x90:rate=10:duration=6', '-c:v', 'ffv1'],
        };

        $this->assertProcessSucceeded(Process::run([$ffmpeg, '-v', 'error', ...$arguments, $path]));

        return $path;
    }

    /**
     * Probe a source with three streams and get it with its last video and audio stream.
     */
    protected function probedStreams(): array
    {
        $this->fakePipeline(probe: fn () => json_encode([
            'format' => ['duration' => '2'],
            'streams' => [
                ['index' => 2, 'codec_type' => 'audio', 'tags' => ['language' => 'fra']],
                ['index' => 7, 'codec_type' => 'video'],
                ['index' => 9, 'codec_type' => 'audio', 'tags' => ['language' => 'eng']],
            ],
        ]));

        $media = FFMpeg::disk('memory')->open('source.mkv');
        $metadata = $media->probe();

        // A fresh fake replaces the probing one, so the tests can assert that
        // selecting the probed streams runs no process of its own.
        Process::swap(new Factory);
        Process::fake();

        return [$media, $metadata->videoStreams()->first(), $metadata->audioStreams()->last()];
    }

    public function test_inspection_keeps_input_positions_and_output_scopes_without_io(): void
    {
        Process::fake();

        $media = FFMpeg::disk('unavailable')->open('absent.mkv');
        $export = $media->export();

        $this->assertSame(1, $export->addInput($media));
        $this->assertSame(2, $export->addInput(FFMpeg::disk('other')->open('absent.wav', ['-probesize', '2048'])));

        $export->filterComplex('[0:v]split[left][right]')
            ->output('left.mp4', fn ($out) => $out->map('[left]')
                ->video('libx264', crf: 21)
                ->withoutMetadata()
                ->chaptersFrom(null))
            ->output('right.mkv', fn ($out) => $out->map('[right]')
                ->map('2:a?')
                ->video('ffv1')
                ->audio('flac')
                ->metadataFrom(2)
                ->chaptersFrom(1));

        $command = $export->inspect();
        $positions = array_keys($command, '-i');
        $boundary = array_search('[output:0]', $command);

        $this->assertSame($command, $export->inspect());
        $this->assertSame(['[input:0]', '[input:1]', '[input:2]'], array_map(fn ($i) => $command[$i + 1], $positions));
        $this->assertContains('21', array_slice($command, 0, $boundary));
        $this->assertNotContains('ffv1', array_slice($command, 0, $boundary));
        $this->assertContains('ffv1', array_slice($command, $boundary));
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('conflictingCompositionProvider')]
    public function test_conflicting_composition_settings_fail_before_execution(Closure $attempt): void
    {
        Process::fake();

        $media = FFMpeg::disk('memory')->open('source.mkv');

        $this->assertThrows(fn () => $attempt($media), InvalidArgumentException::class);
        Process::assertNothingRan();
    }

    /**
     * Get the compositions that are ambiguous or conflict with each other.
     */
    public static function conflictingCompositionProvider(): array
    {
        return [
            'codec before outputs' => [fn (Media $media) => $media->export()
                ->video('ffv1')
                ->output('one.mkv', fn () => null)],
            'codec after outputs' => [fn (Media $media) => $media->export()
                ->output('one.mkv', fn () => null)
                ->video('ffv1')],
            'path with outputs' => [fn (Media $media) => $media->export()
                ->output('one.mkv', fn () => null)
                ->save('two.mkv')],
            'unmapped graph' => [fn (Media $media) => $media->export()
                ->filterComplex('[0:v]null[out]')
                ->inspect()],
            'missing label' => [fn (Media $media) => $media->export()
                ->filterComplex('[0:v]null[out]')
                ->map('[missing]')
                ->inspect()],
            'missing input' => [fn (Media $media) => $media->export()
                ->filterComplex('[9:v]null[out]')
                ->map('[out]')
                ->inspect()],
            'label mapped twice' => [fn (Media $media) => $media->export()
                ->filterComplex('[0:v]null[out]')
                ->map('[out]')
                ->map('[out]')
                ->inspect()],
            'same destination' => [fn (Media $media) => $media->export()
                ->output('same.mkv', fn () => null)
                ->output('x/../same.mkv', fn () => null)
                ->save()],
            'overwrites the source' => [fn (Media $media) => $media->export()
                ->output('source.mkv', fn ($out) => $out->toDisk('memory'))
                ->output('other.mkv', fn () => null)
                ->save()],
            'keyframe seek per output' => [fn (Media $media) => $media->export()
                ->output('one.mkv', fn ($out) => $out->clip(1, duration: 1, seek: 'keyframe'))
                ->output('two.mkv', fn () => null)
                ->inspect()],
            'implicit metadata source' => [function (Media $media) {
                $export = $media->export();
                $export->addInput($media);

                return $export->map('1:v')->inspect();
            }],
        ];
    }

    #[DataProvider('metadataOmissionProvider')]
    public function test_metadata_omission_keeps_the_existing_chapter_defaults(Closure $export, string $chapters): void
    {
        Process::fake();

        $command = $export(FFMpeg::disk('missing')->open('source.mkv'))->inspect();

        $this->assertSame('-1', $command[array_search('-map_metadata', $command, true) + 1]);
        $this->assertSame($chapters, $command[array_search('-map_chapters', $command, true) + 1]);
        Process::assertNothingRan();
    }

    /**
     * Get the exports that omit metadata, with the chapter source each one maps.
     */
    public static function metadataOmissionProvider(): array
    {
        return [
            'metadata only' => [fn (Media $media) => $media->export()
                ->withoutMetadata(), '0'],
            'chapters after explicit chapters' => [fn (Media $media) => $media->export()
                ->chaptersFrom(0)
                ->withoutMetadata(chapters: true), '-1'],
            'explicit chapters after omission' => [fn (Media $media) => $media->export()
                ->withoutMetadata(chapters: true)
                ->chaptersFrom(0), '0'],
            'chapters restored' => [fn (Media $media) => $media->export()
                ->withoutMetadata(chapters: true)
                ->withoutMetadata(chapters: false), '-1'],
            'clipped' => [fn (Media $media) => $media->export()
                ->clip(1, duration: 2)
                ->withoutMetadata(), '-1'],
        ];
    }

    public function test_metadata_omission_is_scoped_to_each_output(): void
    {
        Process::fake();

        $media = FFMpeg::disk('missing')->open('source.mkv');
        $export = $media->export();
        $export->addInput($media);

        $command = $export
            ->output('omitted.mkv', fn ($out) => $out->mapVideo(0)->withoutMetadata(chapters: true))
            ->output('kept.mkv', fn ($out) => $out->mapVideo(1)
                ->chaptersFrom(1)
                ->withoutMetadata())
            ->inspect();

        $chapterSources = array_map(fn ($i) => $command[$i + 1], array_keys($command, '-map_chapters', true));

        $this->assertSame(['-1', '1'], $chapterSources);
        Process::assertNothingRan();
    }

    public function test_resource_placeholders_become_generated_file_names_without_reading_the_resource(): void
    {
        Process::fake();

        $media = FFMpeg::disk('missing')->open('source.mkv');
        $lut = FFMpeg::disk('missing')->open("a 'quoted', 日本語: look.cube");

        $export = $media->export()
            ->resource('look', $lut)
            ->videoFilter('lut3d=file={{look}}');

        $this->assertContains('lut3d=file=resource-0.cube', $export->inspect());
        $this->assertSame($export->inspect(), $media->export()->lut3d($lut)->inspect());
        Process::assertNothingRan();
    }

    public function test_quoted_filter_values_are_not_parsed_as_labels_or_chains(): void
    {
        Process::fake();

        $lut = FFMpeg::disk('missing')->open("a 'quoted', 日本語: look.cube");

        // Brackets, commas and semicolons in a quoted filter value are not labels or chains.
        $command = FFMpeg::disk('missing')
            ->open('source.mkv')
            ->export()
            ->resource('font', $lut)
            ->filterComplex("[0:v]drawtext=text='[not_a_label],;':fontfile={{font}}[out]")
            ->map('[out]')
            ->inspect();

        $this->assertContains("[0:v]drawtext=text='[not_a_label],;':fontfile=resource-0.cube[out]", $command);
        Process::assertNothingRan();
    }

    #[DataProvider('unsafePlaceholderProvider')]
    public function test_resource_placeholders_must_be_whole_unquoted_values(string $filter): void
    {
        Process::fake();

        $media = FFMpeg::disk('missing')->open('source.mkv');
        $lut = FFMpeg::disk('missing')->open("a 'quoted', 日本語: look.cube");

        $this->assertThrows(
            fn () => $media->export()
                ->resource('look', $lut)
                ->videoFilter($filter)
                ->inspect(),
            InvalidArgumentException::class,
        );
        Process::assertNothingRan();
    }

    /**
     * Get the filters that use a resource placeholder in an unsafe context.
     */
    public static function unsafePlaceholderProvider(): array
    {
        return [
            'quoted' => ["lut3d=file='{{look}}'"],
            'prefixed' => ['lut3d=file=prefix{{look}}'],
            'suffixed' => ['lut3d=file={{look}}suffix'],
            'movie source' => ['movie=filename={{look}}'],
            'subtitles filter' => ['subtitles=filename={{look}}'],
            'undeclared resource' => ['lut3d=file={{missing}}'],
            'unused resource' => ['hflip'],
        ];
    }

    public function test_text_resources_are_staged_as_text_files(): void
    {
        Process::fake();

        $command = FFMpeg::disk('missing')
            ->open('source.mkv')
            ->export()
            ->textResource('title', "100%: café ' [x],; \\ %{not_a_function}")
            ->videoFilter('drawtext=textfile={{title}}:expansion=none')
            ->inspect();

        $this->assertContains('drawtext=textfile=resource-0.txt:expansion=none', $command);
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_subtitle_resources_are_staged_as_subtitle_files(): void
    {
        Process::fake();

        $command = FFMpeg::disk('missing')
            ->open('source.mkv')
            ->export()
            ->subtitleResource('captions', FFMpeg::disk('missing')->open('captions.srt'))
            ->filterComplex('[0:v]subtitles=filename={{captions}}[out]')
            ->map('[out]')
            ->inspect();

        $this->assertContains('[0:v]subtitles=filename=resource-0.srt[out]', $command);
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('unsafeTextFilterProvider')]
    public function test_text_resources_must_be_drawn_literally(string $filter): void
    {
        Process::fake();

        $media = FFMpeg::disk('missing')->open('source.mkv');

        $this->assertThrows(
            fn () => $media->export()
                ->textResource('title', 'literal')
                ->videoFilter($filter)
                ->inspect(),
            InvalidArgumentException::class,
        );
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Get the filters that would expand, reload or replace a text resource.
     */
    public static function unsafeTextFilterProvider(): array
    {
        return [
            'expansion left on' => ['drawtext=textfile={{title}}'],
            'expansion in another filter' => ['drawtext=expansion=none,drawtext=textfile={{title}}'],
            'expansion overridden' => ['drawtext=textfile={{title}}:expansion=none:expansion=normal'],
            'reloaded' => ['drawtext=textfile={{title}}:expansion=none:reload=1'],
            'text overridden' => ['drawtext=textfile={{title}}:expansion=none:text=override'],
            'text file overridden' => ['drawtext=textfile={{title}}:expansion=none:textfile=override.txt'],
            'positional option' => ['drawtext=positional:textfile={{title}}:expansion=none'],
            'used as a LUT' => ['lut3d=file={{title}}'],
        ];
    }

    #[DataProvider('unsafeSubtitleFilterProvider')]
    public function test_subtitle_resources_must_be_the_only_subtitle_file(string $filter): void
    {
        Process::fake();

        $media = FFMpeg::disk('missing')->open('source.mkv');
        $subtitle = FFMpeg::disk('missing')->open('captions.srt');

        $this->assertThrows(
            fn () => $media->export()
                ->subtitleResource('captions', $subtitle)
                ->videoFilter($filter)
                ->inspect(),
            InvalidArgumentException::class,
        );
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Get the filters that would change the file or type of a subtitle resource.
     */
    public static function unsafeSubtitleFilterProvider(): array
    {
        return [
            'format overridden' => ['subtitles=filename={{captions}}:f=other.ass'],
            'filename overridden' => ['subtitles=filename={{captions}}:filename=other.ass'],
            'positional filename' => ['subtitles=other.ass:filename={{captions}}'],
            'unnamed option' => ['subtitles={{captions}}'],
            'drawn as text' => ['drawtext=textfile={{captions}}:expansion=none'],
        ];
    }

    #[DataProvider('invalidTypedResourceProvider')]
    public function test_invalid_text_and_subtitle_resources_are_rejected(Closure $attempt): void
    {
        Process::fake();

        $media = FFMpeg::disk('missing')->open('source.mkv');
        $subtitle = FFMpeg::disk('missing')->open('captions.srt');

        $this->assertThrows(fn () => $attempt($media, $subtitle), InvalidArgumentException::class);
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Get the text and subtitle resources that cannot be staged.
     */
    public static function invalidTypedResourceProvider(): array
    {
        return [
            'invalid UTF-8' => [fn (Media $media) => $media->export()
                ->textResource('bad', "\xff")],
            'null byte' => [fn (Media $media) => $media->export()
                ->textResource('bad', "a\0b")],
            'duplicate name' => [fn (Media $media, Media $subtitle) => $media->export()
                ->textResource('name', 'literal')
                ->subtitleResource('name', $subtitle)],
            'keyframe seek' => [fn (Media $media, Media $subtitle) => $media->export()
                ->subtitleResource('captions', $subtitle)
                ->clip(1, duration: 1, seek: 'keyframe')
                ->inspect()],
            'input options' => [fn (Media $media) => $media->export()
                ->subtitleResource('captions', FFMpeg::disk('missing')->open('captions.srt', ['-probesize', '2048']))],
        ];
    }

    public function test_literal_text_resources_are_written_unchanged_and_privately(): void
    {
        $text = "100%: café ' [x],; \\ %{not_a_function}\n";
        $resource = null;

        $this->fakePipeline(function (PendingProcess $process) use (&$resource) {
            $path = $process->path.'/resource-0.txt';
            $resource = [file_get_contents($path), fileperms($path) & 0777];

            file_put_contents($process->path.'/output-0.mkv', 'encoded');

            return Process::result();
        });

        FFMpeg::disk('memory')
            ->open('source.mkv')
            ->export()
            ->textResource('title', $text)
            ->videoFilter('drawtext=textfile={{title}}:expansion=none')
            ->toDisk('memory')
            ->save('text.mkv');

        $this->assertSame([$text, 0600], $resource);
        $this->assertSame('encoded', Storage::disk('memory')->get('text.mkv'));
    }

    public function test_missing_filter_support_fails_with_a_capability_error(): void
    {
        $text = "100%: café ' [x],; \\ %{not_a_function}\n";

        // This build answers the version check but lists no filters at all.
        Process::fake(function (PendingProcess $process) {
            $version = basename($process->command[0]).' version 9.0.2';

            return Process::result(output: in_array('-version', $process->command, true) ? $version : '');
        });

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')
                ->open('source.mkv')
                ->export()
                ->textResource('title', $text)
                ->videoFilter('drawtext=textfile={{title}}:expansion=none')
                ->toDisk('memory')
                ->save('unsupported.mkv'),
            fn (MediaException $exception) => $exception->category === 'capability',
        );
        $this->assertFalse(Storage::disk('memory')->exists('unsupported.mkv'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_probed_streams_map_to_their_absolute_index_without_more_io(): void
    {
        [$media, $video, $audio] = $this->probedStreams();

        $command = $media->export()
            ->mapAudio($audio)
            ->mapVideo($video)
            ->inspect();

        $positions = array_keys($command, '-map');

        $this->assertSame(7, $video->index());
        $this->assertSame(['0:9', '0:7'], array_map(fn ($i) => $command[$i + 1], $positions));
        Process::assertNothingRan();
    }

    public function test_streams_can_be_selected_by_track_or_language(): void
    {
        [$media] = $this->probedStreams();

        $this->assertContains('0:a:m:language:eng:?', $media->export()
            ->mapAudio(language: 'eng', optional: true)
            ->inspect());
        $this->assertContains('0:v:1', $media->export()->mapVideo(track: 1)->inspect());
        $this->assertContains('0:a:0', $media->export()->mapAudio()->inspect());
        $this->assertSame(
            $media->export()->mapVideo(input: 0)->mapAudio(input: 0)->inspect(),
            $media->export()->mapVideo(0)->mapAudio(0)->inspect(),
        );
        Process::assertNothingRan();
    }

    public function test_probed_streams_resolve_to_the_input_that_holds_their_source(): void
    {
        [$media, $video] = $this->probedStreams();

        $export = FFMpeg::disk('missing')->open('other.mkv')->export();
        $export->addInput($media);

        // Each map below adds to the same export, so the later commands also
        // contain the earlier maps; the assertions only look for the new one.
        $this->assertContains('1:7', $export->mapVideo($video)
            ->withoutMetadata()
            ->chaptersFrom(null)
            ->inspect());
        $this->assertContains('1:v:2', $export->mapVideo(1, track: 2)->inspect());
        $this->assertContains('1:a:m:language:eng:?', $export->mapAudio(1, language: 'eng', optional: true)->inspect());

        $repeated = $media->export();
        $repeated->addInput($media);

        $this->assertContains('1:7:?', $repeated
            ->output('one.mkv', fn ($out) => $out->mapVideo($video, input: 1, optional: true)
                ->withoutMetadata()
                ->chaptersFrom(null))
            ->inspect());
        Process::assertNothingRan();
    }

    #[DataProvider('invalidStreamSelectionProvider')]
    public function test_ambiguous_or_invalid_stream_selections_are_rejected(Closure $attempt): void
    {
        [$media, $video, $audio] = $this->probedStreams();

        $this->assertThrows(fn () => $attempt($media, $video, $audio), InvalidArgumentException::class);
        Process::assertNothingRan();
    }

    /**
     * Get the stream selections that are ambiguous or point at the wrong stream.
     */
    public static function invalidStreamSelectionProvider(): array
    {
        return [
            'audio stream as video' => [fn (Media $media, Stream $video, Stream $audio) => $media->export()
                ->mapVideo($audio)],
            'video stream as audio' => [fn (Media $media, Stream $video) => $media->export()
                ->mapAudio($video)],
            'stream with a track' => [fn (Media $media, Stream $video) => $media->export()
                ->mapVideo($video, track: 1)],
            'stream with a language' => [fn (Media $media, Stream $video, Stream $audio) => $media->export()
                ->mapAudio($audio, language: 'eng')],
            'track with a language' => [fn (Media $media) => $media->export()
                ->mapAudio(track: 0, language: 'eng')],
            'language with a specifier' => [fn (Media $media) => $media->export()
                ->mapAudio(language: 'eng:0')],
            'video input given twice' => [fn (Media $media) => $media->export()
                ->mapVideo(0, input: 1)],
            'audio input given twice' => [fn (Media $media) => $media->export()
                ->mapAudio(0, input: 1)],
            'negative input' => [fn (Media $media) => $media->export()
                ->mapAudio(-1)],
            'negative named input' => [fn (Media $media) => $media->export()
                ->mapVideo(input: -1)],
            'negative track' => [fn (Media $media) => $media->export()
                ->mapAudio(track: -1)],
            'stream of an absent source' => [fn (Media $media, Stream $video) => FFMpeg::disk('missing')
                ->open('other.mkv')
                ->export()
                ->mapVideo($video, optional: true)
                ->inspect()],
            'output after top-level maps' => [function (Media $media, Stream $video) {
                $export = FFMpeg::disk('missing')->open('other.mkv')->export();
                $export->addInput($media);

                $export->mapVideo($video)
                    ->withoutMetadata()
                    ->chaptersFrom(null)
                    ->mapVideo(1, track: 2)
                    ->mapAudio(1, language: 'eng', optional: true);

                return $export->output('one.mkv', fn () => null);
            }],
            'missing input' => [fn (Media $media, Stream $video) => $media->export()
                ->mapVideo($video, input: 9)
                ->inspect()],
            'input of another source' => [fn (Media $media, Stream $video) => FFMpeg::disk('missing')
                ->open('other.mkv')
                ->export()
                ->mapVideo($video, input: 0)
                ->inspect()],
            'source opened twice' => [function (Media $media, Stream $video) {
                $ambiguous = $media->export();
                $ambiguous->addInput($media);

                return $ambiguous->mapVideo($video)
                    ->withoutMetadata()
                    ->chaptersFrom(null)
                    ->inspect();
            }],
        ];
    }

    public function test_filter_shortcuts_keep_their_order_in_each_output(): void
    {
        Process::fake();

        $captions = FFMpeg::disk('missing')->open('captions.srt');

        $export = FFMpeg::disk('memory')
            ->open('source.mkv')
            ->export()
            ->output('one.mkv', fn ($out) => $out->burnSubtitles($captions)->resize(width: 80))
            ->output('two.mkv', fn ($out) => $out->resize(width: 80)->burnSubtitles($captions));

        $command = $export->inspect();

        $this->assertSame($command, $export->inspect());
        $this->assertContains('subtitles=filename=resource-0.srt,scale=w=80:h=-2:reset_sar=1', $command);
        $this->assertContains('scale=w=80:h=-2:reset_sar=1,subtitles=filename=resource-0.srt', $command);
        Process::assertNothingRan();
    }

    #[DataProvider('invalidResourceHelperProvider')]
    public function test_resource_helpers_reject_invalid_settings(Closure $attempt): void
    {
        Process::fake();

        $font = FFMpeg::disk('memory')->open('font.ttf');
        $captions = FFMpeg::disk('missing')->open('captions.srt');
        $media = FFMpeg::disk('memory')->open('source.mkv');

        $this->assertThrows(fn () => $attempt($media, $font, $captions), InvalidArgumentException::class);
        Process::assertNothingRan();
    }

    /**
     * Get the text and subtitle helper calls that cannot be drawn.
     */
    public static function invalidResourceHelperProvider(): array
    {
        return [
            'invalid UTF-8 text' => [fn (Media $media, Media $font) => $media->export()
                ->drawText("\xff", $font)],
            'zero font size' => [fn (Media $media, Media $font) => $media->export()
                ->drawText('text', $font, size: 0)],
            'option in the color' => [fn (Media $media, Media $font) => $media->export()
                ->drawText('text', $font, color: 'white:reload=1')],
            'subtitles with a keyframe seek' => [fn (Media $media, Media $font, Media $captions) => $media->export()
                ->burnSubtitles($captions)
                ->clip(1, duration: 1, seek: 'keyframe')
                ->inspect()],
            'subtitles with a copied video' => [fn (Media $media, Media $font, Media $captions) => $media->export()
                ->burnSubtitles($captions)
                ->video('copy')
                ->inspect()],
            'text after outputs' => [fn (Media $media, Media $font) => $media->export()
                ->output('one.mkv', fn () => null)
                ->drawText('text', $font)],
            'subtitles after outputs' => [fn (Media $media, Media $font, Media $captions) => $media->export()
                ->output('one.mkv', fn () => null)
                ->burnSubtitles($captions)],
        ];
    }

    public function test_output_owned_resources_are_private_and_shared_fonts_are_staged_once(): void
    {
        $font = FFMpeg::disk('memory')->open('font.ttf');
        $texts = ["100% {{font}}: café \\ ' [x],; %{not_a_function}", 'different output'];
        $retained = null;
        $resources = null;

        Storage::disk('memory')->put('font.ttf', 'font bytes');

        $this->fakePipeline(function (PendingProcess $process) use (&$resources) {
            $staged = glob($process->path.'/resource-*');

            $resources = [
                'fonts' => count(glob($process->path.'/resource-*.ttf')),
                'texts' => array_map('file_get_contents', glob($process->path.'/resource-*.txt')),
                'modes' => array_unique(array_map(fn ($resource) => fileperms($resource) & 0777, $staged)),
            ];

            file_put_contents($process->path.'/output-0.mkv', 'encoded');
            file_put_contents($process->path.'/output-1.mkv', 'encoded');

            return Process::result();
        });

        FFMpeg::disk('memory')
            ->open('source.mkv')
            ->export()
            ->output('one.mkv', function ($out) use (&$retained, $texts, $font) {
                $retained = $out;

                $out->drawText($texts[0], $font)
                    ->mapVideo()
                    ->toDisk('memory');
            })
            ->output('two.mkv', fn ($out) => $out->drawText($texts[1], $font)
                ->mapVideo()
                ->toDisk('memory'))
            ->save();

        $this->assertSame(1, $resources['fonts']);
        $this->assertEqualsCanonicalizing($texts, $resources['texts']);
        $this->assertSame([0600], array_values($resources['modes']));
        $this->assertSame('encoded', Storage::disk('memory')->get('one.mkv'));
        $this->assertSame('encoded', Storage::disk('memory')->get('two.mkv'));
        $this->assertThrows(fn () => $retained->drawText('late change', $font), LogicException::class);
        $this->assertWorkspacesAreCleanedUp();
    }

    #[TestWith(['drawText', 'staging'])]
    #[TestWith(['burnSubtitles', 'probing'])]
    public function test_unreadable_helper_resources_fail_without_publishing(string $helper, string $phase): void
    {
        $this->fakePipeline();

        Storage::disk('memory')->put('invalid.srt', "\xff");

        $export = FFMpeg::disk('memory')
            ->open('source.mkv')
            ->export()
            ->output('failed-'.$helper.'.mkv', function ($out) use ($helper) {
                $out->toDisk('memory');

                if ($helper === 'drawText') {
                    $out->drawText('text', FFMpeg::disk('memory')->open('missing-font.ttf'));
                } else {
                    $out->burnSubtitles(FFMpeg::disk('memory')->open('invalid.srt'));
                }
            });

        $this->assertThrows(
            fn () => $export->save(),
            fn (MediaException $exception) => str_contains($exception->getMessage(), 'output 0 '.$helper)
                && $exception->phase === $phase,
        );
        $this->assertFalse(Storage::disk('memory')->exists('failed-'.$helper.'.mkv'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_repeated_inputs_share_a_transfer_but_keep_their_input_ids(): void
    {
        // The disk may be read only once, even though the same media is added twice.
        $disk = Mockery::mock(Filesystem::class);

        $disk->shouldReceive('size')
            ->once()
            ->with('same.mkv')
            ->andReturn(5);

        $disk->shouldReceive('readStream')
            ->once()
            ->with('same.mkv')
            ->andReturnUsing(function () {
                $stream = fopen('php://temp', 'w+b');

                fwrite($stream, 'media');
                rewind($stream);

                return $stream;
            });

        $this->fakePipeline();

        $media = FFMpeg::disk($disk)->open('same.mkv');
        $export = $media->export();
        $export->addInput($media);

        $result = $export
            ->output('selected.mkv', fn ($out) => $out->map('1:v')
                ->withoutMetadata()
                ->chaptersFrom(null)
                ->toDisk('memory'))
            ->save();

        $command = $result->processes()->first()['command'];

        $this->assertSame('encoded', Storage::disk('memory')->get('selected.mkv'));
        $this->assertCount(2, array_keys($command, '-i'));
        $this->assertContains('1:v', $command);
        $this->assertWorkspacesAreCleanedUp();
    }

    #[TestWith(['missing', 'artifact'])]
    #[TestWith(['extra-stream', 'mapping'])]
    #[TestWith(['wrong-type', 'mapping'])]
    public function test_every_output_is_validated_before_the_first_publication(string $failure, string $category): void
    {
        // The first output is always valid. The second is missing, has an extra
        // audio stream, or holds audio instead of video, so neither may be published.
        $encode = function (PendingProcess $process) use ($failure) {
            file_put_contents($process->path.'/output-0.mkv', 'valid');

            if ($failure !== 'missing') {
                file_put_contents($process->path.'/output-1.mkv', 'invalid');
            }

            return Process::result();
        };

        $probe = function (PendingProcess $process) use ($failure) {
            $second = str_ends_with(end($process->command), 'output-1.mkv');

            $streams = $second && $failure === 'extra-stream'
                ? [['index' => 0, 'codec_type' => 'video'], ['index' => 1, 'codec_type' => 'audio']]
                : [['index' => 0, 'codec_type' => $second && $failure === 'wrong-type' ? 'audio' : 'video']];

            return json_encode(['format' => ['duration' => '2'], 'streams' => $streams]);
        };

        $this->fakePipeline($encode, $probe);

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')
                ->open('source.mkv')
                ->export()
                ->output('first.mkv', fn ($out) => $out->map('0:v')->toDisk('memory'))
                ->output('second.mkv', fn ($out) => $out->map('0:v')->toDisk('memory'))
                ->save(),
            fn (MediaException $exception) => $exception->phase === 'validation' && $exception->category === $category,
        );
        $this->assertFalse(Storage::disk('memory')->exists('first.mkv'));
        $this->assertFalse(Storage::disk('memory')->exists('second.mkv'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_partial_publication_reports_the_successful_sibling_without_deleting_it(): void
    {
        $this->fakePipeline();

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturn(false);

        $this->assertThrows(
            fn () => FFMpeg::disk('memory')
                ->open('source.mkv')
                ->export()
                ->output('first.mkv', fn ($out) => $out->toDisk('memory')->map('0:v'))
                ->output('second.mkv', fn ($out) => $out->toDisk($disk)->map('0:v'))
                ->save(),
            fn (MediaException $exception) => $exception->phase === 'publication'
                && $exception->published === [['disk' => 'memory', 'path' => 'first.mkv']]
                && $exception->destination === ['disk' => null, 'path' => 'second.mkv'],
        );
        $this->assertSame('encoded', Storage::disk('memory')->get('first.mkv'));
        $this->assertWorkspacesAreCleanedUp();
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    public function test_cancellation_cleans_resources_and_outputs_without_publication(): void
    {
        $this->fakePipeline();

        Storage::disk('memory')->put('look.cube', 'a LUT');

        $cancelled = new ExportCancelled('cancel composition');

        $export = FFMpeg::disk('memory')
            ->open('source.mkv')
            ->export()
            ->resource('look', FFMpeg::disk('memory')->open('look.cube'))
            ->output('first.mkv', fn ($out) => $out->videoFilter('lut3d=file={{look}}')->toDisk('memory'))
            ->output('second.mkv', fn ($out) => $out->toDisk('memory'))
            ->onProgress(function (Progress $progress) use ($cancelled) {
                if ($progress->phase === 'encoding') {
                    throw $cancelled;
                }
            });

        $this->assertThrows(
            fn () => $export->save(),
            fn (ExportCancelled $exception) => $exception === $cancelled,
        );
        $this->assertWorkspacesAreCleanedUp();
        $this->assertFalse(Storage::disk('memory')->exists('first.mkv'));
        $this->assertFalse(Storage::disk('memory')->exists('second.mkv'));
        $this->assertSame('a LUT', Storage::disk('memory')->get('look.cube'));
        Event::assertDispatched(Events\ExportCancelled::class);
    }

    /**
     * Save two outputs with the same path on different disks and a third nested output.
     */
    protected function saveGeneratedFiles(): ExportResult
    {
        $this->fakePipeline();
        $this->fakeLocalDisk();

        return FFMpeg::disk('memory')
            ->open('source.mkv')
            ->export()
            ->output('full.mkv', fn ($out) => $out->mapVideo(0)->toDisk('memory'))
            ->output('full.mkv', fn ($out) => $out->mapVideo(0)->toDisk('local'))
            ->output('nested/preview.mkv', fn ($out) => $out->mapVideo(0)->toDisk('memory'))
            ->save();
    }

    public function test_generated_files_can_be_selected_by_path_and_disk(): void
    {
        $result = $this->saveGeneratedFiles();

        $this->assertSame('full.mkv', $result->file()->path);
        $this->assertSame('memory', $result->file()->disk);
        $this->assertSame('nested/preview.mkv', $result->file('nested/preview.mkv')->path);
        $this->assertSame('local', $result->file('full.mkv', disk: 'local')->disk);
        $this->assertSame('memory', $result->file('full.mkv', disk: 'memory')->disk);
        $this->assertSame('full.mkv', $result->file(disk: 'local')->path);
        $this->assertCount(3, $result->files());
        $this->assertSame('memory', $result->file()->disk);
        $this->assertSame('encoded', Storage::disk('local')->get($result->file('full.mkv', disk: 'local')->path));
    }

    #[DataProvider('ambiguousFileLookupProvider')]
    public function test_file_lookups_must_identify_exactly_one_generated_file(
        array $arguments,
        string $exception,
    ): void {
        $result = $this->saveGeneratedFiles();

        $this->expectException($exception);

        $result->file(...$arguments);
    }

    /**
     * Get the file lookups that match no generated file or more than one.
     */
    public static function ambiguousFileLookupProvider(): array
    {
        return [
            'unknown path' => [['missing.mkv'], ItemNotFoundException::class],
            'path on another disk' => [['nested/preview.mkv', 'disk' => 'local'], ItemNotFoundException::class],
            'path on two disks' => [['full.mkv'], MultipleItemsFoundException::class],
            'disk with two files' => [['disk' => 'memory'], MultipleItemsFoundException::class],
        ];
    }

    /**
     * Get an export whose watermark edit the image driver rejects when it processes the logo.
     */
    protected function unprocessableWatermark(): PendingExport
    {
        $this->fakeLocalDisk();
        $this->addTestFile('logo.png');
        $this->addTestFile('video.mp4');

        // The driver rejects the background color only when it processes the image,
        // and the color stands in for a private value that must never reach the
        // exception message the application might log or show.
        return FFMpeg::disk('local')
            ->open('video.mp4')
            ->export()
            ->video('ffv1')
            ->watermark(
                FFMpeg::disk('local')->open('logo.png'),
                fn (Watermark $watermark) => $watermark->rotate(10, background: 'application-secret'),
            );
    }

    public function test_watermark_edits_are_deferred_until_execution(): void
    {
        Process::fake();

        $export = $this->unprocessableWatermark();

        $this->assertContains('[input:1]', $export->inspect());
        Process::assertNothingRan();
    }

    public function test_failed_watermark_edits_keep_the_original_and_publish_nothing(): void
    {
        Process::fake();

        $export = $this->unprocessableWatermark();
        $hash = hash_file('sha256', Storage::disk('local')->path('logo.png'));

        $this->assertThrows(
            fn () => $export->toDisk('memory')->save('failed.mkv'),
            fn (MediaException $exception) => $exception->phase === 'staging'
                && $exception->category === 'resource'
                && $exception->getPrevious() === null
                && ! str_contains($exception->getMessage(), 'application-secret'),
        );
        $this->assertFalse(Storage::disk('memory')->exists('failed.mkv'));
        $this->assertSame($hash, hash_file('sha256', Storage::disk('local')->path('logo.png')));
        $this->assertWorkspacesAreCleanedUp();
        Process::assertNothingRan();
    }

    public function test_watermark_placement_reads_as_edge_margins_in_any_order(): void
    {
        $this->fakeLocalDisk();

        $media = FFMpeg::disk('local')->open('video.mp4');
        $logo = FFMpeg::disk('local')->open('logo.png');

        $overlay = function (?callable $configure) use ($media, $logo): string {
            $command = $media->export()
                ->watermark($logo, $configure)
                ->video('libx264')
                ->inspect();

            $graph = $command[array_search('-filter_complex', $command, true) + 1];

            preg_match('/overlay=x=([^:]+):y=([^:]+):/', $graph, $match);

            return $match[1].' '.$match[2];
        };

        $this->assertSame('main_w-overlay_w-0 main_h-overlay_h-0', $overlay(null));
        $this->assertSame(
            '25 main_h-overlay_h-10',
            $overlay(fn (Watermark $watermark) => $watermark->left(25)->bottom(10)),
        );
        $this->assertSame('main_w-overlay_w-5 3', $overlay(fn (Watermark $watermark) => $watermark->top(3)->right(5)));
        $this->assertSame(
            '(main_w-overlay_w)/2 main_h-overlay_h-40',
            $overlay(fn (Watermark $watermark) => $watermark->center()->bottom(40)),
        );
        $this->assertSame(
            '(main_w-overlay_w)/2 main_h-overlay_h-40',
            $overlay(fn (Watermark $watermark) => $watermark->bottom(40)->center()),
        );
        $this->assertSame(
            '(main_w-overlay_w)/2 (main_h-overlay_h)/2',
            $overlay(fn (Watermark $watermark) => $watermark->center()),
        );

        // Repeating an edge replaces its margin; the callback's return value is ignored.
        $this->assertSame('main_w-overlay_w-9 main_h-overlay_h-0', $overlay(function (Watermark $watermark) {
            $watermark->right(1)->right(9);

            return 'ignored';
        }));
    }

    #[DataProvider('invalidWatermarkProvider')]
    public function test_invalid_watermark_settings_are_rejected(Closure $invalid): void
    {
        $this->fakeLocalDisk();

        $media = FFMpeg::disk('local')->open('video.mp4');
        $logo = FFMpeg::disk('local')->open('logo.png');

        $this->expectException(InvalidArgumentException::class);

        $media->export()->watermark($logo, $invalid);
    }

    /**
     * Get the watermark settings that conflict or fall outside their range.
     */
    public static function invalidWatermarkProvider(): array
    {
        return [
            'left and right' => [fn (Watermark $watermark) => $watermark->left()->right()],
            'bottom and top' => [fn (Watermark $watermark) => $watermark->bottom()->top()],
            'negative margin' => [fn (Watermark $watermark) => $watermark->left(-1)],
            'size without dimensions' => [fn (Watermark $watermark) => $watermark->size()],
            'zero width' => [fn (Watermark $watermark) => $watermark->size(width: 0)],
            'opacity above one' => [fn (Watermark $watermark) => $watermark->opacity(1.5)],
            'blur above 100' => [fn (Watermark $watermark) => $watermark->blur(101)],
            'empty crop' => [fn (Watermark $watermark) => $watermark->crop(0, 10, 0, 0)],
            'infinite rotation' => [fn (Watermark $watermark) => $watermark->rotate(INF)],
        ];
    }

    #[DataProvider('imageWatermarkProvider')]
    public function test_image_watermarks_keep_alpha_offsets_duration_and_audio(string $disk, bool $flipped): void
    {
        $binary = $this->useRealFFMpeg();
        $local = $this->fakeLocalDisk();
        copy($this->fixture('red-with-audio.mkv'), $local->path('source.mkv'));

        // The logo is 32 by 16 pixels: an opaque blue square on the left and a
        // fully transparent square on the right, so the flip and the alpha
        // channel both show up in the pixels of the watermarked frames.
        $bitmap = imagecreatetruecolor(32, 16);
        imagealphablending($bitmap, false);
        imagesavealpha($bitmap, true);
        imagefill($bitmap, 0, 0, imagecolorallocatealpha($bitmap, 0, 0, 0, 127));
        imagefilledrectangle($bitmap, 0, 0, 15, 15, imagecolorallocatealpha($bitmap, 0, 0, 255, 0));
        imagepng($bitmap, $this->root.'/logo.png');

        $bytes = file_get_contents($this->root.'/logo.png');
        $key = "logos/blue 'é.png";

        Storage::disk($disk)->put($key, $bytes);

        FFMpeg::disk('local')
            ->open('source.mkv')
            ->export()
            ->resize(width: 80, height: 60)
            ->watermark(FFMpeg::disk($disk)->open($key), fn (Watermark $watermark) => $watermark->right(7)
                ->bottom(5)
                ->size(16, 8)
                ->when($flipped, fn (Watermark $watermark) => $watermark->flipHorizontally()))
            ->video('ffv1')
            ->audio('copy')
            ->withoutMetadata(chapters: true)
            ->toDisk('memory')
            ->save('watermarked.mkv');

        $output = FFMpeg::disk('memory')->open('watermarked.mkv');

        $this->assertEqualsWithDelta(2, $output->probe()->duration(), 0.001);

        // The opaque half of the logo lands left of the transparent half unless the edit flips it.
        [$opaque, $transparent] = $flipped ? [69, 59] : [59, 69];
        $samples = [
            [$opaque, 49, 'blue'],
            [$transparent, 49, 'red'],
            [53, 49, 'red'],
            [59, 43, 'red'],
            [59, 57, 'red'],
        ];

        foreach ([0.2, 1.7] as $at) {
            $frame = imagecreatefromstring($output->frame(at: $at)->get());

            foreach ($samples as [$x, $y, $channel]) {
                $pixel = imagecolorsforindex($frame, imagecolorat($frame, $x, $y));

                $this->assertGreaterThan(220, $pixel[$channel]);
                $this->assertLessThan(35, $pixel[$channel === 'blue' ? 'red' : 'blue']);
            }

        }

        $local->put('watermarked.mkv', Storage::disk('memory')->get('watermarked.mkv'));

        $this->assertSame(
            $this->audioHash($binary, $local->path('source.mkv')),
            $this->audioHash($binary, $local->path('watermarked.mkv')),
        );
        $this->assertSame($bytes, Storage::disk($disk)->get($key));
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Get the disks the logo is read from, with and without a horizontal flip.
     */
    public static function imageWatermarkProvider(): array
    {
        return [
            'local logo' => ['local', false],
            'flipped local logo' => ['local', true],
            'memory logo' => ['memory', false],
            'flipped memory logo' => ['memory', true],
        ];
    }

    /**
     * Write a solid blue square logo to the given path.
     */
    protected function createBlueLogo(string $path): void
    {
        $bitmap = imagecreatetruecolor(8, 8);

        imagefill($bitmap, 0, 0, imagecolorallocate($bitmap, 0, 0, 255));
        imagepng($bitmap, $path);
    }

    /**
     * Put the short red video and a blue logo on the local disk and open both.
     */
    protected function openWatermarkSources(): array
    {
        $local = $this->fakeLocalDisk();

        copy($this->fixture('red-short.mkv'), $local->path('source.mkv'));

        $this->createBlueLogo($local->path('logo.png'));

        return [FFMpeg::disk('local')->open('source.mkv'), FFMpeg::disk('local')->open('logo.png')];
    }

    public function test_watermarks_are_drawn_after_each_outputs_resize(): void
    {
        [$media, $logo] = $this->openWatermarkSources();

        $export = $media->export()
            ->watermark($logo, fn (Watermark $watermark) => $watermark->left(4)->top(2))
            ->watermark($logo, fn (Watermark $watermark) => $watermark->center()->opacity(0.5))
            ->output('large.mkv', fn ($out) => $out->resize(80, 60)
                ->video('ffv1')
                ->withoutAudio()
                ->toDisk('memory'))
            ->output('small.mkv', fn ($out) => $out->resize(40, 30)
                ->video('ffv1')
                ->withoutAudio()
                ->toDisk('memory'));

        $this->assertSame($export->inspect(), $export->inspect());

        $export->save();

        // Each output keeps the corner logo at the same offset and the half-transparent
        // logo in its own center, which proves the overlay runs after the resize.
        foreach ([['large.mkv', 80, 60], ['small.mkv', 40, 30]] as [$path, $width, $height]) {
            $output = FFMpeg::disk('memory')->open($path);

            $this->assertEqualsWithDelta(1, $output->probe()->duration(), 0.001);

            $frame = imagecreatefromstring($output->frame(at: 0.8)->get());
            $blue = imagecolorsforindex($frame, imagecolorat($frame, 6, 4));
            $mixed = imagecolorsforindex($frame, imagecolorat($frame, intdiv($width, 2), intdiv($height, 2)));
            $red = imagecolorsforindex($frame, imagecolorat($frame, $width - 2, $height - 2));

            $this->assertSame($width, imagesx($frame));
            $this->assertSame($height, imagesy($frame));
            $this->assertGreaterThan(220, $blue['blue']);
            $this->assertEqualsWithDelta(127, $mixed['red'], 15);
            $this->assertEqualsWithDelta(127, $mixed['blue'], 15);
            $this->assertGreaterThan(220, $red['red']);

        }

        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_per_track_video_filters_run_before_the_watermark(): void
    {
        [$media, $logo] = $this->openWatermarkSources();

        // Per-track video filters run with the output filters, before the overlay.
        $colored = $media->export()
            ->watermark($logo)
            ->video('libx264')
            ->toDisk('memory')
            ->videoStream(0, fn ($video) => $video->color('bt709', 'bt709', 'bt709'));

        $this->assertNotContains('-filter:v:0', $colored->inspect());

        $colored->save('colored.mp4');

        $primaries = FFMpeg::disk('memory')
            ->open('colored.mp4')
            ->probe()
            ->video()
            ->get('color_primaries');

        $this->assertSame('bt709', $primaries);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_videos_cannot_become_static_watermarks(): void
    {
        [$media] = $this->openWatermarkSources();

        $this->assertThrows(
            fn () => $media->export()
                ->watermark($media)
                ->video('ffv1')
                ->save('invalid.mkv'),
            fn (MediaException $exception) => $exception->category === 'watermark',
        );
        $this->assertFalse(Storage::disk('local')->exists('invalid.mkv'));
        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('conflictingWatermarkProvider')]
    public function test_watermarks_reject_outputs_without_a_filtered_video(Closure $invalid): void
    {
        $local = $this->fakeLocalDisk();

        $this->createBlueLogo($local->path('logo.png'));

        $media = FFMpeg::disk('local')->open('source.mkv');
        $logo = FFMpeg::disk('local')->open('logo.png');

        $this->expectException(InvalidArgumentException::class);

        $invalid($media, $logo);
    }

    /**
     * Get the watermarks that conflict with the video settings of their export.
     */
    public static function conflictingWatermarkProvider(): array
    {
        return [
            'copied video' => [fn (Media $media, Media $logo) => $media->export()
                ->watermark($logo)
                ->video('copy')
                ->inspect()],
            'no video' => [fn (Media $media, Media $logo) => $media->export()
                ->watermark($logo)
                ->withoutVideo()
                ->inspect()],
            'explicit map' => [fn (Media $media, Media $logo) => $media->export()
                ->watermark($logo)
                ->map('0:v')
                ->inspect()],
            'complex graph' => [fn (Media $media, Media $logo) => $media->export()
                ->watermark($logo)
                ->filterComplex('[0:v]null[v]')
                ->inspect()],
            'undefined opacity' => [fn (Media $media, Media $logo) => $media->export()
                ->watermark($logo, fn (Watermark $watermark) => $watermark->opacity(NAN))],
            'looping image input' => [fn (Media $media) => $media->export()
                ->watermark(FFMpeg::disk('local')->open('logo.png', ['-ignore_loop', '0']))],
        ];
    }

    /**
     * Put the red video on the memory disk under a nested multibyte name and the blue video on the local disk.
     */
    protected function stageGraphSources(): FilesystemAdapter
    {
        $local = $this->fakeLocalDisk();

        Storage::disk('memory')->put('nested/red 日本語.mkv', file_get_contents($this->fixture('red.mkv')));
        copy($this->fixture('blue-with-tone.mkv'), $local->path('blue.mkv'));

        return $local;
    }

    /**
     * Store a .cube LUT that inverts RGB on the memory disk and get its contents.
     */
    protected function storeInvertingLut(string $key): string
    {
        // Invert RGB, with the red coordinate varying fastest as required by .cube.
        $lut = <<<'CUBE'
            LUT_3D_SIZE 2
            1 1 1
            0 1 1
            1 0 1
            0 0 1
            1 1 0
            0 1 0
            1 0 0
            0 0 0

            CUBE;

        Storage::disk('memory')->put($key, $lut);

        return $lut;
    }

    /**
     * Get the MD5 hash FFmpeg computes for the first audio stream of the given file.
     */
    protected function audioHash(string $ffmpeg, string $path): string
    {
        $audio = Process::run([$ffmpeg, '-v', 'error', '-i', $path, '-map', '0:a', '-f', 'md5', '-']);

        $this->assertProcessSucceeded($audio);

        return $audio->output();
    }

    /**
     * Get the RGB values of the pixel at the given offset in the first frame of the given file.
     */
    protected function firstFramePixel(string $ffmpeg, string $path, int $x, int $y, int $width): array
    {
        $pixels = Process::run([
            $ffmpeg, '-v', 'error', '-i', $path, '-map', '0:v',
            '-frames:v', '1', '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-',
        ]);

        $this->assertProcessSucceeded($pixels);

        return array_values(unpack('C*', substr($pixels->output(), ($y * $width + $x) * 3, 3)));
    }

    public function test_real_luts_are_staged_for_every_output(): void
    {
        $ffmpeg = $this->useRealFFMpeg();
        $local = $this->stageGraphSources();
        $lutKey = "looks/a 'quoted', 日本語: invert.cube";

        $this->storeInvertingLut($lutKey);

        $look = FFMpeg::disk('memory')->open($lutKey);

        $result = FFMpeg::disk('memory')
            ->open('nested/red 日本語.mkv')
            ->export()
            ->output('graded.mkv', fn ($out) => $out->mapVideo(0)
                ->lut3d($look)
                ->video('ffv1')
                ->withoutMetadata(chapters: true)
                ->toDisk('local'))
            ->output('graded-preview.mkv', fn ($out) => $out->mapVideo(0)
                ->lut3d($look)
                ->resize(40, 30)
                ->video('ffv1')
                ->withoutMetadata(chapters: true)
                ->toDisk('local'))
            ->save();

        $this->assertCount(2, $result->files());

        // The inverted red source turns cyan in both outputs.
        foreach (['graded.mkv' => [80, 60], 'graded-preview.mkv' => [40, 30]] as $path => [$width, $height]) {
            $metadata = FFMpeg::disk('local')->open($path)->probe();
            $rgb = $this->firstFramePixel($ffmpeg, $local->path($path), 0, 0, $width);

            $this->assertSame([$width, $height], [$metadata->video()->width(), $metadata->video()->height()]);
            $this->assertLessThan(25, $rgb[0]);
            $this->assertGreaterThan(225, $rgb[1]);
            $this->assertGreaterThan(225, $rgb[2]);
        }

        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_real_graph_outputs_on_mixed_disks_keep_independent_timelines(): void
    {
        $ffmpeg = $this->useRealFFMpeg();
        $local = $this->stageGraphSources();
        $lutKey = "looks/a 'quoted', 日本語: invert.cube";
        $lut = $this->storeInvertingLut($lutKey);

        $export = FFMpeg::disk('memory')->open('nested/red 日本語.mkv')->export();

        $this->assertSame(1, $export->addInput(FFMpeg::disk('local')->open('blue.mkv')));

        $result = $export->resource('look', FFMpeg::disk('memory')->open($lutKey))
            ->filterComplex('[0:v]lut3d=file={{look}},split[base][small];'
                .'[base][1:v]overlay=x=8:y=6[full];[small]scale=40:30[thumb]')
            ->output('composed.mkv', fn (ExportOutput $out) => $out->map('[full]')
                ->map('1:a')
                ->video('ffv1')
                ->audio('pcm_s16le')
                ->withoutMetadata()
                ->chaptersFrom(null)
                ->clip(0.4, duration: 0.8)
                ->toDisk('memory'))
            ->output('thumb.mkv', fn ($out) => $out->map('[thumb]')
                ->video('ffv1')
                ->metadataFrom(0)
                ->chaptersFrom(null)
                ->toDisk('local'))
            ->output('sound.wav', fn ($out) => $out->map('1:a')
                ->audio('copy')
                ->withoutMetadata()
                ->chaptersFrom(null)
                ->toDisk('local'))
            ->save();

        $composed = FFMpeg::disk('memory')->open('composed.mkv')->probe();
        $thumb = FFMpeg::disk('local')->open('thumb.mkv')->probe();
        $sound = FFMpeg::disk('local')->open('sound.wav')->probe();

        $this->assertCount(3, $result->files());
        $this->assertCount(1, $result->processes());
        $this->assertSame(['video', 'audio'], $composed->streams()
            ->map(fn ($stream) => $stream->get('codec_type'))
            ->all());
        $this->assertSame([80, 60], [$composed->video()->width(), $composed->video()->height()]);
        $this->assertEqualsWithDelta(0.8, $composed->duration(), 0.11);
        $this->assertSame([40, 30], [$thumb->video()->width(), $thumb->video()->height()]);
        $this->assertEqualsWithDelta(2, $thumb->duration(), 0.11);
        $this->assertCount(1, $sound->streams());
        $this->assertEqualsWithDelta(2, $sound->duration(), 0.01);
        $this->assertSame($lut, Storage::disk('memory')->get($lutKey));

        $local->put('composed.mkv', Storage::disk('memory')->get('composed.mkv'));

        // The center of the composed frame shows the inverted red base as cyan, and the
        // pixel at (12, 10) lies inside the blue video overlaid at (8, 6).
        $rgb = $this->firstFramePixel($ffmpeg, $local->path('composed.mkv'), 40, 30, 80);
        $blue = $this->firstFramePixel($ffmpeg, $local->path('composed.mkv'), 12, 10, 80);

        $this->assertLessThan(25, $rgb[0]);
        $this->assertGreaterThan(225, $rgb[1]);
        $this->assertGreaterThan(225, $rgb[2]);
        $this->assertLessThan(25, $blue[0]);
        $this->assertLessThan(25, $blue[1]);
        $this->assertGreaterThan(225, $blue[2]);
        $this->assertSame(
            $this->audioHash($ffmpeg, $local->path('blue.mkv')),
            $this->audioHash($ffmpeg, $local->path('sound.wav')),
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_real_retained_outputs_are_frozen_once_execution_starts(): void
    {
        $this->useRealFFMpeg();
        $this->stageGraphSources();

        $retained = null;

        $export = FFMpeg::disk('memory')->open('nested/red 日本語.mkv')->export();
        $export->addInput(FFMpeg::disk('local')->open('blue.mkv'));

        $export->filterComplex('[0:v]split[base][small];[base][1:v]overlay=x=8:y=6[full];[small]scale=40:30[thumb]')
            ->output('composed.mkv', function (ExportOutput $out) use (&$retained) {
                $retained = $out;

                $out->map('[full]')
                    ->map('1:a')
                    ->video('ffv1')
                    ->audio('pcm_s16le')
                    ->withoutMetadata()
                    ->chaptersFrom(null)
                    ->clip(0.4, duration: 0.8)
                    ->toDisk('memory');
            })
            ->output('thumb.mkv', fn ($out) => $out->map('[thumb]')
                ->video('ffv1')
                ->metadataFrom(0)
                ->chaptersFrom(null)
                ->toDisk('local'))
            ->save();

        $this->assertThrows(fn () => $retained->video('copy'), LogicException::class);
        $this->assertThrows(fn () => $retained->streamMetadata('a', 'language', 'eng'), LogicException::class);
        $this->assertThrows(fn () => $retained->disposition('a', 'default'), LogicException::class);
    }

    public function test_real_graph_outputs_can_copy_audio_from_another_input(): void
    {
        $ffmpeg = $this->useRealFFMpeg();
        $local = $this->stageGraphSources();

        $export = FFMpeg::disk('memory')->open('nested/red 日本語.mkv')->export();
        $export->addInput(FFMpeg::disk('local')->open('blue.mkv'));

        $export->filterComplex('[0:v]null[video]')
            ->map('[video]')
            ->map('1:a')
            ->video('ffv1')
            ->audio('copy')
            ->withoutMetadata()
            ->chaptersFrom(null)
            ->toDisk('local')
            ->save('copied-audio.mkv');

        $copied = FFMpeg::disk('local')->open('copied-audio.mkv')->probe();

        $this->assertCount(2, $copied->streams());
        $this->assertSame('pcm_s16le', $copied->audio()->get('codec_name'));
        $this->assertSame([80, 60], [$copied->video()->width(), $copied->video()->height()]);
        $this->assertSame(
            $this->audioHash($ffmpeg, $local->path('blue.mkv')),
            $this->audioHash($ffmpeg, $local->path('copied-audio.mkv')),
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_real_stream_metadata_and_dispositions_are_scoped_to_each_output(): void
    {
        $this->useRealFFMpeg();
        $this->stageGraphSources();

        $title = "English 'mix'; 日本語 = literal";

        // The second output sets and replaces the same tags and dispositions several
        // times; only the last value for each stream may reach the file.
        FFMpeg::disk('local')
            ->open('blue.mkv')
            ->export()
            ->filterComplex('[0:a]asplit[english][german]')
            ->output('tagged-first.mkv', fn ($out) => $out->map('[english]')
                ->mapVideo(0)
                ->video('copy')
                ->audio('pcm_s16le')
                ->withoutMetadata()
                ->chaptersFrom(null)
                ->streamMetadata(0, 'language', 'eng')
                ->streamMetadata(0, 'title', $title)
                ->disposition(0, ['default', 'forced'])
                ->disposition('v:0', '0')
                ->toDisk('memory'))
            ->output('tagged-second.mkv', fn ($out) => $out->mapVideo(0)
                ->map('[german]')
                ->video('copy')
                ->audio('pcm_s16le')
                ->withoutMetadata()
                ->chaptersFrom(null)
                ->streamMetadata('a', 'language', 'fra')
                ->streamMetadata('a:0', 'language', 'spa')
                ->streamMetadata('a', 'language', 'deu')
                ->streamMetadata('a:0', 'title', 'Remove me')
                ->streamMetadata('a:0', 'title', '')
                ->disposition('a', [])
                ->disposition('a:0', 'forced')
                ->disposition('a', [])
                ->toDisk('local'))
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
    }

    /**
     * Render the first frame of the given file as raw grayscale bytes.
     */
    protected function firstGrayFrame(string $ffmpeg, string $path): string
    {
        $frame = Process::run([
            $ffmpeg, '-v', 'error', '-i', $path, '-frames:v', '1', '-pix_fmt', 'gray', '-f', 'rawvideo', '-',
        ]);

        $this->assertProcessSucceeded($frame);

        return $frame->output();
    }

    public function test_real_fonts_and_literal_text_render_without_filter_or_percent_expansion(): void
    {
        $ffmpeg = $this->useRealFFMpeg();
        $local = $this->fakeLocalDisk();
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        $text = "100% 'quoted': \\ [x],; café\n%{not_a_function}";
        $options = 'expansion=none:fontcolor=white:fontsize=20:x=8:y=8';

        $this->assertFileExists($font, 'Native resource acceptance requires DejaVu Sans.');

        Storage::disk('memory')->put("fonts/a 'quoted', café.ttf", file_get_contents($font));

        $generation = Process::run([
            $ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'color=black:size=480x120:rate=10:duration=0.2',
            '-c:v', 'ffv1', $local->path('black.mkv'),
        ]);

        $this->assertProcessSucceeded($generation);

        FFMpeg::disk('local')
            ->open('black.mkv')
            ->export()
            ->resource('font', FFMpeg::disk('memory')->open("fonts/a 'quoted', café.ttf"))
            ->textResource('title', $text)
            ->videoFilter('drawtext=fontfile={{font}}:textfile={{title}}:'.$options)
            ->video('ffv1')
            ->toDisk('local')
            ->save('title.mkv');

        // Compare against a direct render from the unchanged literal file, not PHP escaping.
        file_put_contents($this->root.'/literal.txt', $text);

        $reference = Process::run([
            $ffmpeg, '-v', 'error', '-i', $local->path('black.mkv'),
            '-vf', 'drawtext=fontfile='.$font.':textfile='.$this->root.'/literal.txt:'.$options,
            '-frames:v', '1', '-pix_fmt', 'gray', '-f', 'rawvideo', '-',
        ]);

        $this->assertProcessSucceeded($reference);

        $actual = $this->firstGrayFrame($ffmpeg, $local->path('title.mkv'));

        $this->assertSame($reference->output(), $actual);
        $this->assertGreaterThan(200, count(array_filter(unpack('C*', $actual), fn ($pixel) => $pixel > 200)));

        FFMpeg::disk('local')
            ->open('black.mkv')
            ->export()
            ->drawText($text, font: FFMpeg::disk('memory')->open("fonts/a 'quoted', café.ttf"), size: 20, x: 8, y: 8)
            ->video('ffv1')
            ->toDisk('local')
            ->save('title-helper.mkv');

        $this->assertSame($reference->output(), $this->firstGrayFrame($ffmpeg, $local->path('title-helper.mkv')));
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Copy the six second black video to burn subtitles into to the local disk.
     */
    protected function createSubtitleCanvas(): FilesystemAdapter
    {
        $local = $this->fakeLocalDisk();

        copy($this->fixture('black-captions.mkv'), $local->path('black.mkv'));

        return $local;
    }

    /**
     * Decode every frame of the given file to raw grayscale bytes.
     */
    protected function grayFrames(string $ffmpeg, string $path): string
    {
        $frames = Process::run([$ffmpeg, '-v', 'error', '-i', $path, '-pix_fmt', 'gray', '-f', 'rawvideo', '-']);

        $this->assertProcessSucceeded($frames);

        return $frames->output();
    }

    #[DataProvider('subtitleFormatProvider')]
    public function test_real_subtitles_keep_cue_timing_through_accurate_clips(string $extension, string $content): void
    {
        $ffmpeg = $this->useRealFFMpeg();
        $local = $this->createSubtitleCanvas();
        $key = "captions/a 'quoted', café.".$extension;
        $frameBytes = 160 * 90;

        Storage::disk('memory')->put($key, $content);

        FFMpeg::disk('local')
            ->open('black.mkv')
            ->export()
            ->subtitleResource('captions', FFMpeg::disk('memory')->open($key))
            ->videoFilter('subtitles=filename={{captions}}')
            ->video('ffv1')
            ->clip(4, duration: 2)
            ->toDisk('local')
            ->save('caption-'.$extension.'.mkv');

        $frames = $this->grayFrames($ffmpeg, $local->path('caption-'.$extension.'.mkv'));

        $this->assertSame(20 * $frameBytes, strlen($frames));

        // The clip starts at four seconds, so the cue from 5.0 to 5.5 seconds is
        // visible in frame 12 of the clip and not in frames 2 or 18.
        foreach ([2 => false, 12 => true, 18 => false] as $index => $visible) {
            $pixels = unpack('C*', substr($frames, $index * $frameBytes, $frameBytes));

            $lit = count(array_filter($pixels, fn ($pixel) => $pixel > 100));

            $this->assertSame($visible, $lit > 10, $extension.' frame '.$index);
        }

        $this->assertSame($content, Storage::disk('memory')->get($key));

        FFMpeg::disk('local')
            ->open('black.mkv')
            ->export()
            ->burnSubtitles(FFMpeg::disk('memory')->open($key))
            ->video('ffv1')
            ->clip(4, duration: 2)
            ->toDisk('local')
            ->save('caption-helper-'.$extension.'.mkv');

        $this->assertSame($frames, $this->grayFrames($ffmpeg, $local->path('caption-helper-'.$extension.'.mkv')));
    }

    /**
     * Get the subtitle formats with a single cue from five to five and a half seconds.
     */
    public static function subtitleFormatProvider(): array
    {
        return [
            'srt' => ['srt', <<<'SRT'
                1
                00:00:05,000 --> 00:00:05,500
                Caption café

                SRT],
            'vtt' => ['vtt', <<<'CUES'
                WEBVTT

                00:05.000 --> 00:05.500
                Caption café

                CUES],
            'ass' => ['ass', <<<'ASS'
                [Script Info]
                ScriptType: v4.00+
                PlayResX: 160
                PlayResY: 90
                [V4+ Styles]
                Format: Name, Fontname, Fontsize, PrimaryColour, Alignment
                Style: Default,DejaVu Sans,14,&H00FFFFFF,2
                [Events]
                Format: Layer, Start, End, Style, Text
                Dialogue: 0,0:00:05.00,0:00:05.50,Default,Caption café

                ASS],
        ];
    }

    #[DataProvider('forgedSubtitleProvider')]
    public function test_real_subtitle_resources_reject_content_that_is_not_subtitles(string $invalid): void
    {
        $this->useRealFFMpeg();
        $this->createSubtitleCanvas();

        Storage::disk('memory')->put('forged.srt', $invalid);

        $this->assertThrows(
            fn () => FFMpeg::disk('local')
                ->open('black.mkv')
                ->export()
                ->subtitleResource('captions', FFMpeg::disk('memory')->open('forged.srt'))
                ->videoFilter('subtitles=filename={{captions}}')
                ->video('ffv1')
                ->toDisk('memory')
                ->save('rejected.mkv'),
            fn (MediaException $exception) => $exception->phase === 'probing' && $exception->category === 'resource',
        );
        $this->assertFalse(Storage::disk('memory')->exists('rejected.mkv'));
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Get the files that carry a subtitle extension without valid subtitle content.
     */
    public static function forgedSubtitleProvider(): array
    {
        return [
            'playlist' => [<<<'M3U8'
                #EXTM3U
                #EXT-X-TARGETDURATION:1
                #EXTINF:1,
                sibling.ts

                M3U8],
            'concat list' => ["ffconcat version 1.0\nfile 'sibling.ts'\n"],
            'invalid UTF-8' => ["1\n00:00:00,000 --> 00:00:01,000\n\xff\n"],
        ];
    }

    #[TestWith(['copy', 'Streamcopy requested for output stream fed from a complex filtergraph'])]
    #[TestWith(['filter', 'Simple and complex filtering cannot be used together'])]
    public function test_real_type_scoped_graph_conflicts_fail_without_publishing(
        string $conflict,
        string $diagnostic,
    ): void {
        $this->useRealFFMpeg();

        $export = FFMpeg::disk('local')
            ->open(new File(__DIR__.'/src/guitar.m4a'))
            ->export()
            ->filterComplex('[0:a]anull[a]')
            ->map('[a]')
            ->toDisk('memory');

        if ($conflict === 'copy') {
            $export->audio('copy');
        } else {
            $export->audio('flac')->audioFilter('volume=0.5');
        }

        $this->assertThrows(
            fn () => $export->save('conflict.mkv'),
            function (MediaException $exception) use ($diagnostic) {
                $this->assertSame('encoding', $exception->phase);
                $this->assertSame('configuration', $exception->category, $exception->diagnostics);
                $this->assertStringContainsString($diagnostic, $exception->diagnostics);

                return true;
            },
        );
        $this->assertFalse(Storage::disk('memory')->exists('conflict.mkv'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_real_unlabeled_graph_pads_are_rejected_before_publication(): void
    {
        $this->useRealFFMpeg();

        $media = FFMpeg::disk('local')->open(new File(__DIR__.'/src/video.mp4'));

        // split has an unlabeled second pad. FFmpeg silently adds it to output zero.
        $this->assertThrows(
            fn () => $media->export()
                ->filterComplex('[0:v]split=2[named]')
                ->map('[named]')
                ->video('ffv1')
                ->toDisk('memory')
                ->save('unexpected.mkv'),
            fn (MediaException $exception) => $exception->phase === 'validation' && $exception->category === 'mapping',
        );
        $this->assertFalse(Storage::disk('memory')->exists('unexpected.mkv'));
        $this->assertWorkspacesAreCleanedUp();
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\PendingConcat;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class ConcatTest extends TestCase
{
    /**
     * The directory holding the media fixtures shared by every test in the class.
     */
    protected static ?string $fixtures = null;

    /**
     * The paths of the delayed inputs opened by the current test.
     */
    protected array $delayedInputs = [];

    /**
     * The hashes of the delayed inputs before the current test used them.
     */
    protected array $delayedHashes = [];

    public static function tearDownAfterClass(): void
    {
        if (static::$fixtures !== null) {
            (new Filesystem)->deleteDirectory(static::$fixtures);

            static::$fixtures = null;
        }

        parent::tearDownAfterClass();
    }

    public function test_compatible_concat_inspection_is_pure_and_does_not_accept_direct_urls(): void
    {
        Process::fake();
        $first = FFMpeg::disk('missing')->open('first.mp4');
        $second = FFMpeg::disk('missing')->open('second.mp4');
        $command = $first->concat($second)->export()->video('copy')->inspect();
        $this->assertSame('concat', $command[array_search('-f', $command) + 1]);
        $this->assertSame('1', $command[array_search('-safe', $command) + 1]);
        Process::assertNothingRan();

        $this->expectException(InvalidArgumentException::class);
        $first->concat(FFMpeg::disk()->openUrl('https://media.example/second.mp4'));
    }

    #[TestWith(['contain'])]
    #[TestWith(['cover'])]
    #[TestWith(['stretch'])]
    public function test_transcoded_concat_inspection_owns_the_graph_and_is_pure(string $fit): void
    {
        Process::fake();
        $first = FFMpeg::disk('missing')->open('first.mp4');
        $second = FFMpeg::disk('missing')->open('second.mp4');

        $export = $first->concatTranscoded($second)->conformVideo(128, 96, '30000/1001', $fit)
            ->conformAudio()->video('libx264');
        $command = $export->inspect();
        $this->assertSame($command, $export->inspect());
        $graph = $command[array_search('-filter_complex', $command) + 1];
        $this->assertStringContainsString('[0:V:0]', $graph);
        $this->assertStringContainsString('[1:V:0]', $graph);
        $this->assertStringContainsString('fps=fps=30000/1001', $graph);
        $this->assertStringContainsString('aresample=48000:async=1:first_pts=0', $graph);
        $this->assertStringNotContainsString('asetpts', $graph);
        $this->assertContains('[concat_video]', $command);
        $this->assertContains('[concat_audio]', $command);
        $this->assertNotContains('99', $command);
        $this->assertSame('-1', $command[array_search('-map_metadata', $command) + 1]);
        $this->assertSame('-1', $command[array_search('-map_chapters', $command) + 1]);
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_a_raw_concat_graph_is_independent_of_the_helper_policy(): void
    {
        Process::fake();
        $first = FFMpeg::disk('missing')->open('first.mp4');
        $second = FFMpeg::disk('missing')->open('second.mp4');

        $raw = $first->export();
        $raw->addInput($second);
        $raw->filterComplex('[0:v][1:v]concat=n=2:v=1:a=0[joined]')
            ->map('[joined]')->withoutMetadata(chapters: true)->inspect();

        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_transcoded_concat_requires_another_input(): void
    {
        Process::fake();

        $this->assertThrows(
            fn () => FFMpeg::disk('missing')->open('first.mp4')->concatTranscoded(),
            InvalidArgumentException::class,
        );

        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('invalidConcatPolicyProvider')]
    public function test_transcoded_concat_rejects_ambiguous_or_unsafe_policies_without_io(Closure $configure): void
    {
        Process::fake();
        $first = FFMpeg::disk('missing')->open('first.mp4');
        $second = FFMpeg::disk('missing')->open('second.mp4');

        $this->assertThrows(fn () => $configure($first->concatTranscoded($second)), InvalidArgumentException::class);

        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function invalidConcatPolicyProvider(): array
    {
        return [
            'no conform policy' => [fn (PendingConcat $concat) => $concat->inspect()],
            'no audio policy' => [fn (PendingConcat $concat) => $concat->conformVideo(128, 96, 10)->inspect()],
            'no video policy' => [fn (PendingConcat $concat) => $concat->conformAudio()->inspect()],
            'copied video' => [fn (PendingConcat $concat) => $concat->conformVideo(128, 96, 10)->withoutAudio()->video('copy')->inspect()],
            'copied audio' => [fn (PendingConcat $concat) => $concat->conformVideo(128, 96, 10)->withoutAudio()->audio('copy')->inspect()],
            'audio policy without audio' => [fn (PendingConcat $concat) => $concat->conformVideo(128, 96, 10)->withoutAudio()->conformAudio()->inspect()],
            'odd width' => [fn (PendingConcat $concat) => $concat->conformVideo(127, 96, 10)],
            'zero denominator rate' => [fn (PendingConcat $concat) => $concat->conformVideo(128, 96, '10/0')],
            'filter syntax in the rate' => [fn (PendingConcat $concat) => $concat->conformVideo(128, 96, '10;null')],
            'not a number rate' => [fn (PendingConcat $concat) => $concat->conformVideo(128, 96, NAN)],
            'infinite rate' => [fn (PendingConcat $concat) => $concat->conformVideo(128, 96, INF)],
            'unknown fit' => [fn (PendingConcat $concat) => $concat->conformVideo(128, 96, 10, 'unknown')],
            'zero sample rate' => [fn (PendingConcat $concat) => $concat->conformAudio(0)],
            'three channels' => [fn (PendingConcat $concat) => $concat->conformAudio(channels: 3)],
            'negative channel' => [fn (PendingConcat $concat) => $concat->conformAudio(channel: -1)],
        ];
    }

    public function test_copy_concat_joins_compatible_inputs_in_order(): void
    {
        [$first, $second] = $this->openConcatInputs();
        $combined = $first->concat($second);
        $this->assertEqualsWithDelta(1.7, $combined->probe()->duration(), 0.001);

        $combined->export()->video('copy')->toDisk('memory')->save('copy.mp4');

        $copy = FFMpeg::disk('memory')->open('copy.mp4');
        $this->assertSame('h264', $copy->probe()->video()->codec());
        $this->assertEqualsWithDelta(1.7, $copy->probe()->duration(), 0.001);
        $this->assertFrameColor($copy, 0.2, 'red');
        $this->assertFrameColor($copy, 1.0, 'blue');
        $this->assertRedClipIsUnchanged();
    }

    public function test_concat_publishes_to_the_first_inputs_disk_by_default(): void
    {
        [$first, $second] = $this->openConcatInputs();

        // Without toDisk(), both helpers publish to the first input's disk, not the application default.
        $second->concat($first)->export()->video('copy')->save('copy-default.mp4');
        $second->concatTranscoded($first)->conformVideo(64, 48, 10)->withoutAudio()->video('libx264')->save('transcoded-default.mp4');

        $this->assertTrue(Storage::disk('memory')->exists('copy-default.mp4'));
        $this->assertTrue(Storage::disk('memory')->exists('transcoded-default.mp4'));
        $this->assertRedClipIsUnchanged();
    }

    public function test_copy_concat_rejects_incompatible_geometry(): void
    {
        [$first, , $different] = $this->openConcatInputs();

        $this->assertThrows(
            fn () => $first->concat($different)->export()->video('copy')->toDisk('memory')->save('mismatch.mp4'),
            fn (MediaException $exception) => $exception->category === 'concat',
        );

        $this->assertFalse(Storage::disk('memory')->exists('mismatch.mp4'));
        $this->assertRedClipIsUnchanged();
    }

    public function test_transcoded_concat_conforms_different_geometry(): void
    {
        [$first, , $different] = $this->openConcatInputs();

        $first->concatTranscoded($different)->conformVideo(128, 96, 10)->withoutAudio()
            ->video('libx264', crf: 20)
            ->toDisk('memory')->save('normalized.mp4');

        $normalized = FFMpeg::disk('memory')->open('normalized.mp4');
        $metadata = $normalized->probe();
        $this->assertSame(128, $metadata->video()->get('width'));
        $this->assertSame(96, $metadata->video()->get('height'));
        $this->assertEqualsWithDelta(1.7, $metadata->duration(), 0.001);
        $this->assertFrameColor($normalized, 0.2, 'red');
        $this->assertFrameColor($normalized, 1.0, 'blue');
        $this->assertRedClipIsUnchanged();
    }

    public function test_raw_filter_graphs_can_concat_different_inputs(): void
    {
        [$first, , $different] = $this->openConcatInputs();

        $raw = $first->export();
        $raw->addInput($different);
        $raw->filterComplex('[0:v]scale=128:96,setsar=1,fps=10,setpts=PTS-STARTPTS[first];'
            .'[1:v]scale=128:96,setsar=1,fps=10,setpts=PTS-STARTPTS[second];'
            .'[first][second]concat=n=2:v=1:a=0[joined]')
            ->map('[joined]')->video('libx264')->withoutMetadata(chapters: true)
            ->toDisk('memory')->save('raw.mp4');

        $this->assertTrue(Storage::disk('memory')->exists('raw.mp4'));
        $this->assertRedClipIsUnchanged();
    }

    public function test_transcoded_concat_requires_audio_when_conforming_it(): void
    {
        [$first, , $different] = $this->openConcatInputs();

        $this->assertThrows(
            fn () => $first->concatTranscoded($different)->conformVideo(128, 96, 10)->conformAudio()
                ->toDisk('memory')->save('missing-audio.mp4'),
            fn (MediaException $exception) => $exception->category === 'mapping',
        );

        $this->assertFalse(Storage::disk('memory')->exists('missing-audio.mp4'));
        $this->assertRedClipIsUnchanged();
    }

    /**
     * Open a red clip with an unusual local name, a blue clip on the memory disk, and a larger blue clip.
     *
     * @return array{0: Media, 1: Media, 2: Media}
     */
    protected function openConcatInputs(): array
    {
        $clip = fn (string $color, string $size, string $duration) => [
            '-f', 'lavfi', '-i', "color=$color:size=$size:rate=10:duration=$duration",
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-threads', '1',
        ];

        copy($this->fixture('red.mp4', $clip('red', '64x48', '0.6')), $this->root."/red 'é\n.mp4");
        Storage::disk('memory')->put('blue.mp4', file_get_contents($this->fixture('blue.mp4', $clip('blue', '64x48', '1.1'))));

        return [
            FFMpeg::disk()->open(new File($this->root."/red 'é\n.mp4")),
            FFMpeg::disk('memory')->open('blue.mp4'),
            FFMpeg::disk()->open(new File($this->fixture('large-blue.mp4', $clip('blue', '80x60', '1.1')))),
        ];
    }

    /**
     * Assert that concatenating left the unusually named red clip untouched and cleaned up its workspaces.
     */
    protected function assertRedClipIsUnchanged(): void
    {
        $this->assertSame(hash_file('sha256', $this->fixturePath('red.mp4')), hash_file('sha256', $this->root."/red 'é\n.mp4"));
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Assert that the frame at the given time is mostly the given primary color.
     */
    protected function assertFrameColor(Media $media, float $at, string $channel): void
    {
        $image = imagecreatefromstring($media->frame(at: $at)->get());
        $pixel = imagecolorsforindex($image, imagecolorat($image, 10, 10));

        $this->assertGreaterThan(200, $pixel[$channel]);
        $this->assertLessThan(40, $pixel[$channel === 'red' ? 'blue' : 'red']);
    }

    public function test_audio_concat_preserves_sample_order(): void
    {
        $binary = $this->useRealFFMpeg();
        $first = FFMpeg::disk()->open(new File($this->toneFixture('quiet.wav', '0.2:s=8000:d=0.35')));
        $second = FFMpeg::disk()->open(new File($this->toneFixture('loud.wav', '0.7:s=8000:d=0.65')));

        $first->concat($second)->export()->audio('copy')->toDisk('memory')->save('joined.wav');

        file_put_contents($this->root.'/joined.wav', Storage::disk('memory')->get('joined.wav'));
        $decoded = Process::run([$binary, '-v', 'error', '-i', $this->root.'/joined.wav', '-f', 's16le', 'pipe:1']);
        $this->assertProcessSucceeded($decoded);
        $this->assertSame(16000, strlen($decoded->output())); // 8000 mono 16-bit samples.
        $this->assertEqualsWithDelta(6554, unpack('v', substr($decoded->output(), 200, 2))[1], 1);
        $this->assertEqualsWithDelta(22938, unpack('v', substr($decoded->output(), 8000, 2))[1], 1);
    }

    public function test_audio_concat_rejects_channel_layout_mismatches(): void
    {
        $first = FFMpeg::disk()->open(new File($this->toneFixture('quiet.wav', '0.2:s=8000:d=0.35')));
        $stereo = FFMpeg::disk()->open(new File($this->toneFixture('stereo.wav', '0.7|0.7:s=8000:d=0.2')));

        $this->assertThrows(
            fn () => $first->concat($stereo)->export()->audio('copy')->toDisk('memory')->save('mismatch.wav'),
            fn (MediaException $exception) => $exception->category === 'concat',
        );

        $this->assertFalse(Storage::disk('memory')->exists('mismatch.wav'));
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Generate a constant PCM tone from the given aevalsrc expression and get its path.
     */
    protected function toneFixture(string $name, string $signal): string
    {
        return $this->fixture($name, ['-f', 'lavfi', '-i', 'aevalsrc='.$signal, '-c:a', 'pcm_s16le']);
    }

    public function test_normalized_concat_preserves_audio_delays_across_different_inputs(): void
    {
        $binary = $this->useRealFFMpeg();
        [$first, $second] = $this->openDelayedInputs();

        $first->concatTranscoded($second)->conformVideo(128, 96, 10)
            ->conformAudio(16000, 1, channel: 0)->video('libx264', crf: 20)->audio('pcm_s16le')
            ->toDisk('memory')->save('normalized.mov');

        $media = FFMpeg::disk('memory')->open('normalized.mov');
        $metadata = $media->probe();
        $this->assertSame(128, $metadata->video()->get('width'));
        $this->assertSame(96, $metadata->video()->get('height'));
        $this->assertSame('10/1', $metadata->video()->get('r_frame_rate'));
        $this->assertSame('16000', $metadata->audioStreams()->first()->get('sample_rate'));
        $this->assertSame(1, $metadata->audioStreams()->first()->get('channels'));
        $this->assertEqualsWithDelta(1.7, $metadata->duration(), 0.001);

        file_put_contents($this->root.'/normalized.mov', Storage::disk('memory')->get('normalized.mov'));
        $decoded = Process::run([$binary, '-v', 'error', '-i', $this->root.'/normalized.mov', '-map', '0:a', '-f', 's16le', 'pipe:1']);
        $this->assertProcessSucceeded($decoded);
        $this->assertSame(54400, strlen($decoded->output())); // 1.7 seconds of 16000 Hz mono PCM.
        foreach ([[0.05, 0], [0.2, 6554], [0.5, 0], [0.65, 0], [0.85, 22938]] as [$at, $sample]) {
            $this->assertEqualsWithDelta($sample, unpack('v', substr($decoded->output(), (int) ($at * 16000) * 2, 2))[1], 2);
        }

        $this->assertFrameColor($media, 0.2, 'red');
        $this->assertFrameColor($media, 0.9, 'blue');
        $this->assertDelayedInputsAreUnchanged();
    }

    public function test_normalized_concat_selects_the_requested_channel_of_a_repeated_input(): void
    {
        $binary = $this->useRealFFMpeg();
        [$first] = $this->openDelayedInputs();

        // Select the other stereo channel, and repeat an input to catch identity-based collapsing.
        $first->concatTranscoded($first)->conformVideo(128, 96, 10, 'stretch')
            ->conformAudio(16000, 1, channel: 1)->video('libx264')->audio('pcm_s16le')
            ->toDisk('memory')->save('right.mov');

        file_put_contents($this->root.'/right.mov', Storage::disk('memory')->get('right.mov'));
        $right = Process::run([$binary, '-v', 'error', '-i', $this->root.'/right.mov', '-map', '0:a', '-f', 's16le', 'pipe:1']);
        $this->assertProcessSucceeded($right);
        foreach ([[0.05, 0], [0.2, 13107], [0.65, 0], [0.8, 13107]] as [$at, $sample]) {
            $this->assertEqualsWithDelta($sample, unpack('v', substr($right->output(), (int) ($at * 16000) * 2, 2))[1], 2);
        }
        $this->assertDelayedInputsAreUnchanged();
    }

    public function test_normalized_audio_defaults_to_stereo_at_48_khz(): void
    {
        [$first, $second] = $this->openDelayedInputs();

        $first->concatTranscoded($second)->conformVideo(128, 96, 10, 'cover')->conformAudio()
            ->video('libx264')->audio('pcm_s16le')->toDisk('memory')->save('stereo.mov');

        $stereo = FFMpeg::disk('memory')->open('stereo.mov')->probe()->audio();
        $this->assertSame(2, $stereo->channels());
        $this->assertSame(48000, $stereo->sampleRate());
        $this->assertDelayedInputsAreUnchanged();
    }

    public function test_normalized_concat_rejects_a_channel_missing_from_an_input(): void
    {
        [$first, $second] = $this->openDelayedInputs();

        $this->assertThrows(
            fn () => $first->concatTranscoded($second)->conformVideo(128, 96, 10)->conformAudio(channel: 1)
                ->toDisk('memory')->save('invalid-channel.mov'),
            fn (MediaException $exception) => $exception->category === 'mapping',
        );

        $this->assertFalse(Storage::disk('memory')->exists('invalid-channel.mov'));
        $this->assertDelayedInputsAreUnchanged();
    }

    /**
     * Open a red clip with delayed stereo audio and a larger, faster blue clip with delayed mono audio.
     *
     * @return array{0: Media, 1: Media}
     */
    protected function openDelayedInputs(): array
    {
        $clip = fn (string $color, string $size, string $rate, string $duration, string $delay, string $signal) => [
            '-f', 'lavfi', '-i', "color=$color:size=$size:rate=$rate:duration=$duration",
            '-itsoffset', $delay, '-f', 'lavfi', '-i', 'aevalsrc='.$signal,
            '-c:v', 'libx264', '-bf', '0', '-threads', '1', '-c:a', 'pcm_s16le', '-copyts', '-fps_mode:v', 'passthrough',
        ];

        $this->delayedInputs = [
            $this->fixture('stereo-red.mov', $clip('red', '64x48', '10', '0.6', '0.1', '0.2|0.4:s=48000:d=0.3')),
            $this->fixture('mono-blue.mov', $clip('blue', '80x60', '20', '1.1', '0.15', '0.7:s=8000:d=0.95')),
        ];
        $this->delayedHashes = array_map(fn ($path) => hash_file('sha256', $path), $this->delayedInputs);

        return array_map(fn ($path) => FFMpeg::disk()->open(new File($path)), $this->delayedInputs);
    }

    /**
     * Assert that concatenating left the delayed inputs untouched and cleaned up its workspaces.
     */
    protected function assertDelayedInputsAreUnchanged(): void
    {
        $this->assertSame($this->delayedHashes, array_map(fn ($path) => hash_file('sha256', $path), $this->delayedInputs));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_copy_concat_retains_each_segments_audio_delay_relative_to_video(): void
    {
        [$first, $second] = $this->openSineInputs();
        $this->assertEqualsWithDelta(0, $first->probe()->video()->get('start_time'), 0.001);
        $this->assertEqualsWithDelta(0.1, $first->probe()->audioStreams()->first()->get('start_time'), 0.001);

        $first->concat($second)->export()->map('0')->video('copy')->audio('copy')
            ->toDisk('memory')->save('delayed.mkv');

        file_put_contents($this->root.'/delayed.mkv', Storage::disk('memory')->get('delayed.mkv'));
        $packets = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-select_streams', 'a',
            '-show_packets', '-show_entries', 'packet=pts_time', '-of', 'json', $this->root.'/delayed.mkv']);
        $this->assertProcessSucceeded($packets);
        $times = array_map('floatval', array_column(json_decode($packets->output(), true)['packets'], 'pts_time'));
        $this->assertEqualsWithDelta(0.1, $times[0], 0.001);
        $this->assertContains(0.75, $times); // Second video's 0.6-second boundary plus its 0.15-second delay.
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_copy_concat_rejects_a_nonzero_presentation_origin(): void
    {
        [$first] = $this->openSineInputs();
        $late = FFMpeg::disk()->open(new File($this->lateFixture()));

        $this->assertThrows(
            fn () => $first->concat($late)->export()->video('copy')->audio('copy')->toDisk('memory')->save('late.mov'),
            fn (MediaException $exception) => $exception->category === 'metadata',
        );

        $this->assertFalse(Storage::disk('memory')->exists('late.mov'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_transcoded_concat_rejects_a_nonzero_presentation_origin(): void
    {
        [$first] = $this->openSineInputs();
        $late = FFMpeg::disk()->open(new File($this->lateFixture()));

        $this->assertThrows(
            fn () => $first->concatTranscoded($late)
                ->conformVideo(128, 96, 10)->conformAudio()->toDisk('memory')->save('late-normalized.mov'),
            fn (MediaException $exception) => $exception->category === 'metadata',
        );

        $this->assertFalse(Storage::disk('memory')->exists('late-normalized.mov'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_transcoded_concat_checks_the_origin_of_every_video_track(): void
    {
        [$first] = $this->openSineInputs();
        $tracks = $this->fixture('late-second-track.mkv', [
            '-f', 'lavfi', '-i', 'color=red:size=64x48:rate=10:duration=1',
            '-itsoffset', '1', '-f', 'lavfi', '-i', 'color=blue:size=64x48:rate=10:duration=1', '-f', 'lavfi', '-i', 'sine=sample_rate=48000:duration=1',
            '-map', '0:v', '-map', '1:v', '-map', '2:a', '-c:v', 'ffv1', '-c:a', 'pcm_s16le',
        ]);

        $this->assertThrows(
            fn () => $first->concatTranscoded(FFMpeg::disk()->open(new File($tracks)))
                ->conformVideo(128, 96, 10)->conformAudio()->toDisk('memory')->save('late-track.mov'),
            fn (MediaException $exception) => $exception->category === 'metadata',
        );

        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Open a red and a blue clip whose audio starts after their video.
     *
     * @return array{0: Media, 1: Media}
     */
    protected function openSineInputs(): array
    {
        $clip = fn (string $color, string $duration, string $delay, string $audio) => [
            '-f', 'lavfi', '-i', "color=$color:size=64x48:rate=10:duration=$duration",
            '-itsoffset', $delay, '-f', 'lavfi', '-i', 'sine=sample_rate=8000:duration='.$audio,
            '-c:v', 'libx264', '-bf', '0', '-threads', '1', '-c:a', 'pcm_s16le', '-copyts', '-fps_mode:v', 'passthrough',
        ];

        return [
            FFMpeg::disk()->open(new File($this->fixture('sine-red.mov', $clip('red', '0.6', '0.1', '0.5')))),
            FFMpeg::disk()->open(new File($this->fixture('sine-blue.mov', $clip('blue', '1.1', '0.15', '0.95')))),
        ];
    }

    /**
     * Get the path of the red sine clip shifted to start one second late.
     */
    protected function lateFixture(): string
    {
        return $this->fixture('late.mov', ['-i', $this->fixturePath('sine-red.mov'), '-c', 'copy', '-output_ts_offset', '1']);
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
}

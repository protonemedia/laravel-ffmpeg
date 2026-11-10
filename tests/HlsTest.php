<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use League\Flysystem\PathTraversalDetected;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Events;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\HlsExportResult;
use ProtoneMedia\LaravelFFMpeg\HlsVariant;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\PendingHlsExport;
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class HlsTest extends TestCase
{
    /**
     * The result of saving the shared real variants.
     */
    protected static ?HlsExportResult $realVariantsResult = null;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake(
            [
                Events\ExportStarting::class,
                Events\ExportCompleted::class,
                Events\ExportFailed::class,
                Events\ExportCancelled::class,
            ],
        );
    }

    protected function source(bool $video = true, bool $audio = true): Media
    {
        $binary = $this->useRealFFMpeg();
        $disk = $this->fakeLocalDisk();
        $command = [$binary, '-v', 'error'];

        if ($video) {
            array_push($command, '-f', 'lavfi', '-i', 'testsrc2=size=160x96:rate=20:duration=2.3');
        }

        if ($audio) {
            array_push($command, '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=2.3',
                '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=48000:duration=2.3');
        }

        array_push($command, '-map', '0');

        if ($audio) {
            array_push($command, '-map', '1');

            if ($video) {
                array_push($command, '-map', '2');
            }
        }

        array_push(
            $command, '-c:v', 'libx264', '-preset', 'ultrafast', '-g', '20', '-c:a', 'aac', '-threads', '1',
            $disk->path('source.mkv'),
        );
        $result = Process::run($command);

        $this->assertProcessSucceeded($result);

        return FFMpeg::disk('local')->open('source.mkv');
    }

    protected function probe(string $path): array
    {
        $result = Process::run(
            [config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-show_streams', '-of', 'json', $path],
        );

        $this->assertProcessSucceeded($result);

        return json_decode($result->output(), true)['streams'];
    }

    protected function exportWithLateDefaults(Media $media): PendingHlsExport
    {
        return $media->hls(segmentDuration: 0.7)
            ->variant('low', fn (HlsVariant $variant) => $variant->resize(width: 80)
                ->video(bitrate: '200k')
                ->outputOptions(['-threads', '2']))
            ->variant('high', fn (HlsVariant $variant) => $variant->resize(height: 96))
            ->video('libx264', bitrate: '600k')
            ->audio('aac', bitrate: '48k')
            ->videoFilter('hflip')
            ->outputOptions(['-threads', '3']);
    }

    public function test_inspection_is_pure(): void
    {
        Process::fake();
        $export = $this->exportWithLateDefaults(FFMpeg::disk('absent')->open('unread.mp4'));
        $command = $export->inspect();

        $this->assertSame($command, $export->inspect());
        $this->assertSame(1, count(array_filter($command, fn ($token) => $token === '-i')));
        Process::assertNothingRan();
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_variants_inherit_late_defaults_unless_they_override_them(): void
    {
        Process::fake();
        $command = $this->exportWithLateDefaults(FFMpeg::disk('absent')->open('unread.mp4'))->inspect();
        $first = array_slice($command, 0, array_search('[output:0]', $command));
        $second = array_slice($command, array_search('[output:0]', $command) + 1);

        $this->assertSame('200k', $first[array_search('-b:v', $first) + 1]);
        $this->assertSame('600k', $second[array_search('-b:v', $second) + 1]);
        $this->assertSame('2', $first[array_search('-threads', $first) + 1]);
        $this->assertSame('3', $second[array_search('-threads', $second) + 1]);
    }

    public function test_export_filters_run_before_the_scale_of_each_variant(): void
    {
        Process::fake();
        $media = FFMpeg::disk('absent')->open('unread.mp4');
        $command = $this->exportWithLateDefaults($media)->inspect();

        $this->assertContains('hflip,scale=w=80:h=-2:reset_sar=1', $command);
        $this->assertContains('hflip,scale=w=-2:h=96:reset_sar=1', $command);
        $this->assertNotContains('hflip', $media->hls()->variant('fresh', fn () => null)->inspect());
    }

    public function test_variants_are_keyframed_on_the_segment_grid(): void
    {
        Process::fake();
        $command = $this->exportWithLateDefaults(FFMpeg::disk('absent')->open('unread.mp4'))->inspect();

        $this->assertContains('expr:gte(t,n_forced*0.7)', $command);
        $this->assertNotContains('independent_segments', $command);
    }

    public function test_variants_cannot_be_saved_on_their_own(): void
    {
        $this->assertFalse(is_callable([new HlsVariant, 'toDisk']));
        $this->assertFalse(is_callable([new HlsVariant, 'save']));
    }

    protected function realVariants(): PendingHlsExport
    {
        $this->source();
        Storage::disk('memory')->put('original/source.mkv', Storage::disk('local')->get('source.mkv'));

        return FFMpeg::disk('memory')
            ->open('original/source.mkv')
            ->hls(segmentDuration: 0.7)
            ->audio(bitrate: '48k')
            ->variant('small', fn (HlsVariant $variant) => $variant->resize(width: 80)->video(bitrate: '120k'))
            ->variant('large', fn (HlsVariant $variant) => $variant->video(bitrate: '300k'));
    }

    public function test_real_variants_publish_segments_before_playlists(): void
    {
        $export = $this->realVariants();
        $original = Storage::disk('local')->get('source.mkv');
        $progressEvents = [];
        Event::listen(Events\ExportProgress::class, function ($event) use (&$progressEvents) {
            $progressEvents[] = $event;
        });
        $writes = [];
        $storage = Storage::disk('memory');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')
            ->andReturnUsing(function ($path, $stream, $options) use ($storage, &$writes) {
                $this->assertEquals(['visibility' => 'private', 'CacheControl' => 'max-age=60'], $options);

                $writes[] = $path;

                return $storage->put($path, $stream, $options);
            });
        $disk->shouldReceive('size')
            ->andReturnUsing(fn ($path) => $storage->size($path));
        $result = $export->toDisk($disk, ['CacheControl' => 'max-age=60'])
            ->visibility('private')
            ->save('course/é #?/master.m3u8');

        $this->assertCount(1, $result->processes());
        $this->assertSame('course/é #?/master.m3u8', end($writes));

        $firstPlaylist = array_search('course/é #?/small/index.m3u8', $writes);

        foreach (array_slice($writes, 0, $firstPlaylist) as $path) {
            $this->assertStringEndsWith('.ts', $path);
        }

        $this->assertSame(count($writes) - 3, $firstPlaylist);
        $this->assertSame($original, Storage::disk('local')->get('source.mkv'));
        $this->assertSame($original, $storage->get('original/source.mkv'));
        $this->assertWorkspacesAreCleanedUp();
        Event::assertDispatchedTimes(Events\ExportCompleted::class, 1);
        $this->assertNotEmpty(array_filter($progressEvents, fn ($event) => $event->progress->isEncoding()
            && $event->progress->elapsedSeconds !== null));
        $this->assertSame('publication', end($progressEvents)->progress->phase);
        Event::assertDispatched(Events\ExportCompleted::class,
            fn ($event) => $event->operationId === $progressEvents[0]->operationId);
        $this->assertContains('course/é #?/master.m3u8', array_column($progressEvents[0]->outputs, 'path'));
    }

    /**
     * Get the result of saving the real variants to the "real-variants" disk.
     */
    protected function savedRealVariants(): HlsExportResult
    {
        // Encoding the variants is the slow part, and several tests only read the
        // published files, so we encode them once for the class and point each
        // test at the same directory. Tests may still use their own local disk.
        $this->useRealFFMpeg();

        config(['filesystems.disks.real-variants' => [
            'driver' => 'local',
            'root' => $this->fixturePath('real-variants'),
        ]]);

        if (static::$realVariantsResult === null) {
            static::$realVariantsResult = $this->realVariants()
                ->toDisk('real-variants')
                ->save('course/é #?/master.m3u8');
        } else {
            $this->fakeLocalDisk();
        }

        return static::$realVariantsResult;
    }

    /**
     * Forget the shared real variants after the last test of the class.
     */
    public static function tearDownAfterClass(): void
    {
        static::$realVariantsResult = null;

        parent::tearDownAfterClass();
    }

    public function test_real_master_playlists_list_every_variant_relatively(): void
    {
        $result = $this->savedRealVariants();

        $this->assertSame('course/é #?/master.m3u8', $result->playlist()->path);

        $master = Storage::disk('real-variants')->get($result->playlist()->path);

        $this->assertSame(2, substr_count($master, '#EXT-X-STREAM-INF:'));
        $this->assertStringContainsString('RESOLUTION=80x48', $master);
        $this->assertStringContainsString('RESOLUTION=160x96', $master);
        $this->assertStringContainsString("small/index.m3u8\n", $master);
        $this->assertStringContainsString("large/index.m3u8\n", $master);
    }

    public function test_real_variants_have_aligned_keyframed_segments_that_decode(): void
    {
        $result = $this->savedRealVariants();
        $storage = Storage::disk('real-variants');
        $local = Storage::disk('local');
        $durations = [];

        foreach (['small' => [80, 48], 'large' => [160, 96]] as $name => $dimensions) {
            $variant = $result->variant($name);
            $this->assertSame('course/é #?/'.$name.'/index.m3u8', $variant->playlist()->path);
            $playlist = $storage->get($variant->playlist()->path);
            $this->assertStringEndsWith("#EXT-X-ENDLIST\n", $playlist);
            preg_match_all('/#EXTINF:([0-9.]+),/', $playlist, $matches);
            $durations[$name] = array_map('floatval', $matches[1]);
            $this->assertCount(4, $durations[$name]);
            $files = $variant->files()
                ->where('role', 'hls-segment')
                ->values();
            $this->assertCount(4, $files);

            foreach ($files as $file) {
                $this->assertStringContainsString("\n".basename($file->path)."\n", $playlist);
                $key = 'playback/'.$name.'/'.basename($file->path);
                $local->put($key, $storage->get($file->path));
                $streams = $this->probe($local->path($key));
                $this->assertSame(['video', 'audio'], array_column($streams, 'codec_type'));
                $this->assertSame($dimensions, [$streams[0]['width'], $streams[0]['height']]);
                $this->assertSame(['h264', 'aac'], array_column($streams, 'codec_name'));
                $packet = Process::run(
                    [
                        config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-select_streams', 'v:0',
                        '-read_intervals', '%+#1', '-show_packets', '-show_entries', 'packet=flags', '-of', 'json',
                        $local->path($key),
                    ],
                );
                $this->assertProcessSucceeded($packet);
                $this->assertStringContainsString('K', json_decode($packet->output(), true)['packets'][0]['flags']);
            }

            $local->put('playback/'.$name.'/index.m3u8', $playlist);
            $decode = Process::run([config(
                'laravel-ffmpeg.ffmpeg.binaries',
            ), '-v', 'error', '-xerror', '-i', $local->path('playback/'.$name.'/index.m3u8'),
                '-map', '0:v:0', '-map', '0:a:0', '-f', 'null', '-']);
            $this->assertProcessSucceeded($decode);
        }

        $this->assertSame($durations['small'], $durations['large']);
        $this->assertEqualsWithDelta(0.7, $durations['small'][0], 0.05);
    }

    protected function typedExport(Media $media, string $type, bool $fragmented): PendingHlsExport
    {
        $export = $media->hls(segmentDuration: 0.7)
            ->playlistType(strtoupper($type))
            ->variant('main', fn (HlsVariant $variant) => $variant->video('libx264', preset: 'ultrafast')
                ->map('0:v:0')->audioGroup('sound')->subtitleGroup('text'))
            ->audioRendition('sound', 'Music', fn ($variant) => $variant->map('0:a:0'))
            ->subtitleRendition('text', 'Captions', fn ($variant) => $variant->map('0:s:0'));

        return $fragmented ? $export->fmp4() : $export;
    }

    #[DataProvider('playlistTypes')]
    public function test_playlist_types_are_passed_to_the_muxer_except_for_live(string $type, bool $fragmented): void
    {
        Process::fake();
        $command = $this->typedExport(FFMpeg::disk('absent')->open('unread.mkv'), $type, $fragmented)->inspect();

        if ($type === 'live') {
            $this->assertNotContains('-hls_playlist_type', $command);
        } else {
            $this->assertSame($type, $command[array_search('-hls_playlist_type', $command, true) + 1]);
        }
    }

    public static function playlistTypes(): array
    {
        return [['vod', false], ['event', false], ['live', false], ['event', true], ['live', true]];
    }

    #[DataProvider('playlistTypes')]
    public function test_playlist_types_preserve_complete_decodable_presentations(string $type, bool $fragmented): void
    {
        $this->source();
        $disk = Storage::disk('local');
        $disk->put('captions.srt', <<<'SRT'
            1
            00:00:00,200 --> 00:00:01,100
            Typed captions

            SRT);
        $remux = Process::run([$this->useRealFFMpeg(), '-v', 'error', '-i', $disk->path('source.mkv'),
            '-i', $disk->path('captions.srt'), '-map', '0', '-map', '1:s', '-c', 'copy', $disk->path('captioned.mkv')]);

        $this->assertProcessSucceeded($remux);

        $result = $this->typedExport(
            FFMpeg::disk('local')->open('captioned.mkv'),
            $type,
            $fragmented,
        )->save('typed/master.m3u8');

        $this->assertCount(3, $result->files()->where('role', 'hls-playlist'));

        foreach ($result->files()->where('role', 'hls-playlist') as $file) {
            $playlist = $disk->get($file->path);

            if ($type === 'live') {
                $this->assertStringNotContainsString('#EXT-X-PLAYLIST-TYPE:', $playlist);
            } else {
                $this->assertStringContainsString('#EXT-X-PLAYLIST-TYPE:'.strtoupper($type), $playlist);
            }

            $this->assertStringEndsWith("#EXT-X-ENDLIST\n", $playlist);
        }

        $this->assertStringContainsString(
            'Typed captions',
            $disk->get(
                $result->subtitleRendition('text', 'Captions')->files()->firstWhere('role', 'hls-segment')->path,
            ),
        );
        $this->assertGreaterThan(1, $result->files()->where('role', 'hls-segment')->count());

        $decoded = Process::run(
            [
                $this->useRealFFMpeg(), '-v', 'error', '-i', Storage::disk('local')->path('typed/master.m3u8'), '-f',
                'null', '-',
            ],
        );

        $this->assertProcessSucceeded($decoded);
        $this->assertWorkspacesAreCleanedUp();
    }

    #[TestWith([''])]
    #[TestWith(['other'])]
    #[TestWith([' vod '])]
    public function test_invalid_playlist_types_are_rejected(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('playlist type');

        FFMpeg::disk('absent')
            ->open('source.mp4')
            ->hls()
            ->playlistType($type);
    }

    #[TestWith(['event'])]
    #[TestWith(['live'])]
    public function test_non_vod_parallel_work_is_rejected_before_dispatch(string $type): void
    {
        $export = FFMpeg::disk('absent')
            ->open('source.mp4')
            ->hls()
            ->playlistType($type)
            ->variant('low', fn () => null)
            ->variant('high', fn () => null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires VOD');

        $export->queued(parallel: true)
            ->job('master.m3u8');
    }

    public static function streamLayoutProvider(): array
    {
        return [
            'audio only' => [false, true, 'audio'],
            'silent video' => [true, false, 'video'],
            'video and audio' => [true, true, 'both'],
        ];
    }

    #[DataProvider('streamLayoutProvider')]
    public function test_copied_and_encoded_variants_preserve_the_stream_layout(
        bool $video,
        bool $audio,
        string $name,
    ): void {
        $media = $this->source($video, $audio);

        if (! $video) {
            $disk = Storage::disk('local');
            $attached = Process::run(
                [
                    config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-i', $disk->path('source.mkv'), '-f',
                    'lavfi', '-i', 'color=red:size=32x32:duration=0.05', '-map', '0:a', '-map', '1:v', '-c:a', 'copy',
                    '-c:v', 'mjpeg', '-frames:v', '1', '-disposition:v', 'attached_pic', '-threads', '1',
                    $disk->path('artwork.m4a'),
                ],
            );
            $this->assertProcessSucceeded($attached);
            $media = FFMpeg::disk('local')->open('artwork.m4a');
            $this->assertCount(1, $media->probe()->coverArt());
        }

        $result = $media->hls(segmentDuration: 0.7)
            ->video('copy')
            ->audio('copy')
            ->variant($name, fn () => null)
            ->save('default/master.m3u8');

        $this->assertSame('default/master.m3u8', $result->playlist()->path);

        $first = $result->variant($name)
            ->files()
            ->where('role', 'hls-segment')
            ->first();
        $streams = $this->probe(Storage::disk('local')->path($first->path));

        $this->assertSame(
            $video ? ($audio ? ['video', 'audio'] : ['video']) : ['audio'],
            array_column($streams, 'codec_type'),
        );
        $this->assertStringNotContainsString(
            '#EXT-X-INDEPENDENT-SEGMENTS',
            Storage::disk('local')->get($result->playlist()->path),
        );

        $encoded = $media->hls(segmentDuration: 0.2)
            ->variant('encoded', fn () => null)
            ->save('encoded/master.m3u8');
        $playlist = Storage::disk('local')->get($encoded->variant('encoded')->playlist()->path);

        $this->assertStringContainsString('#EXT-X-TARGETDURATION:1', $playlist);

        $first = $encoded->variant('encoded')
            ->files()
            ->where('role', 'hls-segment')
            ->first();

        $this->assertSame($video ? ($audio ? ['video', 'audio'] : ['video']) : ['audio'],
            array_column($this->probe(Storage::disk('local')->path($first->path)), 'codec_type'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_every_audio_stream_can_be_mapped_into_a_variant(): void
    {
        $result = $this->source()
            ->hls(segmentDuration: 0.7)
            ->video('copy')
            ->audio('copy')
            ->map('0:V:0')
            ->map('0:a')
            ->variant('all', fn () => null)
            ->save('all/master.m3u8');
        $first = $result->variant('all')
            ->files()
            ->where('role', 'hls-segment')
            ->first();

        $this->assertSame(
            ['video', 'audio', 'audio'],
            array_column($this->probe(Storage::disk('local')->path($first->path)), 'codec_type'),
        );
    }

    public function test_a_shared_watermark_graph_feeds_independent_clipped_hls_variants(): void
    {
        $media = $this->source();
        $disk = Storage::disk('local');
        $image = imagecreatetruecolor(24, 16);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagepng($image, $disk->path('logo.png'));
        $export = $media->hls(segmentDuration: 0.4)
            ->clip(0.4, duration: 1.2)
            ->audio(bitrate: '48k')
            ->withoutMetadata(chapters: true);

        $this->assertSame(1, $export->addInput(FFMpeg::disk('local')->open('logo.png')));

        $graph = '[0:v][1:v]overlay=x=4:y=2:eof_action=repeat:repeatlast=1:shortest=0,split=2[branch][large];'
            .'[branch]scale=w=80:h=-2:reset_sar=1[small]';
        $result = $export->filterComplex($graph)
            ->variant('small', fn (HlsVariant $variant) => $variant->map('[small]')->mapAudio(0))
            ->variant('large', fn (HlsVariant $variant) => $variant->map('[large]')->mapAudio(0))
            ->save('watermark/master.m3u8');

        $this->assertCount(1, $result->processes());

        foreach (['small' => [80, 7, 5], 'large' => [160, 14, 10]] as $name => [$width, $x, $y]) {
            $path = $disk->path($result->variant($name)->playlist()->path);
            $decoded = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-i', $path,
                '-frames:v', '1', '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-']);
            $this->assertProcessSucceeded($decoded);
            $this->assertSame($width * (int) ($width * 96 / 160) * 3, strlen($decoded->output()));
            $pixel = substr($decoded->output(), ($y * $width + $x) * 3, 3);

            foreach (unpack('C*', $pixel) as $channel) {
                $this->assertGreaterThan(235, $channel);
            }

            $metadata = Process::run(
                [
                    config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-show_entries', 'format=duration', '-of',
                    'json', $path,
                ],
            );
            $this->assertProcessSucceeded($metadata);
            $this->assertEqualsWithDelta(
                1.2,
                (float) json_decode($metadata->output(), true)['format']['duration'],
                0.06,
            );
        }

        $this->assertTrue($disk->exists('source.mkv'));
        $this->assertSame([24, 16], array_slice(getimagesize($disk->path('logo.png')), 0, 2));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_an_extra_unlabeled_graph_stream_is_rejected_before_hls_publication(): void
    {
        $media = $this->source(video: true, audio: false);

        $this->assertThrows(function () use ($media) {
            $media->hls(segmentDuration: 0.7)
                ->filterComplex('[0:v]split=2[named]')
                ->variant('test', fn (HlsVariant $variant) => $variant->map('[named]'))
                ->toDisk('memory')
                ->save('unexpected/master.m3u8');
        }, function (MediaException $exception) {
            $this->assertSame(['validation', 'mapping'], [$exception->phase, $exception->category]);

            return true;
        });
        $this->assertSame([], Storage::disk('memory')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
    }

    protected function fakePipeline(?callable $mutate = null): void
    {
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($mutate) {
            $tool = basename($process->command[0]);

            if (in_array('-version', $process->command, true)) {
                return Process::result(output: $tool.' version 9.0.2');
            }

            if ($tool === 'ffprobe') {
                if (($selector = array_search('-select_streams', $process->command, true)) !== false
                    && $process->command[$selector + 1] === 'a:0') {
                    return Process::result(output: '{"format":{"duration":"2"},"streams":[]}');
                }

                return Process::result(
                    output: '{"format":{"duration":"2"},"streams":[{"index":0,"codec_type":"video"}]}',
                );
            }

            foreach ($process->command as $token) {
                if (preg_match('/\/playlist-([0-9]+)\.m3u8$/', $token, $match)) {
                    $index = $match[1];
                    file_put_contents($token, sprintf(<<<'M3U8'
                        #EXTM3U
                        #EXT-X-TARGETDURATION:2
                        #EXT-X-PLAYLIST-TYPE:VOD
                        #EXTINF:2.000000,
                        segment-%s-00000000.ts
                        #EXT-X-ENDLIST

                        M3U8, $index));
                    file_put_contents($process->path.'/segment-'.$index.'-00000000.ts', 'fake encoded TS');
                    file_put_contents($process->path.'/master-'.$index.'.m3u8', sprintf(<<<'M3U8'
                        #EXTM3U
                        #EXT-X-STREAM-INF:BANDWIDTH=100000
                        %s

                        M3U8, basename($token)));
                }
            }

            if ($mutate !== null) {
                $mutate($process);
            }

            return Process::result(output: <<<'PROGRESS'
                out_time_us=2000000
                progress=end

                PROGRESS);
        });
    }

    public static function corruptions(): array
    {
        return array_map(
            fn ($case) => [$case],
            [
                'missing', 'empty', 'unreferenced', 'escape', 'network', 'duplicate', 'truncated', 'key', 'duration',
                'whitespace', 'target',
            ],
        );
    }

    #[DataProvider('corruptions')]
    public function test_invalid_generated_inventories_prevent_all_publication(string $case): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        $this->fakePipeline(function (PendingProcess $process) use ($case) {
            $path = $process->path;
            $playlist = file_get_contents($path.'/playlist-0.m3u8');
            match ($case) {
                'missing' => unlink($path.'/segment-0-00000000.ts'),
                'empty' => file_put_contents($path.'/segment-0-00000000.ts', ''),
                'unreferenced' => file_put_contents($path.'/segment-0-00000001.ts', 'unexpected'),
                'escape' => $playlist = str_replace('segment-0-00000000.ts', '../outside.ts', $playlist),
                'network' => $playlist = str_replace(
                    'segment-0-00000000.ts',
                    'https://example.test/media.ts',
                    $playlist,
                ),
                'duplicate' => $playlist = str_replace('#EXT-X-ENDLIST', <<<'M3U8'
                    #EXTINF:2.000000,
                    segment-0-00000000.ts
                    #EXT-X-ENDLIST
                    M3U8, $playlist),
                'truncated' => $playlist = str_replace('#EXT-X-ENDLIST', '', $playlist),
                'key' => $playlist = str_replace('#EXTINF:', <<<'M3U8'
                    #EXT-X-KEY:METHOD=AES-128,URI="key.bin"
                    #EXTINF:
                    M3U8, $playlist),
                'duration' => $playlist = str_replace('2.000000', '0.000000', $playlist),
                'whitespace' => $playlist = "\n\n",
                'target' => $playlist = str_replace('#EXT-X-TARGETDURATION:2', '', $playlist),
            };
            file_put_contents($path.'/playlist-0.m3u8', $playlist);
        });

        $this->assertThrows(function () {
            FFMpeg::disk('memory')
                ->open('source.mp4')
                ->hls()
                ->variant('test', fn () => null)
                ->save('out/master.m3u8');
        }, function (MediaException $exception) {
            $this->assertSame(['validation', 'artifact'], [$exception->phase, $exception->category]);

            return true;
        });
        $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    public function test_master_bandwidth_comes_from_generated_segments(): void
    {
        $storage = Storage::disk('memory');
        $storage->put('source.mp4', 'source');
        $this->fakePipeline(function (PendingProcess $process) {
            file_put_contents($process->path.'/segment-0-00000000.ts', str_repeat('a', 1000));
            file_put_contents($process->path.'/segment-0-00000001.ts', str_repeat('b', 100));
            $playlist = file_get_contents($process->path.'/playlist-0.m3u8');
            file_put_contents($process->path.'/playlist-0.m3u8', str_replace('#EXT-X-ENDLIST', <<<'M3U8'
                #EXTINF:0.500000,
                segment-0-00000001.ts
                #EXT-X-ENDLIST
                M3U8, $playlist));
        });
        $result = FFMpeg::disk('memory')
            ->open('source.mp4')
            ->hls()
            ->variant('test', fn () => null)
            ->save('out/master.m3u8');

        $this->assertStringContainsString(
            '#EXT-X-STREAM-INF:BANDWIDTH=4000,AVERAGE-BANDWIDTH=3520',
            $storage->get($result->playlist()->path),
        );
        $this->assertSame('out/test/index.m3u8', $result->variant('test')->playlist()->path);
    }

    public function test_owned_segments_cannot_replace_the_source(): void
    {
        $storage = Storage::disk('memory');
        $storage->put('protected/test/segment-00000000.ts', 'source bytes');
        $this->fakePipeline();

        $this->assertThrows(function () {
            FFMpeg::disk('memory')
                ->open('protected/test/segment-00000000.ts')
                ->hls()
                ->variant('test', fn () => null)
                ->save('protected/master.m3u8');
        }, InvalidArgumentException::class);
        $this->assertSame('source bytes', $storage->get('protected/test/segment-00000000.ts'));
        $this->assertFalse($storage->exists('protected/master.m3u8'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_partial_publication_reports_concrete_siblings_and_keeps_the_old_master(): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        $this->fakePipeline();
        $storage = Storage::disk('memory');
        $storage->put('out/master.m3u8', 'old master');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')
            ->andReturnUsing(fn ($path, $stream, $options) => $path === 'out/high/index.m3u8'
                ? false
                : $storage->put($path, $stream, $options));
        $disk->shouldReceive('size')
            ->andReturnUsing(fn ($path) => $storage->size($path));

        $this->assertThrows(function () use ($disk) {
            FFMpeg::disk('memory')
                ->open('source.mp4')
                ->hls()
                ->variant('low', fn () => null)
                ->variant('high', fn () => null)
                ->toDisk($disk)
                ->save('out/master.m3u8');
        }, function (MediaException $exception) {
            $this->assertSame(['publication', 'transfer'], [$exception->phase, $exception->category]);
            $this->assertSame(
                ['out/low/segment-00000000.ts', 'out/high/segment-00000000.ts', 'out/low/index.m3u8'],
                array_column($exception->published, 'path'),
            );
            $this->assertSame(['disk' => null, 'path' => 'out/high/index.m3u8'], $exception->destination);

            return true;
        });
        $this->assertSame('old master', $storage->get('out/master.m3u8'));
        $this->assertSame('source', $storage->get('source.mp4'));
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_cancellation_freezes_variants_and_cleans_up_without_publishing(): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        $this->fakePipeline();
        $held = null;
        $export = FFMpeg::disk('memory')
            ->open('source.mp4')
            ->hls()
            ->variant('test', function (HlsVariant $variant) use (&$held) {
                $held = $variant;
            })
            ->onProgress(function (Progress $progress) use (&$held) {
                if ($progress->phase === Progress::STAGING) {
                    $this->assertThrows(fn () => $held->resize(width: 42), \LogicException::class);

                    throw new ExportCancelled;
                }
            });

        $this->assertThrows(fn () => $export->save('out/master.m3u8'), ExportCancelled::class);
        $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
        Event::assertDispatchedTimes(Events\ExportCancelled::class, 1);
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    public function test_custom_paths_keep_variant_membership_and_encode_relative_urls_from_each_playlist(): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        $this->fakePipeline();
        $result = FFMpeg::disk('memory')
            ->open('source.mp4')
            ->hls()
            ->variant('low', fn () => null, playlist: 'lists/low/index.m3u8', segments: 'shared/é #?/low-%03d.ts')
            ->variant('high', fn () => null, playlist: 'lists/high/index.m3u8', segments: 'shared/é #?/high-%d.ts')
            ->variant('root', fn () => null, playlist: 'root.m3u8')
            ->variant('literal', fn () => null, playlist: '100% ready/index.m3u8')
            ->save('catalog/master/list.m3u8');
        $disk = Storage::disk('memory');
        $master = $disk->get($result->playlist()->path);

        $this->assertStringContainsString("../../lists/low/index.m3u8\n", $master);
        $this->assertStringContainsString("../../lists/high/index.m3u8\n", $master);
        $this->assertStringContainsString("../../root.m3u8\n", $master);
        $this->assertStringContainsString("../../100%25%20ready/index.m3u8\n", $master);
        $this->assertStringContainsString(
            "../../shared/%C3%A9%20%23%3F/low-000.ts\n",
            $disk->get('lists/low/index.m3u8'),
        );
        $this->assertStringContainsString(
            "../../shared/%C3%A9%20%23%3F/high-0.ts\n",
            $disk->get('lists/high/index.m3u8'),
        );
        $this->assertStringContainsString("\nsegment-00000000.ts\n", $disk->get('root.m3u8'));
        $this->assertStringContainsString("\nsegment-00000000.ts\n", $disk->get('100% ready/index.m3u8'));
        $this->assertSame(
            ['shared/é #?/low-000.ts', 'lists/low/index.m3u8'],
            $result->variant('low')->files()->pluck('path')->all(),
        );
        $this->assertSame(
            ['shared/é #?/high-0.ts', 'lists/high/index.m3u8'],
            $result->variant('high')->files()->pluck('path')->all(),
        );
        $this->assertSame(
            ['segment-00000000.ts', 'root.m3u8'],
            $result->variant('root')->files()->pluck('path')->all(),
        );
        $this->assertSame(
            ['100% ready/segment-00000000.ts', '100% ready/index.m3u8'],
            $result->variant('literal')->files()->pluck('path')->all(),
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_custom_nested_playlists_decode_with_segments_in_separate_directories(): void
    {
        $media = $this->source();
        $seen = [];
        $export = $media->hls(segmentDuration: 0.7)
            ->variant(
                'low',
                fn ($variant) => $variant->resize(width: 80),
                playlist: 'lists/low/index.m3u8',
                segments: 'assets/low/part-%03d.ts',
            )
            ->variant('high', fn () => null, playlist: 'lists/high/index.m3u8', segments: 'assets/high/part-%d.ts')
            ->playlistUsing(function (string $contents, string $path, ?string $variant) use (&$seen) {
                $seen[$path] = $variant;

                $this->assertSame(['source.mkv'], Storage::disk('local')->allFiles());

                $tag = $variant === null
                    ? '#EXT-X-SESSION-DATA:DATA-ID="com.example.title",VALUE="Café"'
                    : '#EXT-X-START:TIME-OFFSET=0.2,PRECISE=YES';

                if ($variant === 'high') {
                    $tag .= "\n#EXT-X-PROGRAM-DATE-TIME:2026-01-01T00:00:00Z\n"
                        .'#EXT-X-DATERANGE:ID="intro",START-DATE="2026-01-01T00:00:00Z",DURATION=0.2';
                }

                return str_replace(
                    "\n",
                    "\r\n",
                    str_replace("#EXTM3U\n", "#EXTM3U\n# Prepared for ".$path."\n".$tag."\n", $contents),
                );
            });
        $export->inspect();

        $this->assertSame([], $seen);

        $result = $export->save('catalog/master.m3u8');

        $this->assertEquals(
            ['lists/low/index.m3u8' => 'low', 'lists/high/index.m3u8' => 'high', 'catalog/master.m3u8' => null],
            $seen,
        );

        $master = Storage::disk('local')->get($result->playlist()->path);

        $this->assertStringContainsString('#EXT-X-SESSION-DATA:DATA-ID="com.example.title",VALUE="Café"', $master);
        $this->assertStringContainsString("../lists/low/index.m3u8\n", $master);

        foreach (['low', 'high'] as $name) {
            $playlist = $result->variant($name)
                ->playlist();
            $contents = Storage::disk('local')->get($playlist->path);
            $this->assertStringContainsString('# Prepared for '.$playlist->path, $contents);
            $this->assertStringContainsString('#EXT-X-START:TIME-OFFSET=0.2,PRECISE=YES', $contents);

            if ($name === 'high') {
                $this->assertStringContainsString('#EXT-X-PROGRAM-DATE-TIME:2026-01-01T00:00:00Z', $contents);
                $this->assertStringContainsString('#EXT-X-DATERANGE:ID="intro"', $contents);
            }

            $decoded = Process::run(
                [
                    config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-xerror', '-i',
                    Storage::disk('local')->path($playlist->path), '-map', '0:v:0', '-map', '0:a:0', '-f', 'null', '-',
                ],
            );
            $this->assertProcessSucceeded($decoded);
            $this->assertCount(5, $result->variant($name)->files());
        }

        $decoded = Process::run(
            [
                config('laravel-ffmpeg.ffmpeg.binaries'),
                '-v',
                'error',
                '-xerror',
                '-i',
                Storage::disk('local')->path($result->playlist()->path),
                '-map',
                '0:v:0',
                '-map',
                '0:a:0',
                '-f',
                'null',
                '-',
            ],
        );

        $this->assertProcessSucceeded($decoded);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_dynamically_rewritten_encrypted_playlists_decode_every_variant_through_key_rotation(): void
    {
        $media = $this->source();
        $disk = Storage::disk('local');
        $secrets = Storage::fake('secrets');
        $media->hls(segmentDuration: 0.7)
            ->variant(
                'low',
                fn ($variant) => $variant->resize(width: 80),
                playlist: 'lists/low/index.m3u8',
                segments: 'assets/low/part-%03d.ts',
            )
            ->variant('high', fn () => null, playlist: 'lists/high/index.m3u8', segments: 'assets/high/part-%d.ts')
            ->encrypt('secrets', fn ($path) => '/private/'.basename($path), rotateEvery: 2)
            ->save('master.m3u8');
        $keys = [];
        $rewritten = FFMpeg::disk('local')
            ->playlist('master.m3u8')
            ->playlistUrlsUsing(fn ($target) => $disk->path('responses/'.$target))
            ->mediaUrlsUsing(fn ($target) => $disk->path($target))
            ->keyUrlsUsing(function ($target, $source, $uri) use ($secrets, &$keys) {
                $this->assertNull($target);
                $this->assertStringStartsWith('lists/', $source);

                $keys[] = basename($uri);

                return $secrets->path('hls-keys/'.basename($uri));
            })
            ->all();

        $this->assertCount(3, $rewritten);
        $this->assertCount(2, array_unique($keys));

        foreach ($rewritten as $path => $contents) {
            $disk->put('responses/'.$path, $contents);
        }

        foreach ($rewritten->keys() as $path) {
            $decoded = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-xerror',
                '-protocol_whitelist', 'file,crypto', '-allowed_extensions', 'm3u8,ts,key',
                '-i', $disk->path('responses/'.$path), '-map', '0:v:0', '-map', '0:a:0', '-f', 'null', '-']);
            $this->assertProcessSucceeded($decoded);
        }

        $this->assertWorkspacesAreCleanedUp();
    }

    public static function encryptionModes(): array
    {
        return [["\x00\x01\x02\x03supplied-key", null], [null, null], [null, 2]];
    }

    #[DataProvider('encryptionModes')]
    public function test_encryption_neither_changes_the_command_nor_writes_keys_before_saving(
        ?string $key,
        ?int $rotateEvery,
    ): void {
        Process::fake();
        $secrets = Storage::fake('secrets');
        $export = FFMpeg::disk('absent')
            ->open('unread.mkv')
            ->hls(segmentDuration: 0.7)
            ->variant(
                'low',
                fn ($variant) => $variant->resize(width: 80),
                playlist: 'lists/low/index.m3u8',
                segments: 'assets/low/part-%03d.ts',
            )
            ->variant('high', fn () => null, playlist: 'lists/high/index.m3u8', segments: 'assets/high/part-%d.ts');
        $command = $export->inspect();
        $export->encrypt(
            'secrets',
            fn ($path) => $secrets->path($path),
            key: $key,
            rotateEvery: $rotateEvery,
            directory: 'lesson/keys',
        )
            ->playlistUsing(fn ($text) => str_replace("#EXTM3U\n", "#EXTM3U\n# Encrypted lesson\n", $text));

        $this->assertSame($command, $export->inspect());
        $this->assertSame([], $secrets->allFiles());
        Process::assertNothingRan();
    }

    #[DataProvider('encryptionModes')]
    public function test_encrypted_variants_decrypt_to_the_original_bytes_and_play_through_rotation(
        ?string $key,
        ?int $rotateEvery,
    ): void {
        $media = $this->source();
        $disk = Storage::disk('local');
        $secrets = Storage::fake('secrets');
        $export = $media->hls(segmentDuration: 0.7)
            ->variant(
                'low',
                fn ($variant) => $variant->resize(width: 80),
                playlist: 'lists/low/index.m3u8',
                segments: 'assets/low/part-%03d.ts',
            )
            ->variant('high', fn () => null, playlist: 'lists/high/index.m3u8', segments: 'assets/high/part-%d.ts');
        $plain = $export->save('master.m3u8');
        $original = $plain->files()
            ->where('role', 'hls-segment')
            ->mapWithKeys(fn ($file) => [$file->path => $disk->get($file->path)])
            ->all();
        $export->encrypt(
            'secrets',
            fn ($path) => $secrets->path($path),
            key: $key,
            rotateEvery: $rotateEvery,
            directory: 'lesson/keys',
        )
            ->playlistUsing(fn ($text) => str_replace("#EXTM3U\n", "#EXTM3U\n# Encrypted lesson\n", $text));
        $result = $export->save('master.m3u8');

        $this->assertSame($plain->file()->path, $result->file()->path);
        $this->assertSame('hls-segment', $result->file()->role);

        $keys = $result->files()
            ->where('role', 'hls-key')
            ->values();

        $this->assertCount($rotateEvery === null ? 1 : 2, $keys);

        foreach ($keys as $file) {
            $this->assertSame(
                ['secrets', 16, 'private'],
                [$file->disk, $file->size, $secrets->getVisibility($file->path)],
            );
            $this->assertFalse($disk->exists($file->path));

            if ($key !== null) {
                $this->assertSame($key, $secrets->get($file->path));
            }
        }

        $ivs = [];

        foreach (['low', 'high'] as $name) {
            $variant = $result->variant($name);
            $this->assertCount(5, $variant->files());
            $text = $disk->get($variant->playlist()->path);
            preg_match_all(
                '/#EXT-X-KEY:METHOD=AES-128,URI="([^"]+)",IV=0x([0-9a-f]{32})\n([^#\n][^\n]*)/',
                $text,
                $tags,
                PREG_SET_ORDER,
            );
            $this->assertCount(4, $tags);

            foreach ($tags as $sequence => $tag) {
                $index = $rotateEvery === null ? 0 : intdiv($sequence, 2);
                $this->assertSame($secrets->path($keys[$index]->path), $tag[1]);
                $ivs[] = $tag[2];
                $path = $variant->files()
                    ->where('role', 'hls-segment')
                    ->values()[$sequence]->path;
                $ciphertext = $disk->get($path);
                $this->assertSame((intdiv(strlen($original[$path]), 16) + 1) * 16, strlen($ciphertext));
                $this->assertSame(
                    $original[$path],
                    openssl_decrypt(
                        $ciphertext, 'aes-128-cbc', $secrets->get($keys[$index]->path), OPENSSL_RAW_DATA,
                        hex2bin($tag[2]),
                    ),
                );
            }

            $decoded = Process::run([
                config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-xerror',
                '-protocol_whitelist', 'file,crypto', '-allowed_extensions', 'm3u8,ts,key',
                '-i', $disk->path($variant->playlist()->path), '-map', '0:v:0', '-map', '0:a:0', '-f', 'null', '-',
            ]);
            $this->assertProcessSucceeded($decoded);
        }

        $this->assertCount(8, array_unique($ivs));

        $decoded = Process::run(
            [
                config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-xerror', '-protocol_whitelist',
                'file,crypto', '-allowed_extensions', 'm3u8,ts,key', '-i', $disk->path($result->playlist()->path), '-f',
                'null', '-',
            ],
        );

        $this->assertProcessSucceeded($decoded);
        $this->assertStringNotContainsString('EXT-X-KEY', $disk->get($result->playlist()->path));

        $summaries = json_encode($result->processes()->all());

        foreach ($keys as $file) {
            $this->assertStringNotContainsString($file->path, $summaries);
            $this->assertStringNotContainsString(bin2hex($secrets->get($file->path)), $summaries);
        }

        $second = $export->save('again/master.m3u8')
            ->files()
            ->where('role', 'hls-key')
            ->values();

        $this->assertNotSame($keys->pluck('path')->all(), $second->pluck('path')->all());

        if ($key === null) {
            $this->assertNotSame($secrets->get($keys[0]->path), $secrets->get($second[0]->path));
        }

        $this->assertTrue($disk->exists('source.mkv'));
        $this->assertWorkspacesAreCleanedUp();
    }

    #[TestWith([16])]
    #[TestWith([1048576])]
    #[TestWith([1048593])]
    public function test_encryption_handles_padding_and_cbc_chaining_across_large_segments(int $length): void
    {
        $disk = Storage::disk('memory');
        $disk->put('source.mp4', 'source');
        Storage::fake('secrets');
        $plain = substr(str_repeat("\x00\x01\x7f\xffasymmetric", 90000), 0, $length);

        $this->assertSame($length, strlen($plain));

        $this->fakePipeline(fn ($process) => file_put_contents($process->path.'/segment-0-00000000.ts', $plain));
        FFMpeg::disk('memory')
            ->open('source.mp4')
            ->hls()
            ->variant('test', fn () => null)
            ->encrypt('secrets', fn ($path) => 'https://keys.example.test/'.$path, key: '0123456789abcdef')
            ->save('out/master.m3u8');
        preg_match('/IV=0x([0-9a-f]{32})/', $disk->get('out/test/index.m3u8'), $match);
        $ciphertext = $disk->get('out/test/segment-00000000.ts');

        $this->assertSame((intdiv($length, 16) + 1) * 16, strlen($ciphertext));
        $this->assertSame(
            $plain,
            openssl_decrypt($ciphertext, 'aes-128-cbc', '0123456789abcdef', OPENSSL_RAW_DATA, hex2bin($match[1])),
        );
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function encryptionFailureProvider(): array
    {
        return [
            'missing key url' => [null],
            'empty key url' => [''],
            'file key url' => ['file:///secret'],
            'injected tag' => ["https://example.test/key\n#EXT-X-KEY:METHOD=NONE"],
            'quoted key url' => ['https://example.test/"key'],
            'fragment' => ['https://example.test/key#fragment'],
            'protocol relative key url' => ['//example.test/key'],
            'invalid utf-8' => ["/keys/\xff.key"],
            'unicode control character' => ["/keys/\u{0085}.key"],
            'key url failure' => [new \RuntimeException('key URL failure')],
            'cancellation' => [new ExportCancelled],
            'tampered playlist' => ['tamper'],
            'reused key url' => ['reuse'],
        ];
    }

    #[DataProvider('encryptionFailureProvider')]
    public function test_invalid_encryption_publishes_nothing(mixed $case): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        Storage::fake('secrets');
        $this->fakePipeline(function ($process) use ($case) {
            if ($case === 'reuse') {
                file_put_contents($process->path.'/segment-0-00000001.ts', 'next segment');
                $path = $process->path.'/playlist-0.m3u8';
                file_put_contents($path, str_replace('#EXT-X-ENDLIST', <<<'M3U8'
                    #EXTINF:1.000000,
                    segment-0-00000001.ts
                    #EXT-X-ENDLIST
                    M3U8, file_get_contents($path)));
            }
        });
        $export = FFMpeg::disk('memory')
            ->open('source.mp4')
            ->hls()
            ->variant('test', fn () => null)
            ->encrypt('secrets', function () use ($case) {
                if ($case instanceof \Throwable) {
                    throw $case;
                }

                return in_array($case, ['tamper', 'reuse'], true) ? '/keys/test' : $case;
            }, rotateEvery: $case === 'reuse' ? 1 : null);

        if ($case === 'tamper') {
            $export->playlistUsing(fn ($text) => preg_replace('/#EXT-X-KEY:[^\n]+\n/', '', $text));
        }

        $this->assertEncryptedSaveFails($export, $case);

        if ($case !== 'tamper') {
            $export->playlistUsing(fn ($text) => $text);
        }

        $this->assertEncryptedSaveFails($export, $case);
    }

    /**
     * Assert that saving the given encrypted export fails without publishing anything.
     */
    protected function assertEncryptedSaveFails(PendingHlsExport $export, mixed $case): void
    {
        $this->assertThrows(function () use ($export) {
            $export->save('out/master.m3u8');
        }, function (\RuntimeException $exception) use ($case) {
            if ($case instanceof \Throwable) {
                $this->assertSame($case, $exception);
            } else {
                $this->assertInstanceOf(MediaException::class, $exception);
                $this->assertSame(['validation', 'artifact'], [$exception->phase, $exception->category]);
            }

            return true;
        });
        $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
        $this->assertSame([], Storage::disk('secrets')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_failed_key_publication_stops_before_any_media(): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        $storedKeys = Storage::fake('secrets');
        $this->fakePipeline();
        $secrets = Mockery::mock(Filesystem::class);
        $secrets->shouldReceive('put')
            ->andReturnFalse();
        $secrets->shouldReceive('size')
            ->andReturnUsing(fn ($path) => $storedKeys->size($path));

        $this->assertThrows(function () use ($secrets) {
            $this->saveEncryptedTo($secrets);
        }, function (MediaException $exception) {
            $this->assertSame(['publication', 'transfer'], [$exception->phase, $exception->category]);
            $this->assertCount(0, $exception->published);
            $this->assertStringEndsWith('.key', $exception->destination['path']);

            return true;
        });
        $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_failed_media_publication_reports_the_published_keys(): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        $storedKeys = Storage::fake('secrets');
        $this->fakePipeline();
        $secrets = Mockery::mock(Filesystem::class);
        $secrets->shouldReceive('put')
            ->andReturnUsing(fn ($path, $stream, $options) => $storedKeys->put($path, $stream, $options));
        $secrets->shouldReceive('size')
            ->andReturnUsing(fn ($path) => $storedKeys->size($path));

        $this->assertThrows(function () use ($secrets) {
            $this->saveEncryptedTo($secrets);
        }, function (MediaException $exception) use ($storedKeys) {
            $this->assertSame(['publication', 'transfer'], [$exception->phase, $exception->category]);
            $this->assertCount(1, $exception->published);
            $this->assertSame(16, $storedKeys->size($exception->published[0]['path']));
            $this->assertSame('out/test/segment-00000000.ts', $exception->destination['path']);

            return true;
        });
        $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
    }

    protected function saveEncryptedTo(Filesystem $secrets): void
    {
        $failedMedia = Mockery::mock(Filesystem::class);
        $failedMedia->shouldReceive('put')
            ->andReturnFalse();

        FFMpeg::disk('memory')
            ->open('source.mp4')
            ->hls()
            ->variant('test', fn () => null)
            ->encrypt($secrets, fn ($path) => '/keys/'.$path)
            ->toDisk($failedMedia)
            ->save('out/master.m3u8');
    }

    public static function invalidPlaylistHooks(): array
    {
        return [
            ['type', null], ['empty', null], ['reference', null], ['stream', null],
            ['key', null], ['resource', null], ['duplicate', null], ['large', null],
            ['control', null], ['header', null], ['endlist', 'test'], ['target', 'test'],
        ];
    }

    #[DataProvider('invalidPlaylistHooks')]
    public function test_invalid_playlist_hooks_prevent_all_publication(string $case, ?string $target): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        $this->fakePipeline();
        $export = FFMpeg::disk('memory')
            ->open('source.mp4')
            ->hls()
            ->variant('test', fn () => null)
            ->playlistUsing(function (string $contents, string $path, ?string $variant) use ($case, $target) {
                if ($variant !== $target) {
                    return $contents;
                }

                return match ($case) {
                    'type' => null,
                    'empty' => '',
                    'reference' => str_replace('test/index.m3u8', 'https://example.test/external.m3u8', $contents),
                    'stream' => str_replace('#EXT-X-STREAM-INF:', '#EXT-X-STREAM-INF:FRAME-RATE=25,', $contents),
                    'key' => str_replace("#EXTM3U\n", "#EXTM3U\n#EXT-X-KEY:METHOD=NONE\n", $contents),
                    'resource' => str_replace("#EXTM3U\n", <<<'M3U8'
                        #EXTM3U
                        #EXT-X-SESSION-DATA:DATA-ID="external",URI = "https://example.test/info"

                        M3U8, $contents),
                    'duplicate' => $contents."#EXTM3U\n",
                    'large' => $contents.str_repeat('#', 16777216),
                    'control' => $contents."# comment\r#EXT-X-KEY:METHOD=NONE\n",
                    'header' => "\n".$contents,
                    'endlist' => str_replace('#EXT-X-ENDLIST', '', $contents),
                    'target' => str_replace('#EXT-X-TARGETDURATION:2', '#EXT-X-TARGETDURATION:99', $contents),
                };
            });

        $this->assertThrows(fn () => $export->save('out/master.m3u8'), function (MediaException $exception) {
            $this->assertSame(['validation', 'artifact'], [$exception->phase, $exception->category]);

            return true;
        });
        $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    public static function hookFailureProvider(): array
    {
        return [
            'application failure' => [
                new \RuntimeException('application failure'),
                Events\ExportFailed::class,
                Events\ExportCancelled::class,
            ],
            'cancellation' => [new ExportCancelled, Events\ExportCancelled::class, Events\ExportFailed::class],
        ];
    }

    #[DataProvider('hookFailureProvider')]
    public function test_playlist_hooks_freeze_configuration_and_propagate_failures_without_publication(
        \RuntimeException $failure,
        string $dispatched,
        string $notDispatched,
    ): void {
        Storage::disk('memory')->put('source.mp4', 'source');
        $this->fakePipeline();
        $export = FFMpeg::disk('memory')
            ->open('source.mp4')
            ->hls()
            ->variant('test', fn () => null);
        $export->playlistUsing(function () use ($export, $failure) {
            $this->assertThrows(
                fn () => $export->playlistUsing(fn ($contents) => $contents),
                InvalidArgumentException::class,
            );

            throw $failure;
        });

        $this->assertThrows(function () use ($export) {
            $export->save('out/master.m3u8');
        }, function (\RuntimeException $exception) use ($failure) {
            $this->assertSame($failure, $exception);

            return true;
        });
        $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
        Event::assertDispatchedTimes($dispatched, 1);
        Event::assertNotDispatched($notDispatched);
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    public function test_colliding_custom_segments_fail_before_any_write(): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        $this->fakePipeline();

        $this->assertThrows(function () {
            FFMpeg::disk('memory')
                ->open('source.mp4')
                ->hls()
                ->variant('low', fn () => null, segments: 'shared/part-%d.ts')
                ->variant('high', fn () => null, segments: 'shared/part-%01d.ts')
                ->save('master.m3u8');
        }, InvalidArgumentException::class);
        $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
    }

    public static function invalidSettingsProvider(): array
    {
        return [
            'zero segment duration' => [fn (Media $media) => $media->hls(segmentDuration: 0)],
            'infinite segment duration' => [fn (Media $media) => $media->hls(segmentDuration: INF)],
            'no variants' => [fn (Media $media) => $media->hls()->inspect()],
            'variant name traversal' => [fn (Media $media) => $media->hls()->variant('../escape', fn () => null)],
            'duplicate variant' => [
                fn (Media $media) => $media->hls()->variant('same', fn () => null)->variant('same', fn () => null),
            ],
            'non-playlist destination' => [
                fn (Media $media) => $media->hls()->variant('valid', fn () => null)->save('out.mp4'),
            ],
            'muxer override' => [
                fn (Media $media) => $media->hls()->variant(
                    'valid',
                    fn ($variant) => $variant->outputOptions(['-f', 'mp4']),
                )->inspect(),
            ],
            'two pass' => [fn (Media $media) => $media->hls()->variant('valid', fn () => null)->twoPass()->inspect()],
            'remote playlist' => [
                fn (Media $media) => $media->hls()->variant(
                    'valid',
                    fn () => null,
                    playlist: 'https://example.test/list.m3u8',
                ),
            ],
            'segments without number' => [
                fn (Media $media) => $media->hls()->variant('valid', fn () => null, segments: 'segment.ts'),
            ],
            'segments with two numbers' => [
                fn (Media $media) => $media->hls()->variant('valid', fn () => null, segments: 'seg-%d-%d.ts'),
            ],
            'numbered segment directory' => [
                fn (Media $media) => $media->hls()->variant('valid', fn () => null, segments: '%d/segment.ts'),
            ],
            'wide segment number' => [
                fn (Media $media) => $media->hls()->variant('valid', fn () => null, segments: 'seg-%10d.ts'),
            ],
            'short key' => [fn (Media $media) => $media->hls()->encrypt('secrets', fn () => '/keys/key', key: 'short')],
            'long key' => [
                fn (Media $media) => $media->hls()->encrypt('secrets', fn () => '/keys/key', key: str_repeat('x', 17)),
            ],
            'zero rotation' => [
                fn (Media $media) => $media->hls()->encrypt('secrets', fn () => '/keys/key', rotateEvery: 0),
            ],
            'rotation with a fixed key' => [
                fn (Media $media) => $media->hls()->encrypt(
                    'secrets',
                    fn () => '/keys/key',
                    key: str_repeat('x', 16),
                    rotateEvery: 2,
                ),
            ],
        ];
    }

    #[DataProvider('invalidSettingsProvider')]
    public function test_invalid_settings_fail_before_io(Closure $invalid): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('unread.mp4');

        $this->assertThrows(fn () => $invalid($media), InvalidArgumentException::class);
        $this->assertWorkspacesAreCleanedUp();
        Process::assertNothingRan();
    }

    public static function traversingSettingsProvider(): array
    {
        return [
            'playlist' => [
                fn (Media $media) => $media->hls()->variant('valid', fn () => null, playlist: '../outside.m3u8'),
            ],
            'key directory' => [
                fn (Media $media) => $media->hls()->encrypt('secrets', fn () => '/keys/key', directory: '../escape'),
            ],
        ];
    }

    #[DataProvider('traversingSettingsProvider')]
    public function test_traversing_paths_fail_before_io(Closure $invalid): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('unread.mp4');

        $this->assertThrows(fn () => $invalid($media), PathTraversalDetected::class);
        $this->assertWorkspacesAreCleanedUp();
        Process::assertNothingRan();
    }
}

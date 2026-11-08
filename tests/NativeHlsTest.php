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
use ProtoneMedia\LaravelFFMpeg\HlsVariant;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class NativeHlsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/native-hls-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        config(['laravel-ffmpeg.temporary_files_root' => $this->root]);
        Event::fake([Events\ExportStarting::class, Events\ExportCompleted::class, Events\ExportFailed::class, Events\ExportCancelled::class]);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function native(): string
    {
        if (! ($probe = getenv('FFMPEG_9_FFPROBE'))) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for native HLS acceptance.');
        }
        config(['laravel-ffmpeg.ffprobe.binaries' => $probe, 'laravel-ffmpeg.ffmpeg.binaries' => dirname($probe).'/ffmpeg']);

        return dirname($probe).'/ffmpeg';
    }

    private function source(bool $video = true, bool $audio = true): Media
    {
        $binary = $this->native();
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
        array_push($command, '-c:v', 'libx264', '-preset', 'ultrafast', '-g', '20', '-c:a', 'aac', '-threads', '1', $disk->path('source.mkv'));
        $result = Process::run($command);
        $this->assertTrue($result->successful(), $result->errorOutput());

        return FFMpeg::disk('local')->open('source.mkv');
    }

    private function probe(string $path): array
    {
        $result = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-show_streams', '-of', 'json', $path]);
        $this->assertTrue($result->successful(), $result->errorOutput());

        return json_decode($result->output(), true)['streams'];
    }

    #[Test]
    public function inspection_inherits_late_defaults_appends_filters_and_keeps_variants_independent(): void
    {
        Process::fake();
        $media = FFMpeg::disk('absent')->open('unread.mp4');
        $export = $media->hls(segmentDuration: 0.7)
            ->variant('low', fn (HlsVariant $variant) => $variant->resize(width: 80)->video(bitrate: '200k')->outputOptions(['-threads', '2']))
            ->variant('high', fn (HlsVariant $variant) => $variant->resize(height: 96))
            ->video('libx264', bitrate: '600k')->audio('aac', bitrate: '48k')->videoFilter('hflip')->outputOptions(['-threads', '3']);
        $command = $export->inspect();
        $this->assertSame($command, $export->inspect());
        $this->assertSame(1, count(array_filter($command, fn ($token) => $token === '-i')));
        $this->assertContains('hflip,scale=w=80:h=-2:reset_sar=1', $command);
        $this->assertContains('hflip,scale=w=-2:h=96:reset_sar=1', $command);
        $first = array_slice($command, 0, array_search('[output:0]', $command));
        $second = array_slice($command, array_search('[output:0]', $command) + 1);
        $this->assertSame('200k', $first[array_search('-b:v', $first) + 1]);
        $this->assertSame('600k', $second[array_search('-b:v', $second) + 1]);
        $this->assertSame('2', $first[array_search('-threads', $first) + 1]);
        $this->assertSame('3', $second[array_search('-threads', $second) + 1]);
        $this->assertContains('expr:gte(t,n_forced*0.7)', $command);
        $this->assertNotContains('independent_segments', $command);
        $this->assertNotContains('hflip', $media->hls()->variant('fresh', fn () => null)->inspect());
        $this->assertFalse(is_callable([new HlsVariant, 'toDisk']));
        $this->assertFalse(is_callable([new HlsVariant, 'save']));
        Process::assertNothingRan();
        $this->assertSame([], glob($this->root.'/*'));
    }

    #[Test]
    public function real_variants_have_usable_segments_aligned_keyframes_and_relative_nested_playlists(): void
    {
        $media = $this->source();
        $original = Storage::disk('local')->get('source.mkv');
        Storage::disk('memory')->put('original/source.mkv', $original);
        $media = FFMpeg::disk('memory')->open('original/source.mkv');
        $writes = [];
        $storage = Storage::disk('memory');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturnUsing(function ($path, $stream, $options) use ($storage, &$writes) {
            $this->assertEquals(['visibility' => 'private', 'CacheControl' => 'max-age=60'], $options);
            $writes[] = $path;

            return $storage->put($path, $stream, $options);
        });
        $disk->shouldReceive('size')->andReturnUsing(fn ($path) => $storage->size($path));
        $result = $media->hls(segmentDuration: 0.7)->audio(bitrate: '48k')
            ->variant('small', fn (HlsVariant $variant) => $variant->resize(width: 80)->video(bitrate: '120k'))
            ->variant('large', fn (HlsVariant $variant) => $variant->video(bitrate: '300k'))
            ->toDisk($disk, ['CacheControl' => 'max-age=60'])->visibility('private')->save('course/é #?/master.m3u8');
        $this->assertCount(1, $result->processes());
        $this->assertSame('course/é #?/master.m3u8', $result->playlist()->path);
        $master = $storage->get($result->playlist()->path);
        $this->assertSame(2, substr_count($master, '#EXT-X-STREAM-INF:'));
        $this->assertStringContainsString('RESOLUTION=80x48', $master);
        $this->assertStringContainsString('RESOLUTION=160x96', $master);
        $this->assertStringContainsString("small/index.m3u8\n", $master);
        $this->assertStringContainsString("large/index.m3u8\n", $master);
        $this->assertSame('course/é #?/master.m3u8', end($writes));
        $firstPlaylist = array_search('course/é #?/small/index.m3u8', $writes);
        foreach (array_slice($writes, 0, $firstPlaylist) as $path) {
            $this->assertStringEndsWith('.ts', $path);
        }
        $this->assertSame(count($writes) - 3, $firstPlaylist);
        $durations = [];
        foreach (['small' => [80, 48], 'large' => [160, 96]] as $name => $dimensions) {
            $variant = $result->variant($name);
            $this->assertSame('course/é #?/'.$name.'/index.m3u8', $variant->playlist()->path);
            $playlist = $storage->get($variant->playlist()->path);
            $this->assertStringEndsWith("#EXT-X-ENDLIST\n", $playlist);
            preg_match_all('/#EXTINF:([0-9.]+),/', $playlist, $matches);
            $durations[$name] = array_map('floatval', $matches[1]);
            $this->assertCount(4, $durations[$name]);
            $files = $variant->files()->where('role', 'hls-segment')->values();
            $local = Storage::disk('local');
            foreach ($files as $index => $file) {
                $this->assertStringContainsString("\n".basename($file->path)."\n", $playlist);
                $key = 'playback/'.$name.'/'.basename($file->path);
                $local->put($key, $storage->get($file->path));
                $streams = $this->probe($local->path($key));
                $this->assertSame(['video', 'audio'], array_column($streams, 'codec_type'));
                $this->assertSame($dimensions, [$streams[0]['width'], $streams[0]['height']]);
                $this->assertSame(['h264', 'aac'], array_column($streams, 'codec_name'));
                $packet = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-select_streams', 'v:0',
                    '-read_intervals', '%+#1', '-show_packets', '-show_entries', 'packet=flags', '-of', 'json', $local->path($key)]);
                $this->assertTrue($packet->successful(), $packet->errorOutput());
                $this->assertStringContainsString('K', json_decode($packet->output(), true)['packets'][0]['flags']);
            }
            $local->put('playback/'.$name.'/index.m3u8', $playlist);
            $decode = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-xerror', '-i', $local->path('playback/'.$name.'/index.m3u8'),
                '-map', '0:v:0', '-map', '0:a:0', '-f', 'null', '-']);
            $this->assertTrue($decode->successful(), $decode->errorOutput());
        }
        $this->assertSame($durations['small'], $durations['large']);
        $this->assertEqualsWithDelta(0.7, $durations['small'][0], 0.05);
        $this->assertSame($original, Storage::disk('local')->get('source.mkv'));
        $this->assertSame($original, $storage->get('original/source.mkv'));
        $this->assertSame([], glob($this->root.'/*'));
        Event::assertDispatchedTimes(Events\ExportCompleted::class, 1);
    }

    #[Test]
    public function audio_only_silent_video_copy_and_all_audio_selection_are_preserved(): void
    {
        foreach ([[false, true, 'audio'], [true, false, 'video'], [true, true, 'both']] as [$video, $audio, $name]) {
            $media = $this->source($video, $audio);
            if (! $video) {
                $disk = Storage::disk('local');
                $attached = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-i', $disk->path('source.mkv'),
                    '-f', 'lavfi', '-i', 'color=red:size=32x32:duration=0.05', '-map', '0:a', '-map', '1:v',
                    '-c:a', 'copy', '-c:v', 'mjpeg', '-frames:v', '1', '-disposition:v', 'attached_pic', '-threads', '1', $disk->path('artwork.m4a')]);
                $this->assertTrue($attached->successful(), $attached->errorOutput());
                $media = FFMpeg::disk('local')->open('artwork.m4a');
                $this->assertCount(1, $media->probe()->coverArt());
            }
            $result = $media->hls(segmentDuration: 0.7)->video('copy')->audio('copy')
                ->variant($name, fn () => null)->save('default/master.m3u8');
            $this->assertSame('default/master.m3u8', $result->playlist()->path);
            $first = $result->variant($name)->files()->where('role', 'hls-segment')->first();
            $streams = $this->probe(Storage::disk('local')->path($first->path));
            $this->assertSame($video ? ($audio ? ['video', 'audio'] : ['video']) : ['audio'], array_column($streams, 'codec_type'));
            $this->assertStringNotContainsString('#EXT-X-INDEPENDENT-SEGMENTS', Storage::disk('local')->get($result->playlist()->path));
            if ($video && $audio) {
                $result = $media->hls(segmentDuration: 0.7)->video('copy')->audio('copy')->map('0:V:0')->map('0:a')
                    ->variant('all', fn () => null)->save('all/master.m3u8');
                $first = $result->variant('all')->files()->where('role', 'hls-segment')->first();
                $this->assertSame(['video', 'audio', 'audio'], array_column($this->probe(Storage::disk('local')->path($first->path)), 'codec_type'));
            }
            $encoded = $media->hls(segmentDuration: 0.2)->variant('encoded', fn () => null)->save('encoded/master.m3u8');
            $playlist = Storage::disk('local')->get($encoded->variant('encoded')->playlist()->path);
            $this->assertStringContainsString('#EXT-X-TARGETDURATION:1', $playlist);
            $first = $encoded->variant('encoded')->files()->where('role', 'hls-segment')->first();
            $this->assertSame($video ? ($audio ? ['video', 'audio'] : ['video']) : ['audio'],
                array_column($this->probe(Storage::disk('local')->path($first->path)), 'codec_type'));
            $this->assertSame([], glob($this->root.'/*'));
        }
    }

    #[Test]
    public function a_shared_watermark_graph_feeds_independent_clipped_hls_variants(): void
    {
        $media = $this->source();
        $disk = Storage::disk('local');
        $image = imagecreatetruecolor(24, 16);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagepng($image, $disk->path('logo.png'));
        $export = $media->hls(segmentDuration: 0.4)->clip(0.4, duration: 1.2)->audio(bitrate: '48k')->withoutMetadata(chapters: true);
        $this->assertSame(1, $export->addInput(FFMpeg::disk('local')->open('logo.png')));
        $result = $export->filterComplex('[0:v][1:v]overlay=x=4:y=2:eof_action=repeat:repeatlast=1:shortest=0,split=2[branch][large];[branch]scale=w=80:h=-2:reset_sar=1[small]')
            ->variant('small', fn (HlsVariant $variant) => $variant->map('[small]')->mapAudio(0))
            ->variant('large', fn (HlsVariant $variant) => $variant->map('[large]')->mapAudio(0))
            ->save('watermark/master.m3u8');
        $this->assertCount(1, $result->processes());
        foreach (['small' => [80, 7, 5], 'large' => [160, 14, 10]] as $name => [$width, $x, $y]) {
            $path = $disk->path($result->variant($name)->playlist()->path);
            $decoded = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-i', $path,
                '-frames:v', '1', '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-']);
            $this->assertTrue($decoded->successful(), $decoded->errorOutput());
            $this->assertSame($width * (int) ($width * 96 / 160) * 3, strlen($decoded->output()));
            $pixel = substr($decoded->output(), ($y * $width + $x) * 3, 3);
            foreach (unpack('C*', $pixel) as $channel) {
                $this->assertGreaterThan(235, $channel);
            }
            $metadata = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-show_entries', 'format=duration', '-of', 'json', $path]);
            $this->assertTrue($metadata->successful(), $metadata->errorOutput());
            $this->assertEqualsWithDelta(1.2, (float) json_decode($metadata->output(), true)['format']['duration'], 0.06);
        }
        $this->assertTrue($disk->exists('source.mkv'));
        $this->assertSame([24, 16], array_slice(getimagesize($disk->path('logo.png')), 0, 2));
        $this->assertSame([], glob($this->root.'/*'));
    }

    #[Test]
    public function an_extra_unlabeled_graph_stream_is_rejected_before_hls_publication(): void
    {
        $media = $this->source(video: true, audio: false);
        try {
            $media->hls(segmentDuration: 0.7)->filterComplex('[0:v]split=2[named]')
                ->variant('test', fn (HlsVariant $variant) => $variant->map('[named]'))
                ->toDisk('memory')->save('unexpected/master.m3u8');
            $this->fail('An unmapped graph stream was published in HLS.');
        } catch (MediaException $exception) {
            $this->assertSame(['validation', 'mapping'], [$exception->phase, $exception->category]);
        }
        $this->assertSame([], Storage::disk('memory')->allFiles());
        $this->assertSame([], glob($this->root.'/*'));
    }

    private function fakePipeline(?callable $mutate = null): void
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

                return Process::result(output: '{"format":{"duration":"2"},"streams":[{"index":0,"codec_type":"video"}]}');
            }
            foreach ($process->command as $token) {
                if (preg_match('/\/playlist-([0-9]+)\.m3u8$/', $token, $match)) {
                    $index = $match[1];
                    file_put_contents($token, "#EXTM3U\n#EXT-X-TARGETDURATION:2\n#EXT-X-PLAYLIST-TYPE:VOD\n#EXTINF:2.000000,\nsegment-$index-00000000.ts\n#EXT-X-ENDLIST\n");
                    file_put_contents($process->path.'/segment-'.$index.'-00000000.ts', 'fake encoded TS');
                    file_put_contents($process->path.'/master-'.$index.'.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=100000\n".basename($token)."\n");
                }
            }
            if ($mutate !== null) {
                $mutate($process);
            }

            return Process::result(output: "out_time_us=2000000\nprogress=end\n");
        });
    }

    public static function corruptions(): array
    {
        return array_map(fn ($case) => [$case], ['missing', 'empty', 'unreferenced', 'escape', 'network', 'duplicate', 'truncated', 'key', 'duration', 'whitespace', 'target']);
    }

    #[Test]
    #[DataProvider('corruptions')]
    public function invalid_generated_inventories_prevent_all_publication(string $case): void
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
                'network' => $playlist = str_replace('segment-0-00000000.ts', 'https://example.test/media.ts', $playlist),
                'duplicate' => $playlist = str_replace('#EXT-X-ENDLIST', "#EXTINF:2.000000,\nsegment-0-00000000.ts\n#EXT-X-ENDLIST", $playlist),
                'truncated' => $playlist = str_replace('#EXT-X-ENDLIST', '', $playlist),
                'key' => $playlist = str_replace('#EXTINF:', "#EXT-X-KEY:METHOD=AES-128,URI=\"key.bin\"\n#EXTINF:", $playlist),
                'duration' => $playlist = str_replace('2.000000', '0.000000', $playlist),
                'whitespace' => $playlist = "\n\n",
                'target' => $playlist = str_replace('#EXT-X-TARGETDURATION:2', '', $playlist),
            };
            file_put_contents($path.'/playlist-0.m3u8', $playlist);
        });
        try {
            FFMpeg::disk('memory')->open('source.mp4')->hls()->variant('test', fn () => null)->save('out/master.m3u8');
            $this->fail('Invalid HLS artifacts were published.');
        } catch (MediaException $exception) {
            $this->assertSame(['validation', 'artifact'], [$exception->phase, $exception->category]);
        }
        $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
        $this->assertSame([], glob($this->root.'/*'));
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    #[Test]
    public function master_bandwidth_comes_from_generated_segments_and_owned_segments_cannot_replace_the_source(): void
    {
        $storage = Storage::disk('memory');
        $storage->put('source.mp4', 'source');
        $this->fakePipeline(function (PendingProcess $process) {
            file_put_contents($process->path.'/segment-0-00000000.ts', str_repeat('a', 1000));
            file_put_contents($process->path.'/segment-0-00000001.ts', str_repeat('b', 100));
            $playlist = file_get_contents($process->path.'/playlist-0.m3u8');
            file_put_contents($process->path.'/playlist-0.m3u8', str_replace('#EXT-X-ENDLIST', "#EXTINF:0.500000,\nsegment-0-00000001.ts\n#EXT-X-ENDLIST", $playlist));
        });
        $result = FFMpeg::disk('memory')->open('source.mp4')->hls()->variant('test', fn () => null)->save('out/master.m3u8');
        $this->assertStringContainsString('#EXT-X-STREAM-INF:BANDWIDTH=4000,AVERAGE-BANDWIDTH=3520', $storage->get($result->playlist()->path));
        $this->assertSame('out/test/index.m3u8', $result->variant('test')->playlist()->path);
        $storage->put('protected/test/segment-00000000.ts', 'source bytes');
        try {
            FFMpeg::disk('memory')->open('protected/test/segment-00000000.ts')->hls()->variant('test', fn () => null)->save('protected/master.m3u8');
            $this->fail('An owned segment replaced the source.');
        } catch (InvalidArgumentException) {
            $this->assertSame('source bytes', $storage->get('protected/test/segment-00000000.ts'));
            $this->assertFalse($storage->exists('protected/master.m3u8'));
            $this->assertSame([], glob($this->root.'/*'));
        }
    }

    #[Test]
    public function partial_publication_reports_concrete_siblings_and_keeps_the_old_master(): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        $this->fakePipeline();
        $storage = Storage::disk('memory');
        $storage->put('out/master.m3u8', 'old master');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturnUsing(fn ($path, $stream, $options) =>
            $path === 'out/high/index.m3u8' ? false : $storage->put($path, $stream, $options));
        $disk->shouldReceive('size')->andReturnUsing(fn ($path) => $storage->size($path));
        try {
            FFMpeg::disk('memory')->open('source.mp4')->hls()->variant('low', fn () => null)->variant('high', fn () => null)
                ->toDisk($disk)->save('out/master.m3u8');
            $this->fail('A failed playlist upload was accepted.');
        } catch (MediaException $exception) {
            $this->assertSame(['publication', 'transfer'], [$exception->phase, $exception->category]);
            $this->assertSame(['out/low/segment-00000000.ts', 'out/high/segment-00000000.ts', 'out/low/index.m3u8'], array_column($exception->published, 'path'));
            $this->assertSame(['disk' => null, 'path' => 'out/high/index.m3u8'], $exception->destination);
        }
        $this->assertSame('old master', $storage->get('out/master.m3u8'));
        $this->assertSame('source', $storage->get('source.mp4'));
        $this->assertSame([], glob($this->root.'/*'));
    }

    #[Test]
    public function cancellation_freezes_variants_and_cleans_up_without_publishing(): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        $this->fakePipeline();
        $held = null;
        $export = FFMpeg::disk('memory')->open('source.mp4')->hls()->variant('test', function (HlsVariant $variant) use (&$held) {
            $held = $variant;
        })->onProgress(function (Progress $progress) use (&$held) {
            if ($progress->phase === Progress::STAGING) {
                try {
                    $held->resize(width: 42);
                    $this->fail('An executing variant was mutable.');
                } catch (\LogicException) {
                    throw new ExportCancelled;
                }
            }
        });
        try {
            $export->save('out/master.m3u8');
            $this->fail('Cancellation was swallowed.');
        } catch (ExportCancelled) {
            $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
            $this->assertSame([], glob($this->root.'/*'));
        }
        Event::assertDispatchedTimes(Events\ExportCancelled::class, 1);
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    #[Test]
    public function custom_paths_keep_variant_membership_and_encode_relative_urls_from_each_playlist(): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        $this->fakePipeline();
        $result = FFMpeg::disk('memory')->open('source.mp4')->hls()
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
        $this->assertStringContainsString("../../shared/%C3%A9%20%23%3F/low-000.ts\n", $disk->get('lists/low/index.m3u8'));
        $this->assertStringContainsString("../../shared/%C3%A9%20%23%3F/high-0.ts\n", $disk->get('lists/high/index.m3u8'));
        $this->assertStringContainsString("\nsegment-00000000.ts\n", $disk->get('root.m3u8'));
        $this->assertStringContainsString("\nsegment-00000000.ts\n", $disk->get('100% ready/index.m3u8'));
        $this->assertSame(['shared/é #?/low-000.ts', 'lists/low/index.m3u8'], $result->variant('low')->files()->pluck('path')->all());
        $this->assertSame(['shared/é #?/high-0.ts', 'lists/high/index.m3u8'], $result->variant('high')->files()->pluck('path')->all());
        $this->assertSame(['segment-00000000.ts', 'root.m3u8'], $result->variant('root')->files()->pluck('path')->all());
        $this->assertSame(['100% ready/segment-00000000.ts', '100% ready/index.m3u8'], $result->variant('literal')->files()->pluck('path')->all());
        $this->assertSame([], glob($this->root.'/*'));
    }

    #[Test]
    public function custom_nested_playlists_decode_with_segments_in_separate_directories(): void
    {
        $media = $this->source();
        $seen = [];
        $export = $media->hls(segmentDuration: 0.7)
            ->variant('low', fn ($variant) => $variant->resize(width: 80), playlist: 'lists/low/index.m3u8', segments: 'assets/low/part-%03d.ts')
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

                return str_replace("\n", "\r\n", str_replace("#EXTM3U\n", "#EXTM3U\n# Prepared for ".$path."\n".$tag."\n", $contents));
            });
        $export->inspect();
        $this->assertSame([], $seen);
        $result = $export->save('catalog/master.m3u8');
        $this->assertEquals(['lists/low/index.m3u8' => 'low', 'lists/high/index.m3u8' => 'high', 'catalog/master.m3u8' => null], $seen);
        $master = Storage::disk('local')->get($result->playlist()->path);
        $this->assertStringContainsString('#EXT-X-SESSION-DATA:DATA-ID="com.example.title",VALUE="Café"', $master);
        $this->assertStringContainsString("../lists/low/index.m3u8\n", $master);
        foreach (['low', 'high'] as $name) {
            $playlist = $result->variant($name)->playlist();
            $contents = Storage::disk('local')->get($playlist->path);
            $this->assertStringContainsString('# Prepared for '.$playlist->path, $contents);
            $this->assertStringContainsString('#EXT-X-START:TIME-OFFSET=0.2,PRECISE=YES', $contents);
            if ($name === 'high') {
                $this->assertStringContainsString('#EXT-X-PROGRAM-DATE-TIME:2026-01-01T00:00:00Z', $contents);
                $this->assertStringContainsString('#EXT-X-DATERANGE:ID="intro"', $contents);
            }
            $decoded = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-xerror',
                '-i', Storage::disk('local')->path($playlist->path), '-map', '0:v:0', '-map', '0:a:0', '-f', 'null', '-']);
            $this->assertTrue($decoded->successful(), $decoded->errorOutput());
            $this->assertCount(5, $result->variant($name)->files());
        }
        $decoded = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-xerror',
            '-i', Storage::disk('local')->path($result->playlist()->path), '-map', '0:v:0', '-map', '0:a:0', '-f', 'null', '-']);
        $this->assertTrue($decoded->successful(), $decoded->errorOutput());
        $this->assertSame([], glob($this->root.'/*'));
    }

    public static function encryptionModes(): array
    {
        return [["\x00\x01\x02\x03supplied-key", null], [null, null], [null, 2]];
    }

    #[Test]
    #[DataProvider('encryptionModes')]
    public function encrypted_variants_decrypt_to_the_original_bytes_and_play_through_rotation(?string $key, ?int $rotateEvery): void
    {
        $media = $this->source();
        $disk = Storage::disk('local');
        $secrets = Storage::fake('secrets');
        $export = $media->hls(segmentDuration: 0.7)
            ->variant('low', fn ($variant) => $variant->resize(width: 80), playlist: 'lists/low/index.m3u8', segments: 'assets/low/part-%03d.ts')
            ->variant('high', fn () => null, playlist: 'lists/high/index.m3u8', segments: 'assets/high/part-%d.ts');
        $plain = $export->save('master.m3u8');
        $original = $plain->files()->where('role', 'hls-segment')->mapWithKeys(fn ($file) => [$file->path => $disk->get($file->path)])->all();
        $command = $export->inspect();
        $export->encrypt('secrets', fn ($path) => $secrets->path($path), key: $key, rotateEvery: $rotateEvery, directory: 'lesson/keys')
            ->playlistUsing(fn ($text) => str_replace("#EXTM3U\n", "#EXTM3U\n# Encrypted lesson\n", $text));
        $this->assertSame($command, $export->inspect());
        $this->assertSame([], $secrets->allFiles());
        $result = $export->save('master.m3u8');
        $this->assertSame($plain->file()->path, $result->file()->path);
        $this->assertSame('hls-segment', $result->file()->role);
        $keys = $result->files()->where('role', 'hls-key')->values();
        $this->assertCount($rotateEvery === null ? 1 : 2, $keys);
        foreach ($keys as $file) {
            $this->assertSame(['secrets', 16, 'private'], [$file->disk, $file->size, $secrets->getVisibility($file->path)]);
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
            preg_match_all('/#EXT-X-KEY:METHOD=AES-128,URI="([^"]+)",IV=0x([0-9a-f]{32})\n([^#\n][^\n]*)/', $text, $tags, PREG_SET_ORDER);
            $this->assertCount(4, $tags);
            foreach ($tags as $sequence => $tag) {
                $index = $rotateEvery === null ? 0 : intdiv($sequence, 2);
                $this->assertSame($secrets->path($keys[$index]->path), $tag[1]);
                $ivs[] = $tag[2];
                $path = $variant->files()->where('role', 'hls-segment')->values()[$sequence]->path;
                $ciphertext = $disk->get($path);
                $this->assertSame((intdiv(strlen($original[$path]), 16) + 1) * 16, strlen($ciphertext));
                $this->assertSame($original[$path], openssl_decrypt($ciphertext, 'aes-128-cbc', $secrets->get($keys[$index]->path), OPENSSL_RAW_DATA, hex2bin($tag[2])));
            }
            $decoded = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-xerror',
                '-protocol_whitelist', 'file,crypto', '-allowed_extensions', 'm3u8,ts,key', '-i', $disk->path($variant->playlist()->path),
                '-map', '0:v:0', '-map', '0:a:0', '-f', 'null', '-']);
            $this->assertTrue($decoded->successful(), $decoded->errorOutput());
        }
        $this->assertCount(8, array_unique($ivs));
        $decoded = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-xerror',
            '-protocol_whitelist', 'file,crypto', '-allowed_extensions', 'm3u8,ts,key', '-i', $disk->path($result->playlist()->path), '-f', 'null', '-']);
        $this->assertTrue($decoded->successful(), $decoded->errorOutput());
        $this->assertStringNotContainsString('EXT-X-KEY', $disk->get($result->playlist()->path));
        $summaries = json_encode($result->processes()->all());
        foreach ($keys as $file) {
            $this->assertStringNotContainsString($file->path, $summaries);
            $this->assertStringNotContainsString(bin2hex($secrets->get($file->path)), $summaries);
        }
        $second = $export->save('again/master.m3u8')->files()->where('role', 'hls-key')->values();
        $this->assertNotSame($keys->pluck('path')->all(), $second->pluck('path')->all());
        if ($key === null) {
            $this->assertNotSame($secrets->get($keys[0]->path), $secrets->get($second[0]->path));
        }
        $this->assertTrue($disk->exists('source.mkv'));
        $this->assertSame([], glob($this->root.'/*'));
    }

    #[Test]
    public function encryption_handles_padding_and_cbc_chaining_across_large_segments(): void
    {
        $disk = Storage::disk('memory');
        $disk->put('source.mp4', 'source');
        $secrets = Storage::fake('secrets');
        foreach ([16, 1048576, 1048593] as $length) {
            $plain = substr(str_repeat("\x00\x01\x7f\xffasymmetric", 90000), 0, $length);
            $this->assertSame($length, strlen($plain));
            $this->fakePipeline(fn ($process) => file_put_contents($process->path.'/segment-0-00000000.ts', $plain));
            $result = FFMpeg::disk('memory')->open('source.mp4')->hls()->variant('test', fn () => null)
                ->encrypt('secrets', fn ($path) => 'https://keys.example.test/'.$path, key: '0123456789abcdef')->save('out/master.m3u8');
            preg_match('/IV=0x([0-9a-f]{32})/', $disk->get('out/test/index.m3u8'), $match);
            $ciphertext = $disk->get('out/test/segment-00000000.ts');
            $this->assertSame((intdiv($length, 16) + 1) * 16, strlen($ciphertext));
            $this->assertSame($plain, openssl_decrypt($ciphertext, 'aes-128-cbc', '0123456789abcdef', OPENSSL_RAW_DATA, hex2bin($match[1])));
        }
        $this->assertSame([], glob($this->root.'/*'));
    }

    #[Test]
    public function encryption_failures_and_hook_tampering_publish_nothing_and_clean_private_files(): void
    {
        $disk = Storage::disk('memory');
        $disk->put('source.mp4', 'source');
        $secrets = Storage::fake('secrets');
        foreach ([null, '', 'file:///secret', "https://example.test/key\n#EXT-X-KEY:METHOD=NONE", 'https://example.test/"key', 'https://example.test/key#fragment', '//example.test/key',
            "/keys/\xff.key", "/keys/\u{0085}.key", new \RuntimeException('key URL failure'), new ExportCancelled, 'tamper', 'reuse'] as $case) {
            $this->fakePipeline(function ($process) use ($case) {
                if ($case === 'reuse') {
                    file_put_contents($process->path.'/segment-0-00000001.ts', 'next segment');
                    $path = $process->path.'/playlist-0.m3u8';
                    file_put_contents($path, str_replace('#EXT-X-ENDLIST', "#EXTINF:1.000000,\nsegment-0-00000001.ts\n#EXT-X-ENDLIST", file_get_contents($path)));
                }
            });
            $export = FFMpeg::disk('memory')->open('source.mp4')->hls()->variant('test', fn () => null)
                ->encrypt('secrets', function () use ($case) {
                    if ($case instanceof \Throwable) {
                        throw $case;
                    }

                    return in_array($case, ['tamper', 'reuse'], true) ? '/keys/test' : $case;
                }, rotateEvery: $case === 'reuse' ? 1 : null);
            if ($case === 'tamper') {
                $export->playlistUsing(fn ($text) => preg_replace('/#EXT-X-KEY:[^\n]+\n/', '', $text));
            }
            foreach ([false, true] as $identityHook) {
                if ($identityHook && $case !== 'tamper') {
                    $export->playlistUsing(fn ($text) => $text);
                }
                try {
                    $export->save('out/master.m3u8');
                    $this->fail('Invalid encryption was published.');
                } catch (\RuntimeException $exception) {
                    if ($case instanceof \Throwable) {
                        $this->assertSame($case, $exception);
                    } else {
                        $this->assertInstanceOf(MediaException::class, $exception);
                        $this->assertSame(['validation', 'artifact'], [$exception->phase, $exception->category]);
                    }
                }
                $this->assertSame(['source.mp4'], $disk->allFiles());
                $this->assertSame([], $secrets->allFiles());
                $this->assertSame([], glob($this->root.'/*'));
            }
        }
    }

    #[Test]
    public function failed_private_key_publication_precedes_media_and_reports_successful_keys(): void
    {
        $disk = Storage::disk('memory');
        $disk->put('source.mp4', 'source');
        $storedKeys = Storage::fake('secrets');
        $this->fakePipeline();
        foreach ([false, true] as $keysSucceed) {
            $secrets = Mockery::mock(Filesystem::class);
            $secrets->shouldReceive('put')->andReturnUsing(fn ($path, $stream, $options) => $keysSucceed && $storedKeys->put($path, $stream, $options));
            $secrets->shouldReceive('size')->andReturnUsing(fn ($path) => $storedKeys->size($path));
            $failedMedia = Mockery::mock(Filesystem::class);
            $failedMedia->shouldReceive('put')->andReturnFalse();
            try {
                FFMpeg::disk('memory')->open('source.mp4')->hls()->variant('test', fn () => null)
                    ->encrypt($secrets, fn ($path) => '/keys/'.$path)->toDisk($failedMedia)->save('out/master.m3u8');
                $this->fail('A failed encrypted export was accepted.');
            } catch (MediaException $exception) {
                $this->assertSame(['publication', 'transfer'], [$exception->phase, $exception->category]);
                $this->assertCount($keysSucceed ? 1 : 0, $exception->published);
                if ($keysSucceed) {
                    $this->assertSame(16, $storedKeys->size($exception->published[0]['path']));
                    $this->assertSame('out/test/segment-00000000.ts', $exception->destination['path']);
                } else {
                    $this->assertStringEndsWith('.key', $exception->destination['path']);
                }
            }
        }
        $this->assertSame(['source.mp4'], $disk->allFiles());
        $this->assertSame([], glob($this->root.'/*'));
    }

    public static function invalidPlaylistHooks(): array
    {
        return [
            ['type', null], ['empty', null], ['reference', null], ['stream', null],
            ['key', null], ['resource', null], ['duplicate', null], ['large', null],
            ['control', null], ['header', null], ['endlist', 'test'], ['target', 'test'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPlaylistHooks')]
    public function invalid_playlist_hooks_prevent_all_publication(string $case, ?string $target): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        $this->fakePipeline();
        $export = FFMpeg::disk('memory')->open('source.mp4')->hls()->variant('test', fn () => null)
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
                    'resource' => str_replace("#EXTM3U\n", "#EXTM3U\n#EXT-X-SESSION-DATA:DATA-ID=\"external\",URI = \"https://example.test/info\"\n", $contents),
                    'duplicate' => $contents."#EXTM3U\n",
                    'large' => $contents.str_repeat('#', 16777216),
                    'control' => $contents."# comment\r#EXT-X-KEY:METHOD=NONE\n",
                    'header' => "\n".$contents,
                    'endlist' => str_replace('#EXT-X-ENDLIST', '', $contents),
                    'target' => str_replace('#EXT-X-TARGETDURATION:2', '#EXT-X-TARGETDURATION:99', $contents),
                };
            });
        try {
            $export->save('out/master.m3u8');
            $this->fail('Invalid hook output was published.');
        } catch (MediaException $exception) {
            $this->assertSame(['validation', 'artifact'], [$exception->phase, $exception->category]);
        }
        $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
        $this->assertSame([], glob($this->root.'/*'));
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    #[Test]
    public function playlist_hooks_freeze_configuration_and_propagate_failures_without_publication(): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        $this->fakePipeline();
        foreach ([new \RuntimeException('application failure'), new ExportCancelled] as $failure) {
            $export = FFMpeg::disk('memory')->open('source.mp4')->hls()->variant('test', fn () => null);
            $export->playlistUsing(function () use ($export, $failure) {
                try {
                    $export->playlistUsing(fn ($contents) => $contents);
                    $this->fail('A running playlist hook could be replaced.');
                } catch (InvalidArgumentException) {
                    throw $failure;
                }
            });
            try {
                $export->save('out/master.m3u8');
                $this->fail('A playlist callback failure was swallowed.');
            } catch (\RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }
            $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
            $this->assertSame([], glob($this->root.'/*'));
        }
        Event::assertDispatchedTimes(Events\ExportFailed::class, 1);
        Event::assertDispatchedTimes(Events\ExportCancelled::class, 1);
        Event::assertNotDispatched(Events\ExportCompleted::class);
    }

    #[Test]
    public function colliding_custom_segments_fail_before_any_write(): void
    {
        Storage::disk('memory')->put('source.mp4', 'source');
        $this->fakePipeline();
        try {
            FFMpeg::disk('memory')->open('source.mp4')->hls()
                ->variant('low', fn () => null, segments: 'shared/part-%d.ts')
                ->variant('high', fn () => null, segments: 'shared/part-%01d.ts')
                ->save('master.m3u8');
            $this->fail('Colliding concrete segment destinations were accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame(['source.mp4'], Storage::disk('memory')->allFiles());
            $this->assertSame([], glob($this->root.'/*'));
        }
    }

    #[Test]
    public function invalid_settings_fail_before_io(): void
    {
        Process::fake();
        $media = FFMpeg::disk('missing')->open('unread.mp4');
        foreach ([
            fn () => $media->hls(segmentDuration: 0),
            fn () => $media->hls(segmentDuration: INF),
            fn () => $media->hls()->inspect(),
            fn () => $media->hls()->variant('../escape', fn () => null),
            fn () => $media->hls()->variant('same', fn () => null)->variant('same', fn () => null),
            fn () => $media->hls()->variant('valid', fn () => null)->save('out.mp4'),
            fn () => $media->hls()->variant('valid', fn ($variant) => $variant->outputOptions(['-f', 'mp4']))->inspect(),
            fn () => $media->hls()->variant('valid', fn () => null)->twoPass(),
            fn () => $media->hls()->variant('valid', fn () => null, playlist: '../outside.m3u8'),
            fn () => $media->hls()->variant('valid', fn () => null, playlist: 'https://example.test/list.m3u8'),
            fn () => $media->hls()->variant('valid', fn () => null, segments: 'segment.ts'),
            fn () => $media->hls()->variant('valid', fn () => null, segments: 'seg-%d-%d.ts'),
            fn () => $media->hls()->variant('valid', fn () => null, segments: '%d/segment.ts'),
            fn () => $media->hls()->variant('valid', fn () => null, segments: 'seg-%10d.ts'),
            fn () => $media->hls()->encrypt('secrets', fn () => '/keys/key', key: 'short'),
            fn () => $media->hls()->encrypt('secrets', fn () => '/keys/key', key: str_repeat('x', 17)),
            fn () => $media->hls()->encrypt('secrets', fn () => '/keys/key', rotateEvery: 0),
            fn () => $media->hls()->encrypt('secrets', fn () => '/keys/key', key: str_repeat('x', 16), rotateEvery: 2),
            fn () => $media->hls()->encrypt('secrets', fn () => '/keys/key', directory: '../escape'),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Invalid HLS settings were accepted.');
            } catch (InvalidArgumentException|\League\Flysystem\PathTraversalDetected) {
                $this->assertSame([], glob($this->root.'/*'));
            }
        }
        Process::assertNothingRan();
    }
}

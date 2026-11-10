<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\HlsVariant;
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class HlsPackagingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/native-hls-packaging-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/work', 0700);
        config(['laravel-ffmpeg.temporary_files_root' => $this->root.'/work',
            'filesystems.disks.hls-packaging' => ['driver' => 'local', 'root' => $this->root.'/storage'],
            'filesystems.disks.hls-packaging-keys' => ['driver' => 'local', 'root' => $this->root.'/keys']]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function native(): string
    {
        if (! ($probe = getenv('FFMPEG_9_FFPROBE'))) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for HLS packaging acceptance.');
        }
        config(['laravel-ffmpeg.ffprobe.binaries' => $probe, 'laravel-ffmpeg.ffmpeg.binaries' => dirname($probe).'/ffmpeg']);

        return dirname($probe).'/ffmpeg';
    }

    private function source()
    {
        $binary = $this->native();
        $disk = Storage::disk('hls-packaging');
        $disk->put('captions.srt', "1\n00:00:00,200 --> 00:00:01,000\nHello HLS\n\n2\n00:00:01,200 --> 00:00:02,200\nSecond cue\n");
        $result = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=160x96:rate=20:duration=2.4',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=2.4',
            '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=48000:duration=2.4', '-i', $disk->path('captions.srt'),
            '-map', '0:v', '-map', '1:a', '-map', '2:a', '-map', '3:s', '-c:v', 'libx264', '-preset', 'ultrafast',
            '-g', '14', '-c:a', 'aac', '-c:s', 'srt', '-threads', '1', $disk->path('source.mkv')]);
        $this->assertTrue($result->successful(), $result->errorOutput());

        return FFMpeg::disk('hls-packaging')->open('source.mkv');
    }

    private function decode(string $path, array $maps = ['0:v:0', '0:a:0']): string
    {
        $command = [$this->native(), '-v', 'error', '-xerror', '-i', Storage::disk('hls-packaging')->path($path)];
        foreach ($maps as $map) {
            array_push($command, '-map', $map);
        }
        array_push($command, '-f', 'null', '-');
        $result = Process::run($command);
        $this->assertTrue($result->successful(), $result->errorOutput());

        return $result->output();
    }

    public static function packaging(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('packaging')]
    public function test_x265_variants_publish_hevc_playlists_and_decode_without_falling_back_to_h264(bool $fragmented): void
    {
        $export = $this->source()->hls(segmentDuration: 0.7)->video('libx265', crf: 28, preset: 'ultrafast')
            ->outputOptions(['-tag:v', 'hvc1'])
            ->variant('hevc', fn (HlsVariant $variant) => $variant->resize(width: 80));
        if ($fragmented) {
            $export->fmp4();
        }
        $result = $export->save('hevc/master.m3u8');
        $disk = Storage::disk('hls-packaging');
        $variant = $result->variant('hevc');
        $probe = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error',
            '-show_entries', 'stream=codec_type,codec_name,width,height', '-of', 'json', $disk->path($variant->playlist()->path)]);
        $this->assertTrue($probe->successful(), $probe->errorOutput());
        $streams = collect(json_decode($probe->output(), true, flags: JSON_THROW_ON_ERROR)['streams']);
        $video = $streams->firstWhere('codec_type', 'video');
        $this->assertSame('hevc', $video['codec_name']);
        $this->assertSame(80, $video['width']);
        $this->assertSame(48, $video['height']);
        $this->assertSame('aac', $streams->firstWhere('codec_type', 'audio')['codec_name']);
        $this->assertMatchesRegularExpression('/CODECS="[^"]*(?:hvc1|hev1)\./', $disk->get($result->playlist()->path));
        $this->assertStringContainsString('#EXT-X-ENDLIST', $disk->get($variant->playlist()->path));
        $this->assertCount($fragmented ? 1 : 0, $variant->files()->where('role', 'hls-init'));
        $this->decode($variant->playlist()->path);
        $this->decode($result->playlist()->path);
        $this->assertSame([], glob($this->root.'/work/*'));
    }

    #[DataProvider('packaging')]
    public function test_two_pass_variants_decode_with_private_isolated_statistics_and_no_first_pass_packaging(bool $fragmented): void
    {
        $media = $this->source();
        $export = $media->hls(segmentDuration: 0.7)->video(bitrate: '180k')->audio(bitrate: '48k')
            ->variant('small', fn ($v) => $v->resize(width: 80))
            ->variant('large', fn ($v) => $v->video(bitrate: '300k'));
        if ($fragmented) {
            $export->fmp4();
        }
        $inspectedSecondPass = false;
        $export->twoPass()->onProgress(function (Progress $progress) use (&$inspectedSecondPass) {
            if ($progress->phase !== Progress::ENCODING || $progress->pass !== 2 || $inspectedSecondPass) {
                return;
            }
            $inspectedSecondPass = true;
            $this->assertTrue(Storage::disk('hls-packaging')->missing('out/master.m3u8'));
            $workspace = glob($this->root.'/work/*')[0];
            $this->assertSame([], glob($workspace.'/*.m3u8'));
            $this->assertSame([], glob($workspace.'/*.ts'));
            $this->assertSame([], glob($workspace.'/*.m4s'));
            $this->assertSame([], glob($workspace.'/init-*.mp4'));
            $this->assertNotEmpty(glob($workspace.'/pass-0-*.log'));
            $this->assertNotEmpty(glob($workspace.'/pass-1-*.log'));
        });
        $result = $export->save('out/master.m3u8');
        $this->assertTrue($inspectedSecondPass);
        $this->assertCount(2, $result->processes());
        foreach (['small', 'large'] as $name) {
            $this->decode($result->variant($name)->playlist()->path);
            $this->assertCount($fragmented ? 1 : 0, $result->variant($name)->files()->where('role', 'hls-init'));
        }
        $this->decode($result->playlist()->path);
        $this->assertSame([], glob($this->root.'/work/*'));
    }

    public function test_custom_fragment_paths_and_dynamic_map_references_decode_without_publishing_probe_files(): void
    {
        $result = $this->source()->hls(segmentDuration: 0.7)->fmp4()
            ->variant('small', fn ($v) => $v->resize(width: 80), playlist: 'lists/small/index.m3u8',
                segments: 'assets/é #?/part-%03d.m4s', init: 'assets/é #?/start.mp4')
            ->save('catalog/master.m3u8');
        $disk = Storage::disk('hls-packaging');
        $playlist = $disk->get($result->variant('small')->playlist()->path);
        $this->assertStringContainsString('#EXT-X-MAP:URI="../../assets/%C3%A9%20%23%3F/start.mp4"', $playlist);
        $this->assertStringContainsString('../../assets/%C3%A9%20%23%3F/part-000.m4s', $playlist);
        // FFmpeg's local file demuxer does not URL-decode percent-encoded file paths; responses resolve disk keys.
        $rewritten = FFMpeg::disk('hls-packaging')->playlist($result->playlist()->path)
            ->playlistUrlsUsing(fn ($target) => $disk->path('responses/'.$target))
            ->mediaUrlsUsing(function ($target) use ($disk) {
                $safe = 'playback/'.basename($target);
                $disk->put($safe, $disk->get($target));

                return $disk->path($safe);
            })->all();
        foreach ($rewritten as $path => $contents) {
            $disk->put('responses/'.$path, $contents);
        }
        $this->decode('responses/'.$result->playlist()->path);
        $this->assertCount(1, $result->files()->where('role', 'hls-init'));
        $this->assertFalse(collect($disk->allFiles())->contains(fn ($path) => str_contains($path, '.probe.')));
        $this->assertSame([], glob($this->root.'/work/*'));
    }

    #[DataProvider('packaging')]
    public function test_reusable_audio_and_subtitle_groups_decode_each_track_and_rewrite_every_owned_playlist(bool $fragmented): void
    {
        $media = $this->source();
        $export = $media->hls(segmentDuration: 0.7)->video(bitrate: '180k')->audio(bitrate: '48k')
            ->variant('small', fn (HlsVariant $v) => $v->resize(width: 80)->audioGroup('sound')->subtitleGroup('captions'), playlist: 'video/small/index.m3u8')
            ->variant('large', fn (HlsVariant $v) => $v->audioGroup('sound')->subtitleGroup('captions'), playlist: 'video/large/index.m3u8')
            ->audioRendition('sound', 'English', fn ($v) => $v->map('0:a:0'), language: 'en', default: true, channels: '1', playlist: 'audio/en/index.m3u8')
            ->audioRendition('sound', 'Español, stereo name', fn ($v) => $v->map('0:a:1'), language: 'es', playlist: 'audio/es/index.m3u8')
            ->subtitleRendition('captions', 'English', fn ($v) => $v->map('0:s:0'), language: 'en', default: true, forced: false, playlist: 'text/en/index.m3u8');
        if ($fragmented) {
            $export->fmp4();
        }
        $seen = [];
        $result = $export->twoPass()->playlistUsing(function ($text, $path) use (&$seen) {
            $seen[] = $path;
            $this->assertFalse(Storage::disk('hls-packaging')->exists('catalog/master.m3u8'));

            return str_replace("#EXTM3U\n", "#EXTM3U\n# Application comment\n", $text);
        })->save('catalog/master.m3u8');
        $disk = Storage::disk('hls-packaging');
        $master = $disk->get($result->playlist()->path);
        $this->assertSame(3, substr_count($master, '#EXT-X-MEDIA:'));
        $this->assertSame(2, substr_count($master, '#EXT-X-STREAM-INF:'));
        $this->assertSame(2, substr_count($master, 'AUDIO="sound",SUBTITLES="captions"'));
        $this->assertStringContainsString('TYPE=AUDIO,GROUP-ID="sound",NAME="English",DEFAULT=YES,AUTOSELECT=YES,LANGUAGE="en",CHANNELS="1",URI="../audio/en/index.m3u8"', $master);
        $this->assertStringContainsString('TYPE=SUBTITLES,GROUP-ID="captions",NAME="English",DEFAULT=YES,AUTOSELECT=YES,LANGUAGE="en",FORCED=NO,URI="../text/en/index.m3u8"', $master);
        $this->assertStringContainsString('mp4a.40.2', $master);
        $this->assertCount(6, $seen);
        foreach (['English' => 440, 'Español, stereo name' => 880] as $name => $frequency) {
            $path = $result->audioRendition('sound', $name)->playlist()->path;
            $this->decode($path, ['0:a:0']);
            $pcm = Process::run([$this->native(), '-v', 'error', '-i', $disk->path($path), '-map', '0:a:0',
                '-t', '1', '-ar', '8000', '-ac', '1', '-f', 's16le', '-']);
            $this->assertTrue($pcm->successful(), $pcm->errorOutput());
            $previous = $crossings = 0;
            foreach (unpack('v*', $pcm->output()) as $sample) {
                $sample = $sample >= 32768 ? $sample - 65536 : $sample;
                if ($sample > 0 && $previous <= 0) {
                    $crossings++;
                }
                $previous = $sample;
            }
            $this->assertEqualsWithDelta($frequency, $crossings, 6);
        }
        $subtitle = $result->subtitleRendition('captions', 'English');
        $this->assertCount(2, $subtitle->files());
        $vtt = $subtitle->files()->firstWhere('role', 'hls-segment');
        $this->assertStringContainsString('Hello HLS', $disk->get($vtt->path));
        $this->assertStringContainsString('Second cue', $disk->get($vtt->path));
        preg_match('/X-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:([0-9]+)/', $disk->get($vtt->path), $clock);
        $this->assertNotEmpty($clock);
        $probe = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=start_time', '-of', 'json', $disk->path($result->variant('small')->playlist()->path)]);
        $this->assertTrue($probe->successful(), $probe->errorOutput());
        $this->assertEqualsWithDelta((float) json_decode($probe->output(), true)['streams'][0]['start_time'] * 90000, (int) $clock[1], 1);
        $decodeText = Process::run([$this->native(), '-v', 'error', '-i', $disk->path($vtt->path), '-f', 'srt', '-']);
        $this->assertTrue($decodeText->successful(), $decodeText->errorOutput());
        $this->assertStringContainsString('00:00:00,200 --> 00:00:01,000', $decodeText->output());
        $this->decode($result->playlist()->path);
        foreach (['small', 'large'] as $name) {
            $this->decode($result->variant($name)->playlist()->path, ['0:v:0']);
        }
        $rewritten = FFMpeg::disk('hls-packaging')->playlist($result->playlist()->path)
            ->playlistUrlsUsing(fn ($target) => $disk->path('responses/'.$target))
            ->mediaUrlsUsing(fn ($target) => $disk->path($target))->all();
        $this->assertCount(6, $rewritten);
        foreach ($rewritten as $path => $contents) {
            $disk->put('responses/'.$path, $contents);
        }
        $this->decode('responses/'.$result->playlist()->path);
        $this->assertCount($fragmented ? 4 : 0, $result->files()->where('role', 'hls-init'));
        $this->assertSame([], glob($this->root.'/work/*'));
    }

    public function test_invalid_packaging_and_group_settings_fail_before_io(): void
    {
        Process::fake();
        $media = FFMpeg::disk('absent')->open('unread.mp4');
        foreach ([
            fn () => $media->hls()->fmp4()->encrypt('absent', fn () => '/key'),
            fn () => $media->hls()->encrypt('absent', fn () => '/key')->fmp4(),
            fn () => $media->hls()->variant('v', fn () => null, init: 'init.mp4')->inspect(),
            fn () => $media->hls()->fmp4()->variant('v', fn () => null, segments: 'part-%d.ts')->inspect(),
            fn () => $media->hls()->variant('v', fn ($v) => $v->audioGroup('missing'))->inspect(),
            fn () => $media->hls()->audioRendition('a', 'one', fn () => null, default: true, autoselect: false),
            fn () => $media->hls()->audioRendition('a', 'one', fn () => null)->audioRendition('a', 'one', fn () => null),
            fn () => $media->hls()->audioRendition('a', 'one', fn () => null, default: true)->audioRendition('a', 'two', fn () => null, default: true),
            fn () => $media->hls()->audioRendition('../a', 'one', fn () => null),
            fn () => $media->hls()->subtitleRendition('s', 'bad"name', fn () => null),
            fn () => $media->hls()->variant('v', fn () => null)->audioRendition('a', 'one', fn () => null)->inspect(),
            fn () => $media->hls()->variant('v', fn () => null)->twoPass()->inspect(),
            fn () => $media->hls()->variant('v', fn ($v) => $v->subtitleGroup('s'))
                ->subtitleRendition('s', 'one', fn ($v) => $v->map('0:s:0'))->encrypt('absent', fn () => '/key')->inspect(),
            fn () => $media->hls()->variant('v', fn ($v) => $v->audioGroup('a'))->clip(0, duration: 1)
                ->audioRendition('a', 'one', fn ($v) => $v->map('0:a:0')->clip(0, duration: 2))->inspect(),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Invalid HLS packaging settings were accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame([], glob($this->root.'/work/*'));
            }
        }
        Process::assertNothingRan();
    }

    public static function corruptFragments(): array
    {
        return array_map(fn ($case) => [$case], ['missing-init', 'empty-init', 'extra-init', 'missing-map', 'duplicate-map', 'external-map', 'escape-map', 'missing-segment', 'extra-segment', 'hook-map']);
    }

    #[DataProvider('corruptFragments')]
    public function test_invalid_fragment_inventories_and_map_hook_tampering_prevent_every_write(string $case): void
    {
        $disk = Storage::disk('hls-packaging');
        $disk->put('source.mp4', 'source');
        Process::preventStrayProcesses();
        Process::fake(function ($process) use ($case) {
            if (in_array('-version', $process->command, true)) {
                return Process::result(output: basename($process->command[0]).' version 9.0.2');
            }
            if (basename($process->command[0]) === 'ffprobe') {
                return Process::result(output: '{"format":{"duration":"2"},"streams":[{"index":0,"codec_type":"video"}]}');
            }
            $path = $process->path;
            $map = '#EXT-X-MAP:URI="init-0.mp4"';
            $playlist = "#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-TARGETDURATION:2\n#EXT-X-PLAYLIST-TYPE:VOD\n".$map."\n#EXTINF:2.000000,\nsegment-0-00000000.m4s\n#EXT-X-ENDLIST\n";
            file_put_contents($path.'/init-0.mp4', 'owned initialization');
            file_put_contents($path.'/segment-0-00000000.m4s', 'owned fragment');
            file_put_contents($path.'/master-0.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=100000\nplaylist-0.m3u8\n");
            match ($case) {
                'missing-init' => unlink($path.'/init-0.mp4'),
                'empty-init' => file_put_contents($path.'/init-0.mp4', ''),
                'extra-init' => file_put_contents($path.'/init-0-extra.mp4', 'unreferenced initialization'),
                'missing-map' => $playlist = str_replace($map."\n", '', $playlist),
                'duplicate-map' => $playlist = str_replace($map, $map."\n".$map, $playlist),
                'external-map' => $playlist = str_replace('init-0.mp4', 'https://example.test/init.mp4', $playlist),
                'escape-map' => $playlist = str_replace('init-0.mp4', '../init.mp4', $playlist),
                'missing-segment' => unlink($path.'/segment-0-00000000.m4s'),
                'extra-segment' => file_put_contents($path.'/segment-0-00000001.m4s', 'unreferenced fragment'),
                default => null,
            };
            file_put_contents($path.'/playlist-0.m3u8', $playlist);

            return Process::result(output: "out_time_us=2000000\nprogress=end\n");
        });
        $export = FFMpeg::disk('hls-packaging')->open('source.mp4')->hls()->fmp4()->variant('v', fn () => null);
        if ($case === 'hook-map') {
            $export->playlistUsing(fn ($text) => preg_replace('/#EXT-X-MAP:[^\n]*\n/', '', $text));
        }
        try {
            $export->save('out/master.m3u8');
            $this->fail('An invalid initialization or fragment inventory was published.');
        } catch (MediaException $exception) {
            $this->assertSame(['validation', 'artifact'], [$exception->phase, $exception->category]);
        }
        $this->assertSame(['source.mp4'], $disk->allFiles());
        $this->assertSame([], glob($this->root.'/work/*'));
    }

    public function test_dynamic_group_traversal_reads_only_owned_local_playlists_never_external_playlists_init_keys_or_media(): void
    {
        $disk = Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $playlists = [
            'master.m3u8' => "#EXTM3U\n#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"a\",NAME=\"English, commentary\",URI=\"audio/en.m3u8\"\n"
                ."#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"s\",NAME=\"External\",URI=\"https://external.example.test/subs.m3u8\"\n"
                ."#EXT-X-STREAM-INF:BANDWIDTH=1000,AUDIO=\"a\"\nvideo/index.m3u8\n",
            'audio/en.m3u8' => "#EXTM3U\n#EXT-X-MAP:URI=\"../init/a.mp4\"\n#EXTINF:1,\n../audio/a.m4s\n",
            'video/index.m3u8' => "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"https://keys.example.test/key\"\n"
                ."#EXT-X-MAP:URI=\"https://cdn.example.test/init.mp4\",BYTERANGE=\"100@0\"\n#EXTINF:1,\n../video/v.m4s\n",
        ];
        foreach ($playlists as $path => $contents) {
            $disk->shouldReceive('readStream')->with($path)->once()->andReturnUsing(function () use ($contents) {
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, $contents);
                rewind($stream);

                return $stream;
            });
        }
        $calls = [];
        $resolver = function ($target, $source, $uri) use (&$calls) {
            $calls[] = [$target, $source, $uri];

            return 'https://signed.example.test/'.($target ?? 'external').'?signed=1';
        };
        $result = FFMpeg::disk($disk)->playlist('master.m3u8')->playlistUrlsUsing($resolver)->mediaUrlsUsing($resolver)->keyUrlsUsing($resolver)->all();
        $this->assertSame(array_keys($playlists), $result->keys()->all());
        $this->assertContains([null, 'master.m3u8', 'https://external.example.test/subs.m3u8'], $calls);
        $this->assertContains(['init/a.mp4', 'audio/en.m3u8', '../init/a.mp4'], $calls);
        $this->assertContains([null, 'video/index.m3u8', 'https://cdn.example.test/init.mp4'], $calls);
        $this->assertStringContainsString('BYTERANGE="100@0"', $result['video/index.m3u8']);
    }

    public function test_alternate_renditions_require_exactly_one_stream_of_the_declared_type_before_encoding(): void
    {
        $media = $this->source();
        foreach (['0:v:0', '0:a'] as $map) {
            try {
                $media->hls()->variant('v', fn ($v) => $v->audioGroup('a'))
                    ->audioRendition('a', 'one', fn ($v) => $v->map($map))->save('out/master.m3u8');
                $this->fail('An alternate audio rendition accepted a wrong stream type or multiple tracks.');
            } catch (MediaException $exception) {
                $this->assertSame(['probing', 'mapping'], [$exception->phase, $exception->category]);
                $this->assertFalse(Storage::disk('hls-packaging')->exists('out/master.m3u8'));
                $this->assertSame([], glob($this->root.'/work/*'));
            }
        }
    }

    public function test_cancelling_between_hls_passes_cleans_logs_without_publishing_media_or_private_keys(): void
    {
        $export = $this->source()->hls(segmentDuration: 0.7)->video(bitrate: '180k')->variant('v', fn () => null)
            ->encrypt('hls-packaging-keys', fn ($path) => '/keys/'.$path)->twoPass()
            ->onProgress(function (Progress $progress) {
                if ($progress->phase === Progress::ENCODING && $progress->pass === 2) {
                    throw new ExportCancelled;
                }
            });
        try {
            $export->save('out/master.m3u8');
            $this->fail('Cancellation between passes did not stop publication.');
        } catch (ExportCancelled) {
            $this->assertFalse(Storage::disk('hls-packaging')->exists('out/master.m3u8'));
            $this->assertSame([], Storage::disk('hls-packaging-keys')->allFiles());
            $this->assertSame([], glob($this->root.'/work/*'));
        }
    }
}

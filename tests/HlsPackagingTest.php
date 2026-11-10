<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\HlsVariant;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class HlsPackagingTest extends TestCase
{
    /**
     * The directory with the rendition group exports shared by the whole class.
     */
    protected static ?string $groups = null;

    /**
     * The rendition group exports and their hooked playlists, keyed by segment format.
     */
    protected static array $groupExports = [];

    /**
     * Remove the shared rendition group exports after the last test of the class.
     */
    public static function tearDownAfterClass(): void
    {
        if (static::$groups !== null) {
            (new Filesystem)->deleteDirectory(static::$groups);

            static::$groups = null;
            static::$groupExports = [];
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.disks.hls-packaging' => ['driver' => 'local', 'root' => $this->root.'/storage'],
            'filesystems.disks.hls-packaging-keys' => ['driver' => 'local', 'root' => $this->root.'/keys']]);
    }

    protected function source(string $disk = 'hls-packaging')
    {
        $binary = $this->useRealFFMpeg();
        $name = $disk;
        $disk = Storage::disk($name);
        $disk->put('captions.srt', "1\n00:00:00,200 --> 00:00:01,000\nHello HLS\n\n2\n00:00:01,200 --> 00:00:02,200\nSecond cue\n");
        $result = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=160x96:rate=20:duration=2.4',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=2.4',
            '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=48000:duration=2.4', '-i', $disk->path('captions.srt'),
            '-map', '0:v', '-map', '1:a', '-map', '2:a', '-map', '3:s', '-c:v', 'libx264', '-preset', 'ultrafast',
            '-g', '14', '-c:a', 'aac', '-c:s', 'srt', '-threads', '1', $disk->path('source.mkv')]);
        $this->assertProcessSucceeded($result);

        return FFMpeg::disk($name)->open('source.mkv');
    }

    protected function decode(string $path, array $maps = ['0:v:0', '0:a:0'], string $disk = 'hls-packaging'): string
    {
        $command = [$this->useRealFFMpeg(), '-v', 'error', '-xerror', '-i', Storage::disk($disk)->path($path)];
        foreach ($maps as $map) {
            array_push($command, '-map', $map);
        }
        array_push($command, '-f', 'null', '-');
        $result = Process::run($command);
        $this->assertProcessSucceeded($result);

        return $result->output();
    }

    /**
     * Get the segment formats, as whether the segments are fragmented MP4.
     */
    public static function packagingProvider(): array
    {
        return [
            'mpeg-ts' => [false],
            'fmp4' => [true],
        ];
    }

    #[DataProvider('packagingProvider')]
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
        $this->assertProcessSucceeded($probe);
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
        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('packagingProvider')]
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
            $workspace = glob($this->root.'/workspaces/*')[0];
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
        $this->assertWorkspacesAreCleanedUp();
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
        $this->assertWorkspacesAreCleanedUp();
    }

    /**
     * Export the rendition group ladder once per segment format and get its disk, result and hooked playlists.
     */
    protected function exportGroups(bool $fragmented): array
    {
        $this->useRealFFMpeg();

        // The two-pass ladder with its renditions is the slowest export in this class. We
        // encode it once per segment format in a directory shared by the whole class, so
        // each test below can check one part of the same published package.
        static::$groups ??= sys_get_temp_dir().'/laravel-ffmpeg-hls-groups-'.bin2hex(random_bytes(8));

        $type = $fragmented ? 'fmp4' : 'ts';
        $disk = 'hls-groups-'.$type;

        config(["filesystems.disks.{$disk}" => ['driver' => 'local', 'root' => static::$groups.'/'.$type]]);

        static::$groupExports[$type] ??= $this->saveGroups($disk, $fragmented);

        return [$disk, ...static::$groupExports[$type]];
    }

    /**
     * Save the rendition group ladder to the given disk and get its result and hooked playlists.
     */
    protected function saveGroups(string $disk, bool $fragmented): array
    {
        $media = $this->source($disk);
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
        $result = $export->twoPass()->playlistUsing(function ($text, $path) use (&$seen, $disk) {
            $seen[] = $path;
            $this->assertFalse(Storage::disk($disk)->exists('catalog/master.m3u8'));

            return str_replace("#EXTM3U\n", "#EXTM3U\n# Application comment\n", $text);
        })->save('catalog/master.m3u8');
        $this->assertWorkspacesAreCleanedUp();

        return [$result, $seen];
    }

    #[DataProvider('packagingProvider')]
    public function test_rendition_groups_are_listed_in_the_master_playlist(bool $fragmented): void
    {
        [$disk, $result, $seen] = $this->exportGroups($fragmented);
        $master = Storage::disk($disk)->get($result->playlist()->path);
        $this->assertSame(3, substr_count($master, '#EXT-X-MEDIA:'));
        $this->assertSame(2, substr_count($master, '#EXT-X-STREAM-INF:'));
        $this->assertSame(2, substr_count($master, 'AUDIO="sound",SUBTITLES="captions"'));
        $this->assertStringContainsString('TYPE=AUDIO,GROUP-ID="sound",NAME="English",DEFAULT=YES,AUTOSELECT=YES,LANGUAGE="en",CHANNELS="1",URI="../audio/en/index.m3u8"', $master);
        $this->assertStringContainsString('TYPE=SUBTITLES,GROUP-ID="captions",NAME="English",DEFAULT=YES,AUTOSELECT=YES,LANGUAGE="en",FORCED=NO,URI="../text/en/index.m3u8"', $master);
        $this->assertStringContainsString('mp4a.40.2', $master);
        $this->assertCount(6, $seen);
        $this->assertCount($fragmented ? 4 : 0, $result->files()->where('role', 'hls-init'));
    }

    #[DataProvider('packagingProvider')]
    public function test_audio_renditions_decode_their_own_track(bool $fragmented): void
    {
        [$disk, $result] = $this->exportGroups($fragmented);
        foreach (['English' => 440, 'Español, stereo name' => 880] as $name => $frequency) {
            $path = $result->audioRendition('sound', $name)->playlist()->path;
            $this->decode($path, ['0:a:0'], $disk);
            $pcm = Process::run([$this->useRealFFMpeg(), '-v', 'error', '-i', Storage::disk($disk)->path($path), '-map', '0:a:0',
                '-t', '1', '-ar', '8000', '-ac', '1', '-f', 's16le', '-']);
            $this->assertProcessSucceeded($pcm);
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
    }

    #[DataProvider('packagingProvider')]
    public function test_subtitle_renditions_keep_their_cues_on_the_video_clock(bool $fragmented): void
    {
        [$disk, $result] = $this->exportGroups($fragmented);
        $disk = Storage::disk($disk);
        $subtitle = $result->subtitleRendition('captions', 'English');
        $this->assertCount(2, $subtitle->files());
        $vtt = $subtitle->files()->firstWhere('role', 'hls-segment');
        $this->assertStringContainsString('Hello HLS', $disk->get($vtt->path));
        $this->assertStringContainsString('Second cue', $disk->get($vtt->path));
        preg_match('/X-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:([0-9]+)/', $disk->get($vtt->path), $clock);
        $this->assertNotEmpty($clock);
        $probe = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=start_time', '-of', 'json', $disk->path($result->variant('small')->playlist()->path)]);
        $this->assertProcessSucceeded($probe);
        $this->assertEqualsWithDelta((float) json_decode($probe->output(), true)['streams'][0]['start_time'] * 90000, (int) $clock[1], 1);
        $decodeText = Process::run([$this->useRealFFMpeg(), '-v', 'error', '-i', $disk->path($vtt->path), '-f', 'srt', '-']);
        $this->assertProcessSucceeded($decodeText);
        $this->assertStringContainsString('00:00:00,200 --> 00:00:01,000', $decodeText->output());
    }

    #[DataProvider('packagingProvider')]
    public function test_grouped_master_and_variant_playlists_decode(bool $fragmented): void
    {
        [$disk, $result] = $this->exportGroups($fragmented);
        $this->decode($result->playlist()->path, disk: $disk);
        foreach (['small', 'large'] as $name) {
            $this->decode($result->variant($name)->playlist()->path, ['0:v:0'], $disk);
        }
    }

    #[DataProvider('packagingProvider')]
    public function test_rewriting_grouped_playlists_covers_every_owned_playlist(bool $fragmented): void
    {
        [$name, $result] = $this->exportGroups($fragmented);
        $disk = Storage::disk($name);
        $rewritten = FFMpeg::disk($name)->playlist($result->playlist()->path)
            ->playlistUrlsUsing(fn ($target) => $disk->path('responses/'.$target))
            ->mediaUrlsUsing(fn ($target) => $disk->path($target))->all();
        $this->assertCount(6, $rewritten);
        foreach ($rewritten as $path => $contents) {
            $disk->put('responses/'.$path, $contents);
        }
        $this->decode('responses/'.$result->playlist()->path, disk: $name);
    }

    #[DataProvider('invalidPackagingProvider')]
    public function test_invalid_packaging_and_group_settings_fail_before_io(Closure $invalid): void
    {
        Process::fake();
        $media = FFMpeg::disk('absent')->open('unread.mp4');

        $this->assertThrows(fn () => $invalid($media), InvalidArgumentException::class);

        $this->assertWorkspacesAreCleanedUp();
        Process::assertNothingRan();
    }

    /**
     * Get the HLS packaging and rendition group settings that conflict or are incomplete.
     */
    public static function invalidPackagingProvider(): array
    {
        return [
            'encryption before fmp4' => [fn (Media $media) => $media->hls()->fmp4()->encrypt('absent', fn () => '/key')],
            'fmp4 after encryption' => [fn (Media $media) => $media->hls()->encrypt('absent', fn () => '/key')->fmp4()],
            'init path without fmp4' => [fn (Media $media) => $media->hls()->variant('v', fn () => null, init: 'init.mp4')->inspect()],
            'ts segments with fmp4' => [fn (Media $media) => $media->hls()->fmp4()->variant('v', fn () => null, segments: 'part-%d.ts')->inspect()],
            'undeclared audio group' => [fn (Media $media) => $media->hls()->variant('v', fn ($v) => $v->audioGroup('missing'))->inspect()],
            'default without autoselect' => [fn (Media $media) => $media->hls()->audioRendition('a', 'one', fn () => null, default: true, autoselect: false)],
            'duplicate rendition name' => [fn (Media $media) => $media->hls()->audioRendition('a', 'one', fn () => null)->audioRendition('a', 'one', fn () => null)],
            'two defaults in a group' => [fn (Media $media) => $media->hls()->audioRendition('a', 'one', fn () => null, default: true)->audioRendition('a', 'two', fn () => null, default: true)],
            'group id with a path' => [fn (Media $media) => $media->hls()->audioRendition('../a', 'one', fn () => null)],
            'quote in a name' => [fn (Media $media) => $media->hls()->subtitleRendition('s', 'bad"name', fn () => null)],
            'unreferenced group' => [fn (Media $media) => $media->hls()->variant('v', fn () => null)->audioRendition('a', 'one', fn () => null)->inspect()],
            'two-pass inspection' => [fn (Media $media) => $media->hls()->variant('v', fn () => null)->twoPass()->inspect()],
            'encrypted subtitles' => [fn (Media $media) => $media->hls()->variant('v', fn ($v) => $v->subtitleGroup('s'))
                ->subtitleRendition('s', 'one', fn ($v) => $v->map('0:s:0'))->encrypt('absent', fn () => '/key')->inspect()],
            'rendition longer than its variant' => [fn (Media $media) => $media->hls()->variant('v', fn ($v) => $v->audioGroup('a'))->clip(0, duration: 1)
                ->audioRendition('a', 'one', fn ($v) => $v->map('0:a:0')->clip(0, duration: 2))->inspect()],
        ];
    }

    /**
     * Get the ways FFmpeg's fragment inventory or the playlist hook can break an fMP4 export.
     */
    public static function corruptFragmentProvider(): array
    {
        return [
            'missing init' => ['missing-init'],
            'empty init' => ['empty-init'],
            'extra init' => ['extra-init'],
            'missing map' => ['missing-map'],
            'duplicate map' => ['duplicate-map'],
            'external map' => ['external-map'],
            'escaping map' => ['escape-map'],
            'missing segment' => ['missing-segment'],
            'extra segment' => ['extra-segment'],
            'map removed by the hook' => ['hook-map'],
        ];
    }

    #[DataProvider('corruptFragmentProvider')]
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
        $this->assertThrows(
            fn () => $export->save('out/master.m3u8'),
            fn (MediaException $exception) => [$exception->phase, $exception->category] === ['validation', 'artifact'],
        );
        $this->assertSame(['source.mp4'], $disk->allFiles());
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_dynamic_group_traversal_reads_only_owned_local_playlists_never_external_playlists_init_keys_or_media(): void
    {
        $disk = Mockery::mock(FilesystemContract::class);
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

    #[TestWith(['0:v:0'])]
    #[TestWith(['0:a'])]
    public function test_alternate_renditions_require_exactly_one_stream_of_the_declared_type_before_encoding(string $map): void
    {
        $media = $this->source();

        $this->assertThrows(
            fn () => $media->hls()->variant('v', fn ($v) => $v->audioGroup('a'))
                ->audioRendition('a', 'one', fn ($v) => $v->map($map))->save('out/master.m3u8'),
            fn (MediaException $exception) => [$exception->phase, $exception->category] === ['probing', 'mapping'],
        );
        $this->assertFalse(Storage::disk('hls-packaging')->exists('out/master.m3u8'));
        $this->assertWorkspacesAreCleanedUp();
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
        $this->assertThrows(fn () => $export->save('out/master.m3u8'), ExportCancelled::class);
        $this->assertFalse(Storage::disk('hls-packaging')->exists('out/master.m3u8'));
        $this->assertSame([], Storage::disk('hls-packaging-keys')->allFiles());
        $this->assertWorkspacesAreCleanedUp();
    }
}

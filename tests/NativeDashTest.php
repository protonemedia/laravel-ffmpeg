<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use DOMDocument;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\DashManifest;
use ProtoneMedia\LaravelFFMpeg\DashRepresentation;
use ProtoneMedia\LaravelFFMpeg\Events;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\PendingDashExport;
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Queue\ExportPlan;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Support\Hls;

class NativeDashTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $directory = sys_get_temp_dir().'/native-dash-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->root = $directory.'/workspaces';
        mkdir($this->root, 0700);
        config(['laravel-ffmpeg.temporary_files_root' => $this->root,
            'filesystems.disks.local.root' => $directory.'/disk']);
        Event::fake([Events\ExportStarting::class, Events\ExportCompleted::class, Events\ExportFailed::class,
            Events\ExportCancelled::class, Events\QueuedExportCompleted::class]);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory(dirname($this->root));
        parent::tearDown();
    }

    private function source(): Media
    {
        $probe = getenv('FFMPEG_9_FFPROBE');
        if (! $probe) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for native DASH acceptance.');
        }
        config(['laravel-ffmpeg.ffprobe.binaries' => $probe, 'laravel-ffmpeg.ffmpeg.binaries' => dirname($probe).'/ffmpeg']);
        $disk = Storage::disk('local');
        $disk->makeDirectory('');
        $process = Process::run([dirname($probe).'/ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=160x96:rate=20:duration=2.3',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=2.3', '-map', '0:v', '-map', '1:a',
            '-c:v', 'libx264', '-threads', '1', '-c:a', 'aac', $disk->path('source.mkv')]);
        $this->assertTrue($process->successful(), $process->errorOutput());

        return FFMpeg::disk('local')->open('source.mkv');
    }

    private function export(Media $media): PendingDashExport
    {
        return (new PendingDashExport($media, 'local', 0.7))
            ->adaptationSet('picture', 'video')->adaptationSet('sound', 'audio')
            ->representation('low', 'picture', fn (DashRepresentation $r) => $r->map('0:v:0')->resize(width: 80)->video(bitrate: '120k'))
            ->representation('high', 'picture', fn (DashRepresentation $r) => $r->map('0:v:0')->video(bitrate: '300k'))
            ->representation('audio', 'sound', fn (DashRepresentation $r) => $r->map('0:a:0')->audio(bitrate: '48k'))
            ->representation('audio-high', 'sound', fn (DashRepresentation $r) => $r->map('0:a:0')->audio(bitrate: '96k'));
    }

    #[Test]
    public function inspect_is_pure_and_queue_state_roundtrips_with_explicit_adaptation_sets(): void
    {
        Process::fake();
        $export = $this->export(FFMpeg::disk('absent')->open('unread.mp4'))->sharedHls()->outputOptions(['-threads', '2']);
        $command = $export->inspect();
        $this->assertSame($command, $export->inspect());
        $this->assertSame($command, unserialize(serialize(new ExportPlan($export)))->restore()->inspect());
        $this->assertSame(4, count(array_keys($command, 'dash', true)));
        $this->assertContains('expr:gte(t,n_forced*0.7)', $command);
        $this->assertContains('segment-$Number%08d$.m4s', $command);
        $this->assertFalse(is_callable([new DashRepresentation, 'toDisk']));
        $this->assertFalse(is_callable([new DashRepresentation, 'audioGroup']));
        Process::assertNothingRan();
        $this->assertSame([], glob($this->root.'/*'));
    }

    #[Test]
    public function native_mpd_and_opt_in_hls_decode_the_exact_same_owned_segments(): void
    {
        $media = $this->source();
        $storage = Storage::disk('memory');
        $storage->put('source.mkv', Storage::disk('local')->get('source.mkv'));
        $writes = $writeOptions = [];
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturnUsing(function ($path, $stream, $options) use ($storage, &$writes, &$writeOptions) {
            $writes[] = $path;
            $writeOptions[serialize($options)] = $options;

            return $storage->put($path, $stream, $options);
        });
        $disk->shouldReceive('size')->andReturnUsing(fn ($path) => $storage->size($path));
        $result = $this->export(FFMpeg::disk('memory')->open('source.mkv'))->sharedHls()
            ->toDisk($disk, ['CacheControl' => 'max-age=60'])->visibility('private')->save('nested/é #?/manifest.mpd');
        $this->assertSame('nested/é #?/manifest.mpd', $result->manifest()->path);
        $this->assertSame('nested/é #?/master.m3u8', $result->hlsPlaylist()->path);
        $this->assertSame($result->manifest()->path, end($writes));
        $this->assertEquals([['visibility' => 'private', 'CacheControl' => 'max-age=60']], array_values($writeOptions));
        $this->assertSame(1, $result->processes()->count());
        $this->assertSame(['dash-init' => 4, 'dash-segment' => 16, 'hls-playlist' => 4, 'hls-master' => 1, 'dash-manifest' => 1],
            $result->files()->countBy('role')->all());
        $this->assertCount(2, $result->adaptationSets());
        $xml = new DOMDocument;
        $xml->loadXML($storage->get($result->manifest()->path));
        $this->assertSame(4, $xml->getElementsByTagName('Representation')->length);
        $firstManifest = array_search('nested/é #?/low/index.m3u8', $writes);
        foreach (array_slice($writes, 0, $firstManifest) as $path) {
            $this->assertMatchesRegularExpression('/\.(?:mp4|m4s)\z/', $path);
        }
        $local = Storage::disk('local');
        foreach ($result->files() as $file) {
            $local->put($file->path, $storage->get($file->path));
            // FFmpeg interprets literal #/? in a local playlist path as URL syntax.
            $local->put('playback/'.substr($file->path, strlen('nested/é #?/')), $storage->get($file->path));
        }
        $videoTimelines = [];
        foreach ($xml->getElementsByTagName('Representation') as $representation) {
            $name = $representation->getAttribute('id');
            $files = $result->representation($name)->files();
            $this->assertCount(6, $files);
            $template = $representation->getElementsByTagName('SegmentTemplate')->item(0);
            $this->assertTrue($local->exists('nested/é #?/'.$template->getAttribute('initialization')));
            $playlist = $local->get('nested/é #?/'.$name.'/index.m3u8');
            preg_match_all('/^segment-[0-9]+\.m4s$/m', $playlist, $matches);
            $this->assertSame($files->where('role', 'dash-segment')->map(fn ($file) => basename($file->path))->values()->all(), $matches[0]);
            foreach ($files->where('role', 'dash-segment')->values() as $i => $file) {
                $this->assertSame('nested/é #?/'.str_replace('$Number%08d$', sprintf('%08d', $i + 1), $template->getAttribute('media')), $file->path);
                $probe = $this->root.'/fragment.mp4';
                file_put_contents($probe, $local->get('nested/é #?/'.$name.'/init.mp4').$local->get($file->path));
                $check = Process::run([config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-show_streams', '-show_packets', '-of', 'json', $probe]);
                $this->assertTrue($check->successful(), $check->errorOutput());
                $data = json_decode($check->output(), true);
                $this->assertSame([str_starts_with($name, 'audio') ? 'audio' : 'video'], array_column($data['streams'], 'codec_type'));
                if (! str_starts_with($name, 'audio')) {
                    $this->assertStringContainsString('K', $data['packets'][0]['flags']);
                    $this->assertSame($name === 'low' ? 80 : 160, $data['streams'][0]['width']);
                }
            }
            if (! str_starts_with($name, 'audio')) {
                $videoTimelines[] = $xml->saveXML($template->getElementsByTagName('SegmentTimeline')->item(0));
            }
            $this->decode($local->path('playback/'.$name.'/index.m3u8'), ['-map', '0']);
        }
        $this->assertSame($videoTimelines[0], $videoTimelines[1]);
        $this->decode($local->path('playback/manifest.mpd'), ['-map', '0:v:0', '-map', '0:v:1', '-map', '0:a:0', '-map', '0:a:1']);
        $this->decode($local->path('playback/master.m3u8'), ['-map', '0:v:0', '-map', '0:a:0']);
        unlink($this->root.'/fragment.mp4');
        $this->assertSame([], glob($this->root.'/*'));
        Event::assertDispatched(Events\ExportCompleted::class);
    }

    private function decode(string $path, array $maps): void
    {
        $binary = config('laravel-ffmpeg.ffmpeg.binaries');
        $decode = Process::run([$binary, '-v', 'error', '-xerror', '-i', $path, ...$maps, '-f', 'null', '-']);
        $this->assertTrue($decode->successful(), $decode->errorOutput());
    }

    #[Test]
    public function shared_hls_plays_through_protected_hls_routes(): void
    {
        $result = $this->export($this->source())->sharedHls()->save('protected/manifest.mpd');
        $disk = Storage::disk('local');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Hls::define('dash', resolve: fn () => $result->playback(), authorize: fn () => true);
        Hls::routes('/dash/hls', name: 'dash.hls', playback: 'dash');
        $this->actingAs(new \Illuminate\Auth\GenericUser(['id' => 1]));
        $links = fn (string $text) => preg_match_all('~https?://[^\s"]+~', $text, $matches) ? $matches[0] : [];

        $master = $this->get(Hls::url('dash.hls', [], expires: now()->addMinutes(5)))->assertOk()->getContent();
        $this->assertSame(2, substr_count($master, '#EXT-X-STREAM-INF:'));
        $this->assertCount(4, $links($master));
        $variant = $this->get($links($master)[2])->assertOk()->getContent();
        $media = $links($variant);
        $this->assertCount(5, $media);
        $init = $result->representation('low')->files()->firstWhere('role', 'dash-init');
        $this->assertSame($disk->get($init->path), $this->get($media[0])->assertOk()->streamedContent());

        $selected = $result->playbackForVariants(['low'])->render($result->playback()->file(), fn ($id) => 'owned/'.$id);
        $this->assertSame(1, substr_count($selected, '#EXT-X-STREAM-INF:'));
        $this->assertSame(2, substr_count($selected, '#EXT-X-MEDIA:'));
        $this->assertNotContains('dash-manifest', array_column($result->playback()->toArray()['files'], 'role'));
    }

    #[Test]
    public function ordinary_queue_job_serialization_runs_without_generating_hls_unless_requested(): void
    {
        $export = $this->export($this->source());
        $job = unserialize(serialize($export->queued()->job('queued/manifest.mpd')));
        $job->handle();
        $this->assertTrue(Storage::disk('local')->exists('queued/manifest.mpd'));
        $this->assertFalse(Storage::disk('local')->exists('queued/master.m3u8'));
        $this->decode(Storage::disk('local')->path('queued/manifest.mpd'), ['-map', '0']);
        Event::assertDispatched(Events\QueuedExportCompleted::class, fn ($event) => $event->playback === null);
        unserialize(serialize($this->export($this->source())->sharedHls()->queued()->job('shared/manifest.mpd')))->handle();
        Event::assertDispatched(Events\QueuedExportCompleted::class, fn ($event) => $event->playback !== null
            && HlsPresentation::fromArray($event->playback)->file()['path'] === 'shared/master.m3u8');
        $this->assertSame([], glob($this->root.'/*'));
    }

    #[Test]
    public function cancellation_before_publication_publishes_nothing_and_preserves_events(): void
    {
        $export = $this->export($this->source())->onProgress(static function ($progress) {
            if ($progress->phase === Progress::VALIDATION) {
                throw new ExportCancelled;
            }
        });
        try {
            $export->save('cancel/manifest.mpd');
            $this->fail('Expected cancellation.');
        } catch (ExportCancelled) {
            $this->assertSame([], Storage::disk('local')->allFiles('cancel'));
            Event::assertDispatched(Events\ExportCancelled::class);
            $this->assertSame([], glob($this->root.'/*'));
        }
    }

    #[Test]
    public function partial_publication_reports_exact_children_and_never_publishes_the_manifest(): void
    {
        $export = $this->export($this->source());
        $writes = [];
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturnUsing(function ($path, $stream) use (&$writes) {
            if (count($writes) === 2) {
                return false;
            }
            $writes[$path] = strlen(stream_get_contents($stream));

            return true;
        });
        $disk->shouldReceive('size')->andReturnUsing(function ($path) use (&$writes) {
            return $writes[$path];
        });
        try {
            $export->toDisk($disk)->save('partial/manifest.mpd');
            $this->fail('Expected publication failure.');
        } catch (MediaException $e) {
            $this->assertSame(Progress::PUBLICATION, $e->phase);
            $this->assertCount(2, $e->published);
            $this->assertSame(array_keys($writes), array_column($e->published, 'path'));
            $this->assertSame('partial/low/segment-00000002.m4s', $e->destination['path']);
            Event::assertDispatched(Events\ExportFailed::class);
            $this->assertSame([], glob($this->root.'/*'));
        }
    }

    #[Test]
    public function wrong_stream_mapping_fails_before_encoding(): void
    {
        $export = (new PendingDashExport($this->source(), 'local', 1))->adaptationSet('v', 'video')
            ->representation('wrong', 'v', fn ($r) => $r->map('0:a:0'));
        try {
            $export->save('bad/manifest.mpd');
            $this->fail('Expected mapping failure.');
        } catch (MediaException $e) {
            $this->assertSame('mapping', $e->category);
            $this->assertSame([], Storage::disk('local')->allFiles('bad'));
        }
    }

    public static function streamTypes(): array
    {
        return [['video'], ['audio']];
    }

    #[Test, DataProvider('streamTypes')]
    public function single_type_finite_clipped_dash_and_shared_hls_decode(string $type): void
    {
        $export = (new PendingDashExport($this->source(), 'local', 0.7))->adaptationSet('only', $type)
            ->representation('stream', 'only', fn ($r) => $r->map($type === 'video' ? '0:v:0' : '0:a:0'))
            ->clip(0.3, 1.2)->sharedHls();
        $result = $export->save('single/manifest.mpd');
        $this->decode(Storage::disk('local')->path($result->manifest()->path), ['-map', '0']);
        $this->decode(Storage::disk('local')->path($result->hlsPlaylist()->path), ['-map', '0']);
        $this->assertCount(2, $result->files()->where('role', 'dash-segment'));
    }

    #[Test]
    public function corrupt_encoder_inventory_fails_validation_before_any_publication(): void
    {
        $source = $this->source();
        // The encoder succeeds, then a stray file appears beside its segments.
        $wrapper = dirname($this->root).'/ffmpeg-plants-unowned-segment';
        file_put_contents($wrapper, "#!/bin/sh\n".escapeshellarg(config('laravel-ffmpeg.ffmpeg.binaries')).' "$@" || exit $?'."\n"
            .'for last; do :; done'."\n".'case "$last" in */index.mpd) printf not-owned > "$(dirname "$last")/unowned.m4s";; esac'."\n");
        chmod($wrapper, 0700);
        config(['laravel-ffmpeg.ffmpeg.binaries' => $wrapper]);
        $export = (new PendingDashExport($source, 'local', 0.7))->adaptationSet('video', 'video')->representation('stream', 'video', fn ($r) => $r->map('0:v:0'));
        try {
            $export->save('corrupt/manifest.mpd');
            $this->fail('Expected inventory rejection.');
        } catch (MediaException $exception) {
            $this->assertSame(Progress::VALIDATION, $exception->phase);
            $this->assertSame('artifact', $exception->category);
            $this->assertSame([], Storage::disk('local')->allFiles('corrupt'));
            Event::assertDispatched(Events\ExportFailed::class);
            $this->assertSame([], glob($this->root.'/*'));
        }
    }

    public static function invalidConfigurations(): array
    {
        return [['muxer'], ['copy'], ['clip'], ['path'], ['missing-set'], ['video-gop'], ['video-params']];
    }

    #[Test, DataProvider('invalidConfigurations')]
    public function invalid_configuration_is_rejected_without_io(string $case): void
    {
        Process::fake();
        $export = $this->export(FFMpeg::disk('absent')->open('unread.mp4'));
        $this->expectException(InvalidArgumentException::class);
        match ($case) {
            'muxer' => $export->outputOptions(['-f', 'mp4'])->inspect(),
            'copy' => $export->video('copy')->inspect(),
            'clip' => $export->representation('different', 'picture', fn ($r) => $r->map('0:v:0')->clip(1, 1))->inspect(),
            'path' => $export->representation('../escape', 'picture', fn () => null),
            'missing-set' => $export->representation('unknown', 'undefined', fn () => null),
            'video-gop' => $export->videoOptions(['-g', '120'])->inspect(),
            'video-params' => $export->videoOptions(['-x264-params', 'open-gop=1'])->inspect(),
        };
    }

    public static function invalidInventories(): array
    {
        return array_map(fn ($case) => [$case], ['traversal', 'external', 'entity', 'base', 'missing', 'extra', 'empty', 'symlink',
            'repeat', 'unbounded', 'gap', 'dynamic', 'oversize', 'malformed']);
    }

    #[Test, DataProvider('invalidInventories')]
    public function unowned_or_unsafe_manifest_inventory_is_rejected(string $case): void
    {
        $xml = '<?xml version="1.0"?><MPD xmlns="'.DashManifest::NS.'" type="static" mediaPresentationDuration="PT1S">'
            .'<Period><AdaptationSet contentType="video"><Representation id="0" mimeType="video/mp4" codecs="avc1.64000c" bandwidth="100000">'
            .'<SegmentTemplate timescale="1000" initialization="init.mp4" media="segment-$Number%08d$.m4s" startNumber="1">'
            .'<SegmentTimeline><S t="0" d="1000"/></SegmentTimeline></SegmentTemplate></Representation></AdaptationSet></Period></MPD>';
        $directory = $this->root.'/inventory';
        mkdir($directory);
        file_put_contents($directory.'/init.mp4', 'init');
        file_put_contents($directory.'/segment-00000001.m4s', 'media');
        file_put_contents($directory.'/index.mpd', $xml);
        $this->assertCount(1, DashManifest::read($directory.'/index.mpd', 'video')['durations']);
        $xml = match ($case) {
            'traversal' => str_replace('init.mp4', '../init.mp4', $xml),
            'external' => str_replace('init.mp4', 'https://example.org/init.mp4', $xml),
            'entity' => str_replace('<MPD ', '<!DOCTYPE MPD [<!ENTITY x SYSTEM "file:///etc/passwd">]><MPD ', $xml),
            'base' => str_replace('<Period>', '<BaseURL>https://example.org/</BaseURL><Period>', $xml),
            'repeat' => str_replace('d="1000"', 'd="1000" r="100000"', $xml),
            'unbounded' => str_replace('d="1000"', 'd="1000" r="-1"', $xml),
            'gap' => str_replace('t="0"', 't="1000"', $xml),
            'dynamic' => str_replace('type="static"', 'type="dynamic"', $xml),
            // One byte over the limit: a longer file is truncated on read, and one long whitespace run trips libxml's own limit.
            'oversize' => str_pad(str_replace('><', '>'.str_repeat(' ', intdiv(16777217 - strlen($xml), substr_count($xml, '><'))).'<', $xml), 16777217),
            'malformed' => '<MPD>',
            default => $xml,
        };
        file_put_contents($directory.'/index.mpd', $xml);
        match ($case) {
            'missing' => unlink($directory.'/segment-00000001.m4s'),
            'extra' => file_put_contents($directory.'/unowned.m4s', 'extra'),
            'empty' => file_put_contents($directory.'/init.mp4', ''),
            'symlink' => (function () use ($directory) {
                unlink($directory.'/init.mp4');
                symlink($directory.'/segment-00000001.m4s', $directory.'/init.mp4');
            })(),
            default => null,
        };
        $this->expectException(MediaException::class);
        DashManifest::read($directory.'/index.mpd', 'video');
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
use ProtoneMedia\LaravelFFMpeg\PendingHlsMerge;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Support\Hls;

class NativeHlsSelectionTest extends TestCase
{
    private string $root;

    private array $inventory;

    private bool $allowed = true;

    protected function getPackageProviders($app): array
    {
        return [\ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/native-hls-selection-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/work', 0700);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'laravel-ffmpeg.temporary_files_root' => $this->root.'/work', 'laravel-ffmpeg.ffmpeg.threads' => 1,
            'filesystems.disks.selection' => ['driver' => 'local', 'root' => $this->root.'/storage'],
            'filesystems.disks.selection-keys' => ['driver' => 'local', 'root' => $this->root.'/keys']]);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function binary(): string
    {
        if (! ($probe = getenv('FFMPEG_9_FFPROBE'))) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for HLS selection acceptance.');
        }
        config(['laravel-ffmpeg.ffprobe.binaries' => $probe, 'laravel-ffmpeg.ffmpeg.binaries' => dirname($probe).'/ffmpeg']);

        return dirname($probe).'/ffmpeg';
    }

    private function source()
    {
        $binary = $this->binary();
        $disk = Storage::disk('selection');
        if (! $disk->exists('source.mkv')) {
            $disk->put('captions.srt', "1\n00:00:00,100 --> 00:00:01,000\nHello selection\n");
            $result = Process::run([$binary, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=160x96:rate=20:duration=2.4',
                '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=2.4', '-i', $disk->path('captions.srt'),
                '-map', '0:v', '-map', '1:a', '-map', '2:s', '-c:v', 'libx264', '-preset', 'ultrafast',
                '-c:a', 'aac', '-c:s', 'srt', '-threads', '1', $disk->path('source.mkv')]);
            $this->assertTrue($result->successful(), $result->errorOutput());
        }

        return FFMpeg::disk('selection')->open('source.mkv');
    }

    private function export(string $directory, bool $encrypted = false, bool $fragmented = false)
    {
        $export = $this->source()->hls(segmentDuration: 0.7)->video(bitrate: '180k')->audio(bitrate: '48k')
            ->variant('small', function ($v) use ($encrypted) {
                $v->resize(width: 80)->audioGroup('sound');
                if (! $encrypted) {
                    $v->subtitlesGroup('captions');
                }
            })
            ->variant('large', fn ($v) => $v->audioGroup('sound'))
            ->variant('premium', fn ($v) => $v->audioGroup('exclusive'))
            ->audioRendition('sound', 'English, shared', fn ($v) => $v->map('0:a:0'), language: 'en', default: true)
            ->audioRendition('exclusive', 'Exclusive SUBTITLES=', fn ($v) => $v->map('0:a:0'), default: true);
        if ($encrypted) {
            $export->encrypt('selection-keys', fn ($id) => 'https://keys.invalid/'.$id, rotateEvery: 1);
        } else {
            $export->subtitleRendition('captions', 'English', fn ($v) => $v->map('0:s:0'), default: true);
        }
        if ($fragmented) {
            $export->fmp4();
        }

        return $export->save($directory.'/master.m3u8');
    }

    private function decode(HlsPresentation $presentation): void
    {
        $disk = Storage::disk('selection');
        $prefix = 'responses/'.bin2hex(random_bytes(5)).'/';
        foreach ($presentation->toArray()['files'] as $file) {
            if (! in_array($file['role'], ['hls-master', 'hls-playlist'], true)) {
                continue;
            }
            $id = HlsPresentation::id($file['disk'], $file['path']);
            $text = $presentation->render($file, function ($id) use ($presentation, $disk, $prefix) {
                $target = $presentation->file($id);

                return in_array($target['role'], ['hls-master', 'hls-playlist'], true)
                    ? $disk->path($prefix.$id.'.m3u8') : Storage::disk($target['disk'])->path($target['path']);
            });
            $disk->put($prefix.$id.'.m3u8', $text);
        }
        $result = Process::run([$this->binary(), '-v', 'error', '-xerror', '-allowed_extensions', 'ALL',
            '-protocol_whitelist', 'file,crypto,data', '-i', $disk->path($prefix.$presentation->toArray()['playlist'].'.m3u8'),
            '-map', '0:v:0', '-map', '0:a:0', '-f', 'null', '-']);
        $this->assertTrue($result->successful(), $result->errorOutput());
    }

    private function register(HlsPresentation $presentation): void
    {
        $this->inventory = $presentation->toArray();
        Hls::define('selection', resolve: fn () => HlsPresentation::fromArray($this->inventory), authorize: fn () => $this->allowed);
        Hls::routes('/selection/{asset}', name: 'selection.hls', playback: 'selection');
        $this->actingAs(new GenericUser(['id' => 42]));
    }

    private function url(?string $id = null): string
    {
        return Hls::url('selection.hls', array_filter(['asset' => 'lesson', 'hlsResource' => $id]));
    }

    public static function packaging(): array
    {
        return [[true, false], [false, true]];
    }

    #[Test]
    #[DataProvider('packaging')]
    public function selected_variants_keep_shared_groups_and_only_their_keys_and_initializations(bool $encrypted, bool $fragmented): void
    {
        $result = $this->export('original', $encrypted, $fragmented);
        $selected = HlsPresentation::fromArray(json_decode(json_encode($result->playbackForVariants(['small'])->toArray()), true));
        $paths = array_column($selected->toArray()['files'], 'path');
        $this->assertContains('original/small/index.m3u8', $paths);
        $this->assertNotContains('original/large/index.m3u8', $paths);
        $this->assertNotContains('original/premium/index.m3u8', $paths);
        $text = $selected->render($selected->file(), fn ($id) => 'owned/'.$id);
        $this->assertSame(1, substr_count($text, '#EXT-X-STREAM-INF:'));
        $this->assertStringContainsString('English, shared', $text);
        $this->assertStringNotContainsString('Exclusive', $text);
        $this->assertSame($encrypted ? 1 : 2, substr_count($text, '#EXT-X-MEDIA:'));
        $this->assertSame($fragmented ? 2 : 0, count(array_filter($selected->toArray()['files'], fn ($f) => $f['role'] === 'hls-init')));
        if ($encrypted) {
            $this->assertNotEmpty(array_filter($selected->toArray()['files'], fn ($f) => $f['role'] === 'hls-key'));
            $this->assertLessThan(count($result->playback()->toArray()['files']), count($selected->toArray()['files']));
        }
        $this->decode($selected);
        $this->assertSame('original/large/index.m3u8', $result->variant('large')->playlist()->path);
        $this->assertSame('hls-playlist', $result->variant('large')->playback()->file()['role']);
        $this->register($result->playback());
        $masterUrl = $this->url();
        $old = [];
        foreach ($result->playback()->toArray()['files'] as $file) {
            if (! in_array($file['path'], $paths, true)) {
                $old[] = $this->url(HlsPresentation::id($file['disk'], $file['path']));
            }
        }
        $this->get($masterUrl)->assertOk();
        $this->inventory = $selected->toArray();
        $this->assertSame(1, substr_count($this->get($masterUrl)->assertOk()->getContent(), '#EXT-X-STREAM-INF:'));
        foreach ($old as $url) {
            $this->get($url)->assertNotFound();
            $this->head($url)->assertNotFound();
        }
        foreach ($selected->toArray()['files'] as $file) {
            $this->get($this->url(HlsPresentation::id($file['disk'], $file['path'])))->assertOk();
        }
        $this->allowed = false;
        $this->get($masterUrl)->assertForbidden();
        $this->get($this->url($selected->toArray()['playlist']))->assertForbidden();
        $this->assertSame([], glob($this->root.'/work/*'));
    }

    #[Test]
    #[DataProvider('packaging')]
    public function completed_exports_compose_with_namespaced_groups_and_unchanged_old_artifacts(bool $encrypted, bool $fragmented): void
    {
        $first = $this->export('first', $encrypted, $fragmented);
        $second = $this->export('second', $encrypted, $fragmented);
        $disk = Storage::disk('selection');
        $before = [];
        foreach (['selection', 'selection-keys'] as $name) {
            foreach (Storage::disk($name)->allFiles() as $path) {
                $before[$name][$path] = hash('sha256', Storage::disk($name)->get($path));
            }
        }
        $merged = FFMpeg::mergeHls(['one' => $first->playback()->toArray(), 'two' => $second->playback()])
            ->toDisk('selection')->save('catalog/master.m3u8');
        $this->assertMatchesRegularExpression('~^catalog/master\.[a-f0-9]{32}\.m3u8$~', $merged->playlist()->path);
        $this->assertFalse($disk->exists('catalog/master.m3u8'));
        $master = $disk->get($merged->playlist()->path);
        $this->assertSame(6, substr_count($master, '#EXT-X-STREAM-INF:'));
        $this->assertStringContainsString('GROUP-ID="p0-sound"', $master);
        $this->assertStringContainsString('AUDIO="p1-sound"', $master);
        $this->assertStringContainsString('GROUP-ID="p1-exclusive",NAME="Exclusive SUBTITLES=",DEFAULT=YES', $master);
        $this->assertCount(1, $merged->files()->filter(fn ($f) => $f->owned));
        foreach ($before as $name => $paths) {
            foreach ($paths as $path => $hash) {
                $this->assertSame($hash, hash('sha256', Storage::disk($name)->get($path)));
            }
        }
        $presentation = HlsPresentation::fromArray($merged->playback()->toArray())->playbackForVariants(['two-small', 'one-large']);
        $this->assertSame(2, substr_count($presentation->render($presentation->file(), fn ($id) => 'owned/'.$id), '#EXT-X-STREAM-INF:'));
        $this->decode($presentation);
        $this->decode($merged->playbackForVariants(['two-small']));
        $this->decode($merged->playbackForVariants(['one-large']));
        $this->decode($merged->playbackForVariants(['two-premium']));
        $this->decode($first->playbackForVariants(['small']));
        $this->decode($second->playbackForVariants(['small']));
    }

    #[Test]
    public function failed_master_publication_preserves_completed_inputs(): void
    {
        $first = $this->export('first');
        $second = $this->export('second');
        $disk = Storage::disk('selection');
        $before = [];
        foreach ($disk->allFiles() as $path) {
            $before[$path] = hash('sha256', $disk->get($path));
        }
        $adapter = new \League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter($disk->getAdapter());
        Storage::set('selection', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter));
        try {
            FFMpeg::mergeHls(['one' => $first, 'two' => $second])->save('catalog/master.m3u8');
            $this->fail('Read-only storage accepted a composed master.');
        } catch (\ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException $exception) {
            $this->assertSame(['publication', 'transfer'], [$exception->phase, $exception->category]);
            $this->assertSame('selection', $exception->destination['disk']);
            $this->assertMatchesRegularExpression('~^catalog/master\.[a-f0-9]{32}\.m3u8$~', $exception->destination['path']);
            $this->assertSame([], $exception->published);
        }
        $this->assertSame(array_keys($before), $disk->allFiles());
        foreach ($before as $path => $hash) {
            $this->assertSame($hash, hash('sha256', $disk->get($path)));
        }
    }

    #[Test]
    public function empty_unknown_duplicate_and_reexpanded_selections_are_rejected(): void
    {
        $presentation = $this->export('original')->playback();
        foreach ([[], ['small', 'small'], ['unknown'], [123]] as $names) {
            try {
                $presentation->playbackForVariants($names);
                $this->fail('Invalid selection was accepted.');
            } catch (InvalidArgumentException|\Illuminate\Support\ItemNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $this->expectException(\Illuminate\Support\ItemNotFoundException::class);
        $presentation->playbackForVariants(['small'])->playbackForVariants(['large']);
    }

    #[Test]
    public function invalid_compositions_fail_without_a_master_write(): void
    {
        $first = $this->export('first')->playback()->toArray();
        $second = $this->export('second')->playback()->toArray();
        $badClock = $second;
        foreach ($badClock['provenance'] as &$metadata) {
            $metadata['start'] = 50;
        }
        unset($metadata);
        $noProvenance = $second;
        $noProvenance['provenance'] = [];
        $badPath = $second;
        $badPath['files'][0]['path'] = '../escape';
        $disk = Storage::disk('selection');
        $before = $disk->allFiles();
        foreach ([[$first, $first], [$first, $badClock], [$first, $noProvenance], [$first, $badPath], [$first, $second]] as $inputs) {
            try {
                (new PendingHlsMerge($inputs))->save('catalog/master.m3u8');
                $this->fail('Invalid composition was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            $this->assertSame($before, $disk->allFiles());
        }
        try {
            (new PendingHlsMerge(['one' => $first, 'two' => $second]))->save('first/small/segment-00000000.ts/master.m3u8');
            $this->fail('An artifact-prefix destination was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('prefix', $exception->getMessage());
        }
        $this->assertSame($before, $disk->allFiles());
        // A master that references a file outside its inventory is invalid input, not an HTTP 404.
        $master = collect($first['files'])->firstWhere('role', 'hls-master')['path'];
        $original = $disk->get($master);
        $disk->put($master, rtrim($original, "\n")."\n#EXT-X-STREAM-INF:BANDWIDTH=1\nmissing/index.m3u8\n");
        try {
            (new PendingHlsMerge(['one' => $first, 'two' => $second]))->save('catalog/master.m3u8');
            $this->fail('A master with an unowned reference was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertInstanceOf(\Illuminate\Support\ItemNotFoundException::class, $exception->getPrevious());
        }
        $disk->put($master, $original);
        $this->assertSame($before, $disk->allFiles());
        try {
            HlsPresentation::fromArray($first)->file('unknown');
            $this->fail('An unknown resource was returned.');
        } catch (\Illuminate\Support\ItemNotFoundException) {
            $this->addToAssertionCount(1);
        }
        $disk->delete('second/small/segment-00000000.ts');
        $this->expectException(InvalidArgumentException::class);
        (new PendingHlsMerge(['one' => $first, 'two' => $second]))->save('catalog/master.m3u8');
    }

    #[Test]
    public function real_incompatible_segment_grids_clips_and_packaging_are_rejected(): void
    {
        $source = $this->source();
        $first = $source->hls(segmentDuration: 0.7)->variant('first', fn ($v) => $v->resize(width: 80))->save('base/master.m3u8');
        $exports = [
            $source->hls(segmentDuration: 1.1)->variant('second', fn ($v) => $v->resize(width: 80)),
            $source->hls(segmentDuration: 0.7)->clip(0.2, duration: 1.6)->variant('second', fn ($v) => $v->resize(width: 80)),
            $source->hls(segmentDuration: 0.7)->fmp4()->variant('second', fn ($v) => $v->resize(width: 80)),
        ];
        foreach ($exports as $index => $export) {
            $second = $export->save('incompatible-'.$index.'/master.m3u8');
            $before = Storage::disk('selection')->allFiles();
            try {
                (new PendingHlsMerge([$first, $second]))->save('catalog/master.m3u8');
                $this->fail('Incompatible native export was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            $this->assertSame($before, Storage::disk('selection')->allFiles());
        }
    }
}

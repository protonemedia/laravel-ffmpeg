<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Filesystem\Filesystem as LocalFilesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ItemNotFoundException;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter;
use Orchestra\Testbench\Attributes\RequiresEnv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
use ProtoneMedia\LaravelFFMpeg\PendingHlsMerge;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Support\Hls;
use Throwable;

#[RequiresEnv('FFMPEG_9_FFPROBE')]
class HlsSelectionTest extends TestCase
{
    /**
     * The playback inventories of the shared completed exports.
     */
    protected static array $completedInventories = [];

    protected array $inventory;

    protected bool $allowed = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'filesystems.disks.selection' => ['driver' => 'local', 'root' => $this->root.'/storage'],
            'filesystems.disks.selection-keys' => ['driver' => 'local', 'root' => $this->root.'/keys']]);
    }

    protected function source()
    {
        $binary = $this->useRealFFMpeg();
        $disk = Storage::disk('selection');

        if (! $disk->exists('source.mkv')) {
            $disk->put('captions.srt', <<<'SRT'
                1
                00:00:00,100 --> 00:00:01,000
                Hello selection

                SRT);
            $result = Process::run(
                [
                    $binary,
                    '-v',
                    'error',
                    '-f',
                    'lavfi',
                    '-i',
                    'testsrc2=size=160x96:rate=20:duration=2.4',
                    '-f',
                    'lavfi',
                    '-i',
                    'sine=frequency=440:sample_rate=48000:duration=2.4',
                    '-i',
                    $disk->path('captions.srt'),
                    '-map',
                    '0:v',
                    '-map',
                    '1:a',
                    '-map',
                    '2:s',
                    '-c:v',
                    'libx264',
                    '-preset',
                    'ultrafast',
                    '-c:a',
                    'aac',
                    '-c:s',
                    'srt',
                    '-threads',
                    '1',
                    $disk->path('source.mkv'),
                ],
            );
            $this->assertProcessSucceeded($result);
        }

        return FFMpeg::disk('selection')->open('source.mkv');
    }

    protected function export(string $directory, bool $encrypted = false, bool $fragmented = false)
    {
        $export = $this->source()
            ->hls(segmentDuration: 0.7)
            ->video(bitrate: '180k')
            ->audio(bitrate: '48k')
            ->variant('small', function ($v) use ($encrypted) {
                $v->resize(width: 80)
                    ->audioGroup('sound');

                if (! $encrypted) {
                    $v->subtitleGroup('captions');
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

    /**
     * Get the playback inventories of the plain "first" and "second" exports.
     */
    protected function completedExports(): array
    {
        // Many tests only need two completed exports to reject or compose, so we
        // encode them once for the class and copy the files into the private
        // storage of each test. The inventories only hold disk-relative paths.
        $this->useRealFFMpeg();

        if (static::$completedInventories === []) {
            static::$completedInventories = [
                $this->export('first')->playback()->toArray(),
                $this->export('second')->playback()->toArray(),
            ];

            (new LocalFilesystem)->copyDirectory($this->root.'/storage', $this->fixturePath('completed'));
        } else {
            (new LocalFilesystem)->copyDirectory($this->fixturePath('completed'), $this->root.'/storage');
        }

        return static::$completedInventories;
    }

    /**
     * Forget the shared completed exports after the last test of the class.
     */
    public static function tearDownAfterClass(): void
    {
        static::$completedInventories = [];

        parent::tearDownAfterClass();
    }

    protected function decode(HlsPresentation $presentation): void
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

        $result = Process::run([$this->useRealFFMpeg(), '-v', 'error', '-xerror', '-allowed_extensions', 'ALL',
            '-protocol_whitelist', 'file,crypto,data', '-i', $disk->path(
                $prefix.$presentation->toArray()['playlist'].'.m3u8',
            ),
            '-map', '0:v:0', '-map', '0:a:0', '-f', 'null', '-']);

        $this->assertProcessSucceeded($result);
    }

    protected function register(HlsPresentation $presentation): void
    {
        $this->inventory = $presentation->toArray();
        Hls::define(
            'selection',
            resolve: fn () => HlsPresentation::fromArray($this->inventory),
            authorize: fn () => $this->allowed,
        );
        Hls::routes('/selection/{asset}', name: 'selection.hls', playback: 'selection');
        $this->actingAs(new GenericUser(['id' => 42]));
    }

    protected function url(?string $id = null): string
    {
        return Hls::url('selection.hls', array_filter(['asset' => 'lesson', 'hlsResource' => $id]));
    }

    public static function packaging(): array
    {
        return [[true, false], [false, true]];
    }

    #[DataProvider('packaging')]
    public function test_selected_variants_keep_shared_groups_and_only_their_keys_and_initializations(
        bool $encrypted,
        bool $fragmented,
    ): void {
        $result = $this->export('original', $encrypted, $fragmented);
        $selected = HlsPresentation::fromArray(
            json_decode(json_encode($result->playbackForVariants(['small'])->toArray()), true),
        );
        $paths = array_column($selected->toArray()['files'], 'path');

        $this->assertContains('original/small/index.m3u8', $paths);
        $this->assertNotContains('original/large/index.m3u8', $paths);
        $this->assertNotContains('original/premium/index.m3u8', $paths);

        $text = $selected->render($selected->file(), fn ($id) => 'owned/'.$id);

        $this->assertSame(1, substr_count($text, '#EXT-X-STREAM-INF:'));
        $this->assertStringContainsString('English, shared', $text);
        $this->assertStringNotContainsString('Exclusive', $text);
        $this->assertSame($encrypted ? 1 : 2, substr_count($text, '#EXT-X-MEDIA:'));
        $this->assertSame(
            $fragmented ? 2 : 0,
            count(array_filter($selected->toArray()['files'], fn ($f) => $f['role'] === 'hls-init')),
        );

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

        $this->assertWorkspacesAreCleanedUp();
    }

    #[DataProvider('packaging')]
    public function test_completed_exports_compose_with_namespaced_groups_and_unchanged_old_artifacts(
        bool $encrypted,
        bool $fragmented,
    ): void {
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
            ->toDisk('selection')
            ->save('catalog/master.m3u8');

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

        $presentation = HlsPresentation::fromArray(
            $merged->playback()->toArray(),
        )->playbackForVariants(['two-small', 'one-large']);

        $this->assertSame(
            2,
            substr_count($presentation->render($presentation->file(), fn ($id) => 'owned/'.$id), '#EXT-X-STREAM-INF:'),
        );

        $this->decode($presentation);
        $this->decode($merged->playbackForVariants(['two-small']));
        $this->decode($merged->playbackForVariants(['one-large']));
        $this->decode($merged->playbackForVariants(['two-premium']));
        $this->decode($first->playbackForVariants(['small']));
        $this->decode($second->playbackForVariants(['small']));
    }

    public function test_failed_master_publication_preserves_completed_inputs(): void
    {
        [$first, $second] = $this->completedExports();
        $disk = Storage::disk('selection');
        $before = [];

        foreach ($disk->allFiles() as $path) {
            $before[$path] = hash('sha256', $disk->get($path));
        }

        $adapter = new ReadOnlyFilesystemAdapter($disk->getAdapter());
        Storage::set('selection', new FilesystemAdapter(new Filesystem($adapter), $adapter));

        $this->assertThrows(function () use ($first, $second) {
            FFMpeg::mergeHls(['one' => $first, 'two' => $second])->save('catalog/master.m3u8');
        }, function (MediaException $exception) {
            $this->assertSame(['publication', 'transfer'], [$exception->phase, $exception->category]);
            $this->assertSame('selection', $exception->destination['disk']);
            $this->assertMatchesRegularExpression(
                '~^catalog/master\.[a-f0-9]{32}\.m3u8$~',
                $exception->destination['path'],
            );
            $this->assertSame([], $exception->published);

            return true;
        });
        $this->assertSame(array_keys($before), $disk->allFiles());

        foreach ($before as $path => $hash) {
            $this->assertSame($hash, hash('sha256', $disk->get($path)));
        }
    }

    public static function invalidSelectionProvider(): array
    {
        return [
            'empty' => [[], InvalidArgumentException::class],
            'duplicate' => [['small', 'small'], InvalidArgumentException::class],
            'unknown' => [['unknown'], ItemNotFoundException::class],
            'not a name' => [[123], ItemNotFoundException::class],
        ];
    }

    #[DataProvider('invalidSelectionProvider')]
    public function test_empty_unknown_and_duplicate_selections_are_rejected(array $names, string $exception): void
    {
        $presentation = HlsPresentation::fromArray($this->completedExports()[0]);

        $this->assertThrows(function () use ($presentation, $names) {
            $presentation->playbackForVariants($names);
        }, function (Throwable $thrown) use ($exception) {
            $this->assertInstanceOf($exception, $thrown);
            $this->assertNotEmpty($thrown->getMessage());

            return true;
        });
    }

    public function test_reexpanded_selections_are_rejected(): void
    {
        $presentation = HlsPresentation::fromArray($this->completedExports()[0]);

        $this->expectException(ItemNotFoundException::class);

        $presentation->playbackForVariants(['small'])
            ->playbackForVariants(['large']);
    }

    public static function invalidCompositionProvider(): array
    {
        return [
            'same presentation twice' => ['same'],
            'shifted clock' => ['clock'],
            'missing provenance' => ['provenance'],
            'traversing path' => ['path'],
            'colliding variant names' => ['unnamed'],
        ];
    }

    #[DataProvider('invalidCompositionProvider')]
    public function test_invalid_compositions_fail_without_a_master_write(string $case): void
    {
        [$first, $second] = $this->completedExports();
        $invalid = $second;
        match ($case) {
            'clock' => $invalid['provenance'] = array_map(
                fn ($metadata) => ['start' => 50] + $metadata,
                $second['provenance'],
            ),
            'provenance' => $invalid['provenance'] = [],
            'path' => $invalid['files'][0]['path'] = '../escape',
            default => null,
        };
        $disk = Storage::disk('selection');
        $before = $disk->allFiles();
        $merge = new PendingHlsMerge([$first, $case === 'same' ? $first : $invalid]);

        $this->assertThrows(fn () => $merge->save('catalog/master.m3u8'), function (
            InvalidArgumentException $exception,
        ) {
            $this->assertNotEmpty($exception->getMessage());

            return true;
        });
        $this->assertSame($before, $disk->allFiles());
    }

    public function test_unsafe_composition_destinations_and_inventories_fail_without_a_master_write(): void
    {
        [$first, $second] = $this->completedExports();
        $disk = Storage::disk('selection');
        $before = $disk->allFiles();

        $this->assertThrows(function () use ($first, $second) {
            (new PendingHlsMerge(['one' => $first, 'two' => $second]))
                ->save('first/small/segment-00000000.ts/master.m3u8');
        }, InvalidArgumentException::class, 'prefix');
        $this->assertSame($before, $disk->allFiles());

        // A master that references a file outside its inventory is invalid input, not an HTTP 404.
        $master = collect($first['files'])->firstWhere('role', 'hls-master')['path'];
        $original = $disk->get($master);
        $disk->put($master, sprintf(<<<'M3U8'
            %s
            #EXT-X-STREAM-INF:BANDWIDTH=1
            missing/index.m3u8

            M3U8, rtrim($original, "\n")));

        $this->assertThrows(function () use ($first, $second) {
            (new PendingHlsMerge(['one' => $first, 'two' => $second]))->save('catalog/master.m3u8');
        }, function (InvalidArgumentException $exception) {
            $this->assertInstanceOf(ItemNotFoundException::class, $exception->getPrevious());

            return true;
        });

        $disk->put($master, $original);

        $this->assertSame($before, $disk->allFiles());
        $this->assertThrows(fn () => HlsPresentation::fromArray($first)->file('unknown'), ItemNotFoundException::class);

        $disk->delete('second/small/segment-00000000.ts');

        $this->expectException(InvalidArgumentException::class);

        (new PendingHlsMerge(['one' => $first, 'two' => $second]))->save('catalog/master.m3u8');
    }

    #[TestWith(['segment grid'])]
    #[TestWith(['clip'])]
    #[TestWith(['packaging'])]
    public function test_real_incompatible_segment_grids_clips_and_packaging_are_rejected(string $difference): void
    {
        $source = $this->source();
        $first = $source->hls(segmentDuration: 0.7)
            ->variant('first', fn ($v) => $v->resize(width: 80))
            ->save('base/master.m3u8');
        $export = match ($difference) {
            'segment grid' => $source->hls(segmentDuration: 1.1),
            'clip' => $source->hls(segmentDuration: 0.7)->clip(0.2, duration: 1.6),
            'packaging' => $source->hls(segmentDuration: 0.7)->fmp4(),
        };
        $second = $export->variant('second', fn ($v) => $v->resize(width: 80))
            ->save('incompatible/master.m3u8');
        $before = Storage::disk('selection')->allFiles();
        $merge = new PendingHlsMerge([$first, $second]);

        $this->assertThrows(fn () => $merge->save('catalog/master.m3u8'), function (
            InvalidArgumentException $exception,
        ) {
            $this->assertNotEmpty($exception->getMessage());

            return true;
        });
        $this->assertSame($before, Storage::disk('selection')->allFiles());
    }
}

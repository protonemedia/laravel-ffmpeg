<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use ArrayObject;
use Aws\CommandInterface;
use Aws\Middleware;
use Aws\S3\S3Client;
use Closure;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\Attributes\RequiresEnv;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\HlsExportResult;
use ProtoneMedia\LaravelFFMpeg\HlsVariant;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use Psr\Http\Message\RequestInterface;

#[RequiresEnv('FFMPEG_S3_ENDPOINT')]
class S3Test extends TestCase
{
    /**
     * The key prefix that holds every object of the running test.
     */
    protected ?string $prefix = null;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->useRealFFMpeg();

        // Every test writes below its own random prefix of the bucket, so runs never
        // see each other's objects and the tear down removes exactly what it wrote.
        $this->prefix = 'laravel-ffmpeg-tests/'.bin2hex(random_bytes(8));

        config(['filesystems.disks.s3' => [
            'driver' => 's3',
            'key' => getenv('FFMPEG_S3_KEY'),
            'secret' => getenv('FFMPEG_S3_SECRET'),
            'region' => getenv('FFMPEG_S3_REGION') ?: 'us-east-1',
            'bucket' => getenv('FFMPEG_S3_BUCKET') ?: 'laravel-ffmpeg',
            'endpoint' => getenv('FFMPEG_S3_ENDPOINT'),
            'use_path_style_endpoint' => filter_var(
                getenv('FFMPEG_S3_USE_PATH_STYLE_ENDPOINT') ?: 'true',
                FILTER_VALIDATE_BOOL,
            ),
            'root' => $this->prefix,
            'throw' => true,
        ]]);

        if (! $this->client()->doesBucketExistV2($this->bucket())) {
            $this->client()->createBucket(['Bucket' => $this->bucket()]);
        }
    }

    /**
     * Clean up the testing environment before the next test.
     */
    protected function tearDown(): void
    {
        if ($this->prefix !== null) {
            Storage::disk('s3')->deleteDirectory('');
        }

        parent::tearDown();
    }

    /**
     * Get the S3 client of the "s3" disk.
     */
    protected function client(): S3Client
    {
        return Storage::disk('s3')->getClient();
    }

    /**
     * Get the bucket of the "s3" disk.
     */
    protected function bucket(): string
    {
        return config('filesystems.disks.s3.bucket');
    }

    /**
     * Get the bucket key of the given disk path.
     */
    protected function key(string $path): string
    {
        return $this->prefix.'/'.$path;
    }

    /**
     * Get the local path of a short video with audio, encoded once for the class.
     */
    protected function source(): string
    {
        $path = $this->fixturePath('source.mp4');

        if (! is_file($path)) {
            $result = Process::run([
                config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error',
                '-f', 'lavfi', '-i', 'testsrc2=size=160x96:rate=20:duration=1.5',
                '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=1.5',
                '-c:v', 'libx264', '-preset', 'ultrafast', '-g', '10', '-pix_fmt', 'yuv420p',
                '-c:a', 'aac', '-threads', '1', $path,
            ]);

            $this->assertProcessSucceeded($result);
        }

        return $path;
    }

    /**
     * Upload the class source video to "uploads/source.mp4" on the "s3" disk.
     */
    protected function uploadSource(): void
    {
        $stream = fopen($this->source(), 'rb');

        Storage::disk('s3')->put('uploads/source.mp4', $stream);

        fclose($stream);
    }

    /**
     * Publish a single-variant HLS presentation of the source to "streams/lesson" on the "s3" disk.
     */
    protected function publishHls(): HlsExportResult
    {
        $this->uploadSource();

        return FFMpeg::disk('s3')
            ->open('uploads/source.mp4')
            ->hls(segmentDuration: 0.5)
            ->variant('main', fn (HlsVariant $variant) => $variant->video('libx264', bitrate: '200k'))
            ->audio('aac', bitrate: '48k')
            ->save('streams/lesson/master.m3u8');
    }

    /**
     * Record the ACL header of every object the S3 client puts, keyed by object key.
     *
     * @return ArrayObject<string, string>
     */
    protected function recordAcls(): ArrayObject
    {
        $acls = new ArrayObject;

        // We read the header from the signed request, which is what the server receives,
        // instead of the command parameters, so a dropped option cannot go unnoticed.
        $this->client()
            ->getHandlerList()
            ->appendSign(Middleware::tap(function (CommandInterface $command, ?RequestInterface $request) use ($acls) {
                if ($command->getName() === 'PutObject') {
                    $acls[$command['Key']] = $request->getHeaderLine('x-amz-acl');
                }
            }), 'record-acls');

        return $acls;
    }

    /**
     * Get a progress callback that records the permissions of every staged input, keyed by name.
     */
    protected function recordStagedInputs(array &$staged): Closure
    {
        return function () use (&$staged) {
            foreach (glob($this->root.'/workspaces/*/input-*') as $path) {
                $staged[basename($path)] = fileperms($path) & 0777;
            }
        };
    }

    /**
     * Get the width and height of the first video stream of the given local file.
     *
     * @return array{0: int, 1: int}
     */
    protected function dimensions(string $path): array
    {
        $result = Process::run([
            config('laravel-ffmpeg.ffprobe.binaries'), '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height', '-of', 'json', $path,
        ]);

        $this->assertProcessSucceeded($result);

        $stream = json_decode($result->output(), true)['streams'][0];

        return [$stream['width'], $stream['height']];
    }

    /**
     * Assert that real FFmpeg decodes every stream of the given local file without errors.
     */
    protected function assertDecodes(string $path): void
    {
        $this->assertProcessSucceeded(Process::run([
            config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-xerror', '-i', $path,
            '-map', '0', '-f', 'null', '-',
        ]));
    }

    public function test_media_on_s3_is_staged_in_a_private_workspace_and_exported_back_to_s3(): void
    {
        $this->uploadSource();
        $staged = [];

        $result = FFMpeg::disk('s3')
            ->open('uploads/source.mp4')
            ->export()
            ->video('libx264', preset: 'ultrafast')
            ->audio('aac')
            ->resize(width: 80)
            ->onProgress($this->recordStagedInputs($staged))
            ->save('exports/small.mp4');

        $this->assertSame(['input-0.mp4' => 0600], $staged);
        $this->assertSame('s3', $result->file()->disk);
        $this->assertSame('exports/small.mp4', $result->file()->path);
        $this->assertSame(Storage::disk('s3')->size('exports/small.mp4'), $result->file()->size);
        $this->assertWorkspacesAreCleanedUp();

        $metadata = FFMpeg::disk('s3')
            ->open('exports/small.mp4')
            ->probe();
        $video = $metadata->video();

        $this->assertSame([80, 48], [$video->width(), $video->height()]);
        $this->assertTrue($metadata->hasAudio());
        $this->assertEqualsWithDelta(1.5, $metadata->duration(), 0.1);
        $this->assertWorkspacesAreCleanedUp();
    }

    #[TestWith(['private', 'private'])]
    #[TestWith(['public', 'public-read'])]
    public function test_exports_send_their_visibility_and_options_to_s3(string $visibility, string $acl): void
    {
        $this->uploadSource();
        $acls = $this->recordAcls();

        FFMpeg::disk('s3')
            ->open('uploads/source.mp4')
            ->export()
            ->video('copy')
            ->audio('copy')
            ->toDisk('s3', ['CacheControl' => 'max-age=60'])
            ->visibility($visibility)
            ->save('exports/remux.mkv');

        $object = $this->client()->headObject([
            'Bucket' => $this->bucket(),
            'Key' => $this->key('exports/remux.mkv'),
        ]);

        // MinIO accepts canned ACLs but ignores them, and always reports the owner's
        // full control, so the header the server received is the visibility we check.
        $this->assertSame([$this->key('exports/remux.mkv') => $acl], $acls->getArrayCopy());
        $this->assertSame('max-age=60', $object['CacheControl']);
        $this->assertSame('video/x-matroska', $object['ContentType']);
    }

    public function test_hls_exports_publish_every_playlist_and_segment_to_s3(): void
    {
        $this->uploadSource();
        $acls = $this->recordAcls();
        $playback = Storage::build(['driver' => 'local', 'root' => $this->root.'/playback']);

        $result = FFMpeg::disk('s3')
            ->open('uploads/source.mp4')
            ->hls(segmentDuration: 0.5)
            ->variant('low', fn (HlsVariant $variant) => $variant->resize(width: 80)
                ->video('libx264', bitrate: '150k'))
            ->variant('high', fn (HlsVariant $variant) => $variant->video('libx264', bitrate: '300k'))
            ->audio('aac', bitrate: '48k')
            ->visibility('public')
            ->save('streams/lesson/master.m3u8');

        $paths = $result->files()
            ->pluck('path')
            ->sort()
            ->values()
            ->all();
        $stored = collect(Storage::disk('s3')->allFiles('streams'))
            ->sort()
            ->values()
            ->all();
        $roles = $result->files()
            ->countBy('role')
            ->sortKeys()
            ->all();
        $sent = $acls->getArrayCopy();

        ksort($sent);

        $this->assertSame($paths, $stored);
        $this->assertSame(array_fill_keys(array_map($this->key(...), $paths), 'public-read'), $sent);
        $this->assertSame(['hls-master' => 1, 'hls-playlist' => 2, 'hls-segment' => 6], $roles);
        $this->assertWorkspacesAreCleanedUp();

        foreach ($result->files() as $file) {
            $object = $this->client()->headObject([
                'Bucket' => $this->bucket(),
                'Key' => $this->key($file->path),
            ]);

            $this->assertSame($file->size, $object['ContentLength']);
            $this->assertSame(
                str_ends_with($file->path, '.m3u8') ? 'application/vnd.apple.mpegurl' : 'video/mp2t',
                $object['ContentType'],
            );

            $playback->put($file->path, Storage::disk('s3')->get($file->path));
        }

        $this->assertDecodes($playback->path('streams/lesson/master.m3u8'));

        foreach (['low' => [80, 48], 'high' => [160, 96]] as $name => $dimensions) {
            $playlist = $playback->path($result->variant($name)->playlist()->path);

            $this->assertDecodes($playlist);
            $this->assertSame($dimensions, $this->dimensions($playlist));
        }
    }

    #[TestWith(['streams/master.m3u8'])]
    #[TestWith(['uploads/disguised.mp4'])]
    public function test_playlists_on_s3_cannot_be_opened_as_media(string $path): void
    {
        Storage::disk('s3')->put($path, "#EXTM3U\n#EXT-X-ENDLIST\n");

        $this->assertThrows(
            fn () => FFMpeg::disk('s3')
                ->open($path)
                ->probe(),
            fn (MediaException $exception) => $exception->phase === 'staging' && $exception->category === 'manifest',
        );

        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_s3_inputs_combine_with_local_inputs_in_one_export(): void
    {
        $this->uploadSource();
        $tone = $this->root.'/tone.m4a';
        $staged = [];

        $this->assertProcessSucceeded(Process::run([
            config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error',
            '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=22050:duration=1.5',
            '-c:a', 'aac', '-ac', '1', $tone,
        ]));

        $export = FFMpeg::disk('s3')
            ->open('uploads/source.mp4')
            ->export();
        $input = $export->addInput(FFMpeg::open(new File($tone)));

        // Adding an input resets the default destination to filesystems.default,
        // so a multi-input export names its disk explicitly.
        $export->map('0:v:0')
            ->map($input.':a:0')
            ->video('libx264', preset: 'ultrafast')
            ->audio('copy')
            ->metadataFrom(0)
            ->chaptersFrom(null)
            ->onProgress($this->recordStagedInputs($staged))
            ->toDisk('s3')
            ->save('exports/mixed.mp4');

        $metadata = FFMpeg::disk('s3')
            ->open('exports/mixed.mp4')
            ->probe();
        $video = $metadata->video();

        $this->assertSame(['input-0.mp4' => 0600], $staged);
        $this->assertSame([160, 96], [$video->width(), $video->height()]);
        $this->assertSame(22050, $metadata->audio()->sampleRate());
        $this->assertFileExists($tone);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_generated_files_on_s3_are_deleted_through_the_inventory(): void
    {
        $result = $this->publishHls();

        $this->assertCount($result->files()->count(), Storage::disk('s3')->allFiles('streams'));

        foreach ($result->files() as $file) {
            $this->assertTrue($file->owned);
            $this->assertTrue($file->delete());
        }

        $this->assertSame([], Storage::disk('s3')->allFiles('streams'));
        $this->assertTrue(Storage::disk('s3')->exists('uploads/source.mp4'));
    }

    public function test_playlists_on_s3_delete_every_approved_file(): void
    {
        $result = $this->publishHls();
        $approved = [];

        FFMpeg::disk('s3')
            ->playlist('streams/lesson/master.m3u8')
            ->deleteAllFiles(function (string $path) use (&$approved): bool {
                $approved[] = $path;

                return true;
            });

        $published = $result->files()
            ->pluck('path')
            ->all();

        $this->assertEqualsCanonicalizing($published, $approved);
        $this->assertSame([], Storage::disk('s3')->allFiles('streams'));
        $this->assertTrue(Storage::disk('s3')->exists('uploads/source.mp4'));
    }

    public function test_filesystem_objects_open_and_publish_s3_media(): void
    {
        $this->uploadSource();

        $result = FFMpeg::disk(Storage::disk('s3'))
            ->open('uploads/source.mp4')
            ->export()
            ->video('libx264', preset: 'ultrafast')
            ->audio('aac')
            ->resize(width: 80)
            ->save('exports/object.mp4');
        $stream = $result->file()->readStream();

        $this->assertNull($result->file()->disk);
        $this->assertSame(Storage::disk('s3')->get('exports/object.mp4'), stream_get_contents($stream));

        fclose($stream);

        $metadata = FFMpeg::disk(Storage::disk('s3'))
            ->open('exports/object.mp4')
            ->probe();
        $video = $metadata->video();

        $this->assertSame([80, 48], [$video->width(), $video->height()]);
        $this->assertWorkspacesAreCleanedUp();
    }
}

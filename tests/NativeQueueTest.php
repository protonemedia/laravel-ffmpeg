<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\File;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ProtoneMedia\LaravelFFMpeg\Events\QueuedExportCompleted;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use RuntimeException;

class NativeQueueTest extends TestCase
{
    private string $root;

    private array $completed = [];

    private array $errors = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! ($probe = getenv('FFMPEG_9_FFPROBE'))) {
            $this->markTestSkipped('Set FFMPEG_9_FFPROBE for queue acceptance.');
        }
        $this->root = sys_get_temp_dir().'/native-queue-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/work', 0700);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'media', 'retry_after' => 4000],
            'queue.batching' => ['database' => 'sqlite', 'table' => 'job_batches'],
            'laravel-ffmpeg.ffmpeg.binaries' => dirname($probe).'/ffmpeg', 'laravel-ffmpeg.ffprobe.binaries' => $probe,
            'laravel-ffmpeg.temporary_files_root' => $this->root.'/work',
            'filesystems.disks.queued' => ['driver' => 'local', 'root' => $this->root.'/storage', 'throw' => true]]);
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
        Event::listen(QueuedExportCompleted::class, function ($event) { $this->completed[] = $event; });
        Event::listen(\Illuminate\Queue\Events\JobExceptionOccurred::class, function ($event) { $this->errors[] = $event->exception; });
        $disk = Storage::disk('queued');
        $disk->makeDirectory('');
        $result = Process::run([dirname($probe).'/ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=96x64:rate=10:duration=1.6',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=1.6',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'aac', '-threads', '1', $disk->path('source.mp4')]);
        $this->assertTrue($result->successful(), $result->errorOutput());
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    private function work(): void
    {
        app('queue.worker')->runNextJob('database', 'media', new WorkerOptions(sleep: 0, rest: 0));
    }

    private function source()
    {
        return FFMpeg::disk('queued')->open('source.mp4');
    }

    #[Test]
    public function fluent_dispatch_serializes_a_snapshot_and_runs_callbacks_middleware_retries_and_chains(): void
    {
        $export = $this->source()->export()->video('libx264', preset: 'ultrafast')->resize(width: 48)
            ->onProgress(static function ($progress) { Storage::disk('queued')->put('progress', $progress->phase); });
        $queued = $export->queued(connection: 'database', queue: 'media', delay: 120)->configureJob(function ($job) {
            $job->tries = 2;
            $job->through([new QueueFailOnce]);
        });
        $export->resize(width: 32);
        $queued->save('converted.mp4')->chain([new QueueFollowUp]);
        $row = DB::table('jobs')->sole();
        $this->assertGreaterThanOrEqual(time() + 118, $row->available_at);
        $this->assertStringNotContainsString('source.mp4', json_decode($row->payload, true)['data']['command']);
        $this->assertFalse(Storage::disk('queued')->exists('converted.mp4'));
        DB::table('jobs')->update(['available_at' => time()]);
        $this->work();
        $this->assertCount(1, $this->errors);
        $this->assertSame('Retry fixture', $this->errors[0]->getMessage());
        $this->assertFalse(Storage::disk('queued')->exists('converted.mp4'));
        $this->assertFalse(Storage::disk('queued')->exists('follow-up'));
        $this->work();
        $this->assertCount(1, $this->errors);
        $this->assertSame(48, FFMpeg::disk('queued')->open('converted.mp4')->probe()->video()->get('width'));
        $this->assertSame('publication', Storage::disk('queued')->get('progress'));
        $this->assertCount(1, $this->completed);
        $this->assertSame($queued->id, $this->completed[0]->exportId);
        $this->assertSame('converted.mp4', $this->completed[0]->files[0]['path']);
        $this->assertNull($this->completed[0]->playback);
        $this->assertFalse(Storage::disk('queued')->exists('follow-up'));
        $this->work();
        $this->assertSame('published', Storage::disk('queued')->get('follow-up'));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame([], glob($this->root.'/work/*'));
    }

    #[Test]
    public function parallel_hls_publishes_only_after_all_conversions_and_then_releases_the_chain(): void
    {
        $queued = $this->source()->hls(segmentDuration: 0.5)->video(bitrate: '100k', preset: 'ultrafast')->audio(bitrate: '48k')->fmp4()
            ->variant('small', fn ($v) => $v->resize(width: 48))
            ->variant('large', fn ($v) => $v->resize(width: 96))
            ->queued(connection: 'database', queue: 'media', delay: 120, parallel: true);
        Bus::chain([$queued->job('catalog/master.m3u8'), new QueueFollowUp])->onConnection('database')->onQueue('media')->dispatch();
        $this->assertGreaterThanOrEqual(time() + 118, DB::table('jobs')->sole()->available_at);
        DB::table('jobs')->update(['available_at' => time()]);
        $this->work();
        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertLessThanOrEqual(time(), DB::table('jobs')->max('available_at'));
        $this->work();
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame([], Storage::disk('queued')->allFiles('catalog'));
        $this->assertCount(0, $this->completed);
        $this->work();
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame([], Storage::disk('queued')->allFiles('catalog'));
        $this->work();
        $this->assertSame([], $this->errors);
        $this->assertCount(1, $this->completed);
        $playback = HlsPresentation::fromArray($this->completed[0]->playback);
        $this->assertSame(['small', 'large'], array_keys($playback->toArray()['variants']));
        $this->assertCount(1, Storage::disk('queued')->allFiles('catalog'));
        foreach ($playback->toArray()['files'] as $file) {
            if ($file['role'] !== 'hls-playlist') {
                continue;
            }
            $decoded = Process::run([config('laravel-ffmpeg.ffmpeg.binaries'), '-v', 'error', '-i', Storage::disk('queued')->path($file['path']), '-f', 'framemd5', '-']);
            $this->assertTrue($decoded->successful(), $decoded->errorOutput());
            $this->assertStringContainsString('#media_type 0: video', $decoded->output());
        }
        $this->assertFalse(Storage::disk('queued')->exists('follow-up'));
        $this->work();
        $this->assertSame('published', Storage::disk('queued')->get('follow-up'));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    #[Test]
    public function failed_parallel_conversions_never_publish_a_master_or_run_the_chain(): void
    {
        $job = $this->source()->hls(segmentDuration: 0.5)->video(bitrate: '100k', preset: 'ultrafast')
            ->variant('small', fn ($v) => $v->resize(width: 48))
            ->variant('broken', fn ($v) => $v->video('not_an_encoder'))
            ->queued(connection: 'database', queue: 'media', parallel: true)->job('failed/master.m3u8');
        Bus::chain([$job, new QueueFollowUp])->onConnection('database')->onQueue('media')->dispatch();
        $this->work();
        $this->work();
        $this->work();
        $this->assertCount(1, $this->errors);
        $this->assertSame([], Storage::disk('queued')->allFiles('failed'));
        $this->assertCount(0, $this->completed);
        $this->assertFalse(Storage::disk('queued')->exists('follow-up'));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertNotNull(DB::table('job_batches')->sole()->cancelled_at);
    }

    #[Test]
    public function native_batches_can_cancel_queued_conversions_before_they_start(): void
    {
        Storage::disk('queued')->put('logo.png', file_get_contents(__DIR__.'/src/logo.png'));
        $hash = hash('sha256', Storage::disk('queued')->get('logo.png'));
        $image = FFMpeg::disk('queued')->open('logo.png')->prepareImage(static function ($path) {
            Storage::disk('queued')->put('prepared', hash_file('sha256', $path));
        });
        Bus::batch([
            $this->source()->frame(0.5)->queued(connection: 'database', queue: 'media')->job('preview.jpg'),
            $this->source()->export()->queued(connection: 'database', queue: 'media')->job('ready.mp4'),
            $image->still(0.6, frameRate: 5)->resize(width: 48)->video('libx264')
                ->queued(connection: 'database', queue: 'media')->job('still.mp4'),
        ])->onConnection('database')->onQueue('media')->dispatch();
        $this->work();
        $this->work();
        $this->work();
        $this->assertSame([96, 64], array_slice(getimagesize(Storage::disk('queued')->path('preview.jpg')), 0, 2));
        $this->assertSame(96, FFMpeg::disk('queued')->open('ready.mp4')->probe()->video()->get('width'));
        $this->assertSame(48, FFMpeg::disk('queued')->open('still.mp4')->probe()->video()->get('width'));
        $this->assertSame($hash, Storage::disk('queued')->get('prepared'));
        $this->assertSame($hash, hash('sha256', Storage::disk('queued')->get('logo.png')));
        $this->assertCount(3, $this->completed);
        $this->completed = [];
        $batch = Bus::batch([
            $this->source()->frame(0.5)->queued(connection: 'database', queue: 'media')->job('frame.jpg'),
            $this->source()->export()->queued(connection: 'database', queue: 'media')->job('copy.mp4'),
        ])->onConnection('database')->onQueue('media')->dispatch();
        $batch->cancel();
        $this->work();
        $this->work();
        $this->assertFalse(Storage::disk('queued')->exists('frame.jpg'));
        $this->assertFalse(Storage::disk('queued')->exists('copy.mp4'));
        $this->assertCount(0, $this->completed);
        $this->assertSame([], $this->errors);
    }

    #[Test]
    public function cancellation_during_encoding_and_before_finalization_prevents_publication(): void
    {
        Bus::batch([
            $this->source()->export()->video('libx264')->onProgress(static function ($progress) {
                if ($progress->isEncoding()) {
                    if ($progress->elapsedSeconds !== null) {
                        Storage::disk('queued')->put('progress-after-cancellation', 'unexpected');
                    }
                    DB::table('job_batches')->update(['cancelled_at' => time()]);
                }
            })->queued(connection: 'database', queue: 'media')->job('cancelled.mp4'),
        ])->onConnection('database')->onQueue('media')->dispatch();
        $this->work();
        $this->assertFalse(Storage::disk('queued')->exists('cancelled.mp4'));
        $this->assertFalse(Storage::disk('queued')->exists('progress-after-cancellation'));
        $this->assertCount(1, $this->errors);
        $this->assertInstanceOf(\ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled::class, $this->errors[0]);

        $queued = $this->source()->hls(segmentDuration: 0.5)->video(bitrate: '100k', preset: 'ultrafast')
            ->variant('small', fn ($v) => $v->resize(width: 48))
            ->variant('large', fn ($v) => $v->resize(width: 96))
            ->queued(connection: 'database', queue: 'media', parallel: true);
        Bus::chain([$queued->job('cancelled/master.m3u8'), new QueueFollowUp])->onConnection('database')->onQueue('media')->dispatch();
        $this->work();
        $this->work();
        $this->work();
        $batch = DB::table('job_batches')->where('name', 'FFmpeg '.$queued->id)->sole();
        Bus::findBatch($batch->id)->cancel();
        $this->work();
        $this->assertSame([], Storage::disk('queued')->allFiles('cancelled'));
        $this->assertFalse(Storage::disk('queued')->exists('follow-up'));
        $this->assertCount(0, $this->completed);
        $this->assertCount(2, $this->errors);
        $this->assertInstanceOf(\ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled::class, $this->errors[1]);
        $this->assertSame([], glob($this->root.'/work/*'));
    }

    #[Test]
    public function parallel_hls_keeps_shared_renditions_together_and_serializes_encryption_callbacks(): void
    {
        $this->source()->hls(segmentDuration: 0.5)->video(bitrate: '100k', preset: 'ultrafast')->audio(bitrate: '48k')
            ->variant('small', fn ($v) => $v->resize(width: 48)->audioGroup('sound'))
            ->variant('large', fn ($v) => $v->resize(width: 96)->audioGroup('sound'))
            ->variant('independent', fn ($v) => $v->resize(width: 64))
            ->audioRendition('sound', 'English', fn ($v) => $v->map('0:a:0'), default: true)
            ->encrypt('queued', static fn ($key) => 'https://keys.invalid/'.$key, rotateEvery: 1)
            ->queued(connection: 'database', queue: 'media', parallel: true)->save('encrypted/master.m3u8');
        $this->work();
        $this->assertSame(2, DB::table('jobs')->count());
        $this->work();
        $this->work();
        $this->work();
        $this->assertSame([], $this->errors);
        $this->assertCount(1, $this->completed);
        $inventory = HlsPresentation::fromArray($this->completed[0]->playback)->toArray();
        $this->assertCount(3, $inventory['variants']);
        $this->assertCount(4, array_filter($inventory['files'], fn ($file) => $file['role'] === 'hls-playlist'));
        $this->assertCount(1, array_filter($inventory['provenance'], fn ($item) => $item['streamType'] === 'audio'));
        $keys = array_filter($inventory['files'], fn ($file) => $file['role'] === 'hls-key');
        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertSame(16, Storage::disk($key['disk'])->size($key['path']));
        }
    }

    #[Test]
    public function parallel_plans_reject_unsupported_configuration_and_keep_one_shared_group_as_one_job(): void
    {
        $make = fn () => $this->source()->hls(segmentDuration: 0.5)->video(bitrate: '100k', preset: 'ultrafast')
            ->variant('small', fn ($v) => $v->resize(width: 48))
            ->variant('large', fn ($v) => $v->resize(width: 96));
        foreach ([
            [$make()->playlistUsing(static fn ($text) => $text), 'playlist callbacks'],
            [$this->source()->hls()->filterComplex('[0:v]split=2[first][second]')
                ->variant('first', fn ($v) => $v->map('[first]'))
                ->variant('second', fn ($v) => $v->map('[second]')), 'complex graphs'],
            [$make()->audioRendition('unused', 'English', fn ($v) => $v->map('0:a:0')), 'referenced'],
        ] as [$export, $message]) {
            try {
                $export->queued(parallel: true)->job('invalid/master.m3u8');
                $this->fail('Unsupported parallel configuration was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
        try {
            Bus::batch([$make()->queued(parallel: true)->job('invalid/master.m3u8')])->dispatch();
            $this->fail('A nested coordinator cannot represent completion of its children.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('not inside another', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('jobs')->count());
        $this->source()->hls(segmentDuration: 0.5)->video(bitrate: '100k', preset: 'ultrafast')
            ->variant('small', fn ($v) => $v->resize(width: 48)->audioGroup('sound'))
            ->variant('large', fn ($v) => $v->resize(width: 96)->audioGroup('sound'))
            ->audioRendition('sound', 'English', fn ($v) => $v->map('0:a:0'), default: true)
            ->queued(connection: 'database', queue: 'media', parallel: true)->save('single/master.m3u8');
        $this->assertSame(1, DB::table('jobs')->count());
        $this->work();
        $this->assertSame([], $this->errors);
        $this->assertCount(1, $this->completed);
        $this->assertTrue(Storage::disk('queued')->exists('single/master.m3u8'));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    #[Test]
    public function queued_plans_reject_request_local_inputs_before_dispatch(): void
    {
        foreach ([FFMpeg::open(new File(Storage::disk('queued')->path('source.mp4'))), FFMpeg::disk(Storage::disk('queued'))->open('source.mp4')] as $source) {
            try {
                $source->export()->queued();
                $this->fail('Request-local inputs must not be queued.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('durable named disks', $exception->getMessage());
            }
        }
        $capture = new \stdClass;
        foreach ([fn ($progress) => null, static function ($progress) use ($capture) {}] as $callback) {
            try {
                $this->source()->export()->onProgress($callback)->queued();
                $this->fail('Nonportable callbacks must not be queued.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('callbacks', $exception->getMessage());
            }
        }
        $this->assertSame(0, DB::table('jobs')->count());
    }
}

class QueueFailOnce
{
    public function handle($job, $next)
    {
        if (! Storage::disk('queued')->exists('attempted')) {
            Storage::disk('queued')->put('attempted', 'yes');
            throw new RuntimeException('Retry fixture');
        }

        return $next($job);
    }
}

class QueueFollowUp implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Storage::disk('queued')->put('follow-up', 'published');
    }
}

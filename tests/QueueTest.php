<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\Attributes\RequiresEnv;
use ProtoneMedia\LaravelFFMpeg\Events\QueuedExportCompleted;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Watermark;
use RuntimeException;

#[RequiresEnv('FFMPEG_9_FFPROBE')]
class QueueTest extends TestCase
{
    protected array $completed = [];

    protected array $errors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $ffmpeg = $this->useRealFFMpeg();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'media', 'retry_after' => 4000],
            'queue.batching' => ['database' => 'sqlite', 'table' => 'job_batches'],
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
        Event::listen(QueuedExportCompleted::class, function ($event) {
            $this->completed[] = $event;
        });
        Event::listen(JobExceptionOccurred::class, function ($event) {
            $this->errors[] = $event->exception;
        });
        $disk = Storage::disk('queued');
        $disk->makeDirectory('');
        $result = Process::run([$ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=96x64:rate=10:duration=1.6',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000:duration=1.6',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'aac', '-threads', '1', $disk->path('source.mp4')]);
        $this->assertProcessSucceeded($result);
    }

    protected function work(): void
    {
        app('queue.worker')->runNextJob('database', 'media', new WorkerOptions(sleep: 0, rest: 0));
    }

    protected function source()
    {
        return FFMpeg::disk('queued')->open('source.mp4');
    }

    public function test_fluent_dispatch_serializes_a_snapshot_and_runs_callbacks_middleware_retries_and_chains(): void
    {
        $export = $this->source()->export()->video('libx264', preset: 'ultrafast')->resize(width: 48)
            ->onProgress(static function ($progress) {
                Storage::disk('queued')->put('progress', $progress->phase);
            });
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
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_a_saved_hls_builder_can_still_be_queued(): void
    {
        $export = $this->source()->hls(segmentDuration: 0.5)->video(bitrate: '100k', preset: 'ultrafast')->audio(bitrate: '48k')
            ->variant('small', fn ($variant) => $variant->resize(width: 48));
        $export->save('direct/master.m3u8');
        $export->queued(connection: 'database', queue: 'media')->save('queued/master.m3u8');
        $this->work();
        $this->assertSame([], $this->errors);
        $this->assertTrue(Storage::disk('queued')->exists('queued/master.m3u8'));
    }

    public function test_parallel_hls_publishes_only_after_all_conversions_and_then_releases_the_chain(): void
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
            $this->assertProcessSucceeded($decoded);
            $this->assertStringContainsString('#media_type 0: video', $decoded->output());
        }
        $this->assertFalse(Storage::disk('queued')->exists('follow-up'));
        $this->work();
        $this->assertSame('published', Storage::disk('queued')->get('follow-up'));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_failed_parallel_conversions_never_publish_a_master_or_run_the_chain(): void
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

    public function test_native_batches_can_cancel_queued_conversions_before_they_start(): void
    {
        $bitmap = imagecreatetruecolor(8, 8);
        imagefill($bitmap, 0, 0, imagecolorallocate($bitmap, 255, 0, 0));
        ob_start();
        imagepng($bitmap);
        Storage::disk('queued')->put('logo.png', ob_get_clean());
        imagedestroy($bitmap);
        $hash = hash('sha256', Storage::disk('queued')->get('logo.png'));
        $logo = FFMpeg::disk('queued')->open('logo.png');
        Bus::batch([
            $this->source()->frame(0.5)->queued(connection: 'database', queue: 'media')->job('preview.jpg'),
            $this->source()->export()->queued(connection: 'database', queue: 'media')->job('ready.mp4'),
            // The callback runs before capture, so an ordinary closure that captures $this queues.
            $this->source()->export()->watermark($logo, fn (Watermark $watermark) => $watermark->grayscale())->video('libx264')
                ->queued(connection: 'database', queue: 'media')->job('watermarked.mp4'),
        ])->onConnection('database')->onQueue('media')->dispatch();
        $this->work();
        $this->work();
        $this->work();
        $this->assertSame([96, 64], array_slice(getimagesize(Storage::disk('queued')->path('preview.jpg')), 0, 2));
        $this->assertSame(96, FFMpeg::disk('queued')->open('ready.mp4')->probe()->video()->get('width'));
        // The recorded edit survives the queue: the red mark in the bottom-right corner is gray.
        $frame = imagecreatefromstring(FFMpeg::disk('queued')->open('watermarked.mp4')->frame(0.5)->get());
        $pixel = imagecolorsforindex($frame, imagecolorat($frame, 92, 60));
        imagedestroy($frame);
        $this->assertLessThan(30, abs($pixel['red'] - $pixel['blue']), json_encode($pixel));
        $this->assertLessThan(150, $pixel['red'], json_encode($pixel));
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

    public function test_cancellation_during_encoding_and_before_finalization_prevents_publication(): void
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
        $this->assertInstanceOf(ExportCancelled::class, $this->errors[0]);

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
        $this->assertInstanceOf(ExportCancelled::class, $this->errors[1]);
        $this->assertWorkspacesAreCleanedUp();
    }

    public function test_parallel_hls_keeps_shared_renditions_together_and_serializes_encryption_callbacks(): void
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

    public function test_parallel_hls_keeps_one_shared_group_as_one_job(): void
    {
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

    public function test_disabling_export_events_preserves_queue_publication_and_chains(): void
    {
        config(['laravel-ffmpeg.enable_events' => false]);
        $this->source()->export()->video('libx264', preset: 'ultrafast')->resize(width: 48)
            ->queued(connection: 'database', queue: 'media')->save('quiet.mp4')->chain([new QueueFollowUp]);
        $this->work();
        $this->work();

        $this->assertSame(48, FFMpeg::disk('queued')->open('quiet.mp4')->probe()->video()->get('width'));
        $this->assertSame('published', Storage::disk('queued')->get('follow-up'));
        $this->assertSame([], $this->completed);
        $this->assertSame([], $this->errors);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_ordinary_queued_hls_preserves_non_vod_types_and_encryption(): void
    {
        foreach (['event', 'live'] as $type) {
            $this->source()->hls(segmentDuration: 0.5)->playlistType($type)->video(bitrate: '100k', preset: 'ultrafast')
                ->variant('main', fn ($variant) => $variant->resize(width: 48))
                ->encrypt('queued', static fn ($key) => 'https://keys.invalid/'.$key, rotateEvery: 1)
                ->queued(connection: 'database', queue: 'media')->save($type.'/master.m3u8');
            $this->work();
            $playlist = Storage::disk('queued')->get($type.'/main/index.m3u8');
            if ($type === 'event') {
                $this->assertStringContainsString('#EXT-X-PLAYLIST-TYPE:EVENT', $playlist);
            } else {
                $this->assertStringNotContainsString('#EXT-X-PLAYLIST-TYPE:', $playlist);
            }
            $this->assertStringContainsString('#EXT-X-KEY:METHOD=AES-128', $playlist);
            $this->assertStringEndsWith("#EXT-X-ENDLIST\n", $playlist);
        }
        $this->assertCount(2, $this->completed);
        $this->assertSame([], $this->errors);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertWorkspacesAreCleanedUp();
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

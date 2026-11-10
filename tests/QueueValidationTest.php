<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\PendingHlsExport;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use stdClass;

/** Pre-dispatch rejections that need no FFmpeg binary. */
class QueueValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
        // Nothing is probed or encoded before dispatch, so placeholder bytes suffice.
        Storage::disk('queued')->put('source.mp4', 'source');
    }

    protected function source()
    {
        return FFMpeg::disk('queued')->open('source.mp4');
    }

    #[DataProvider('unsupportedParallelProvider')]
    public function test_parallel_plans_reject_unsupported_configuration_before_dispatch(Closure $configure, string $message): void
    {
        $ladder = $this->source()->hls(segmentDuration: 0.5)->video(bitrate: '100k', preset: 'ultrafast')
            ->variant('small', fn ($v) => $v->resize(width: 48))
            ->variant('large', fn ($v) => $v->resize(width: 96));
        $export = $configure($ladder, $this->source());

        $this->assertThrows(
            fn () => $export->queued(parallel: true)->job('invalid/master.m3u8'),
            InvalidArgumentException::class,
            $message,
        );
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * Get the HLS settings a parallel plan cannot split across jobs, with the reason it gives.
     */
    public static function unsupportedParallelProvider(): array
    {
        return [
            'playlist callback' => [fn (PendingHlsExport $ladder) => $ladder->playlistUsing(static fn ($text) => $text), 'playlist callbacks'],
            'complex graph' => [fn (PendingHlsExport $ladder, Media $source) => $source->hls()->filterComplex('[0:v]split=2[first][second]')
                ->variant('first', fn ($v) => $v->map('[first]'))
                ->variant('second', fn ($v) => $v->map('[second]')), 'complex graphs'],
            'unreferenced rendition' => [fn (PendingHlsExport $ladder) => $ladder->audioRendition('unused', 'English', fn ($v) => $v->map('0:a:0')), 'referenced'],
        ];
    }

    public function test_parallel_plans_cannot_be_nested_in_a_batch(): void
    {
        $export = $this->source()->hls(segmentDuration: 0.5)->video(bitrate: '100k', preset: 'ultrafast')
            ->variant('small', fn ($v) => $v->resize(width: 48))
            ->variant('large', fn ($v) => $v->resize(width: 96));

        $this->assertThrows(
            fn () => Bus::batch([$export->queued(parallel: true)->job('invalid/master.m3u8')])->dispatch(),
            InvalidArgumentException::class,
            'not inside another',
        );
        $this->assertSame(0, DB::table('jobs')->count());
    }

    #[DataProvider('requestLocalInputProvider')]
    public function test_queued_plans_reject_request_local_inputs_before_dispatch(Closure $open): void
    {
        $source = $open(Storage::disk('queued'));

        $this->assertThrows(fn () => $source->export()->queued(), InvalidArgumentException::class, 'durable named disks');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * Get the inputs that exist only for the current request.
     */
    public static function requestLocalInputProvider(): array
    {
        return [
            'local file' => [fn (FilesystemAdapter $disk) => FFMpeg::open(new File($disk->path('source.mp4')))],
            'filesystem instance' => [fn (FilesystemAdapter $disk) => FFMpeg::disk($disk)->open('source.mp4')],
        ];
    }

    public function test_queued_plans_reject_progress_callbacks_bound_to_an_object(): void
    {
        // An arrow function written in a test method is bound to the test case, and
        // a queued job cannot carry that object to the worker that runs the export.
        $callback = fn ($progress) => null;

        $this->assertThrows(
            fn () => $this->source()->export()->onProgress($callback)->queued(),
            InvalidArgumentException::class,
            'callbacks',
        );
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_queued_plans_reject_progress_callbacks_capturing_an_object(): void
    {
        $capture = new stdClass;
        $callback = static function ($progress) use ($capture) {
            $capture->progress = $progress;
        };

        $this->assertThrows(
            fn () => $this->source()->export()->onProgress($callback)->queued(),
            InvalidArgumentException::class,
            'callbacks',
        );
        $this->assertSame(0, DB::table('jobs')->count());
    }
}

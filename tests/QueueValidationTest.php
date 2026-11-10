<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

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

    public function test_parallel_plans_reject_unsupported_configuration_before_dispatch(): void
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
    }

    public function test_queued_plans_reject_request_local_inputs_before_dispatch(): void
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
        foreach ([fn ($progress) => null, static function ($progress) use ($capture) {
            $capture->progress = $progress;
        }] as $callback) {
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

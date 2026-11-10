<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use ProtoneMedia\LaravelFFMpeg\Events\QueuedExportCompleted;
use ProtoneMedia\LaravelFFMpeg\PendingExport;
use ProtoneMedia\LaravelFFMpeg\Queue\ExportJob;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use RuntimeException;

class IntegrationRecipeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'laravel-ffmpeg.enable_events' => true]);
        Storage::fake('recipes');
        Storage::fake('receipts');
        Storage::disk('recipes')->put('source.mp4', 'immutable source revision 1');
        Schema::create('recipe_media', function (Blueprint $table) {
            $table->id();
            $table->string('source_version');
            $table->string('profile_version');
            $table->string('current_export_id')->nullable();
            $table->string('published_export_id')->nullable();
        });
        Schema::create('recipe_conversions', function (Blueprint $table) {
            $table->string('export_id')->primary();
            $table->unsignedBigInteger('media_id');
            $table->string('source_version');
            $table->string('profile_version');
            $table->string('status')->default('pending');
            $table->text('inventory')->nullable();
        });
        DB::table('recipe_media')->insert(['id' => 1, 'source_version' => 'sha256:revision-1',
            'profile_version' => RecipeWebVideo::VERSION]);
        Event::listen(QueuedExportCompleted::class, [RecipeInventory::class, 'handle']);
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            $tool = basename($process->command[0]);
            if (in_array('-version', $process->command, true)) {
                return Process::result(output: $tool.' version 9.0.2');
            }
            if ($tool === 'ffprobe') {
                return Process::result(output: json_encode([
                    'format' => ['duration' => '1'],
                    'streams' => [['index' => 0, 'codec_type' => 'video'], ['index' => 1, 'codec_type' => 'audio']],
                    'chapters' => [],
                ]));
            }
            file_put_contents(end($process->command), 'encoded application derivative');

            return Process::result(output: "out_time_us=1000000\nprogress=end\n");
        });
    }

    protected function export(): PendingExport
    {
        return RecipeWebVideo::apply(FFMpeg::disk('recipes')->open('source.mp4')->export());
    }

    protected function request(): ExportJob
    {
        $queued = $this->export()->queued();
        DB::transaction(function () use ($queued) {
            $media = DB::table('recipe_media')
                ->where('id', 1)
                ->lockForUpdate()
                ->first();
            DB::table('recipe_conversions')->insert(['export_id' => $queued->id, 'media_id' => 1,
                'source_version' => $media->source_version, 'profile_version' => $media->profile_version]);
            DB::table('recipe_media')
                ->where('id', 1)
                ->update(['current_export_id' => $queued->id]);
        });

        return $queued->job('conversions/'.$queued->id.'/web.mp4');
    }

    public function test_profiles_apply_to_sync_exports(): void
    {
        $command = $this->export()->inspect();

        $this->assertContains('libx264', $command);
        $this->assertContains('scale=w=640:h=-2:reset_sar=1', $command);
        $this->assertNotContains('libx264', FFMpeg::disk('recipes')->open('source.mp4')->export()->inspect());
        Process::assertNothingRan();

        $result = $this->export()->save('sync.mp4');

        $this->assertSame('sync.mp4', $result->file()->path);
        Storage::disk('recipes')->assertExists('sync.mp4');
    }

    public function test_serialized_queue_exports_publish_their_inventory(): void
    {
        Bus::fake();

        $job = $this->request();
        Bus::dispatch($job);

        Bus::assertDispatched(ExportJob::class, fn ($sent) => $sent->exportId === $job->exportId);
        Storage::disk('recipes')->assertMissing($job->path);

        // Exercise the public job after a real serialization boundary, without a worker.
        $restored = unserialize(serialize($job));

        $this->assertSame($this->export()->inspect(), $restored->plan->restore()->inspect());

        $restored->handle();
        $row = DB::table('recipe_conversions')
            ->where('export_id', $job->exportId)
            ->sole();

        $this->assertSame('ready', $row->status);

        $inventory = json_decode($row->inventory, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($job->path, $inventory['files'][0]['path']);
        $this->assertTrue($inventory['files'][0]['owned']);
        $this->assertSame($job->exportId, DB::table('recipe_media')->sole()->published_export_id);
        Storage::disk('recipes')->assertExists($job->path);
        $this->assertSame('private', Storage::disk('receipts')->getVisibility($job->exportId.'.json'));
    }

    public function test_duplicate_completion_preserves_the_inventory(): void
    {
        $this->request();
        $new = $this->request();
        $new->handle();
        $original = DB::table('recipe_conversions')
            ->where('export_id', $new->exportId)
            ->sole()->inventory;
        $receipt = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        Event::dispatch(new QueuedExportCompleted($new->exportId, $receipt['files'], $receipt['playback']));

        $this->assertSame(
            $original,
            DB::table('recipe_conversions')->where('export_id', $new->exportId)->sole()->inventory,
        );
        $this->assertSame(2, DB::table('recipe_conversions')->count());
    }

    public function test_an_older_job_does_not_replace_the_published_export(): void
    {
        $old = $this->request();
        $new = $this->request();
        $new->handle();
        $old->handle();

        $this->assertSame('stale', DB::table('recipe_conversions')->where('export_id', $old->exportId)->sole()->status);
        $this->assertSame($new->exportId, DB::table('recipe_media')->sole()->published_export_id);
        $this->assertSame(2, DB::table('recipe_conversions')->count());
    }

    #[TestWith(['source_version', 'sha256:revision-2'])]
    #[TestWith(['profile_version', 'web-v3'])]
    public function test_a_changed_source_or_profile_blocks_publication(string $column, string $value): void
    {
        $job = $this->request();
        DB::table('recipe_media')
            ->where('id', 1)
            ->update([$column => $value]);
        $job->handle();

        $this->assertSame('stale', DB::table('recipe_conversions')->where('export_id', $job->exportId)->sole()->status);
        $this->assertNull(DB::table('recipe_media')->sole()->published_export_id);
    }

    public function test_database_failures_can_be_reconciled_from_the_receipt(): void
    {
        $job = $this->request();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER recipe_fail BEFORE UPDATE ON recipe_media
            BEGIN SELECT RAISE(ABORT, 'persistence unavailable'); END
            SQL);

        $this->assertThrows(fn () => $job->handle(), QueryException::class, 'persistence unavailable');
        $this->assertSame('pending', DB::table('recipe_conversions')->sole()->status);
        $this->assertNull(DB::table('recipe_conversions')->sole()->inventory);
        $this->assertNull(DB::table('recipe_media')->sole()->published_export_id);
        Storage::disk('recipes')->assertExists($job->path);
        Storage::disk('receipts')->assertExists($job->exportId.'.json');

        DB::unprepared('DROP TRIGGER recipe_fail');
        app(RecipeInventory::class)->reconcile($job->exportId);

        $this->assertSame('ready', DB::table('recipe_conversions')->sole()->status);
        $this->assertSame($job->exportId, DB::table('recipe_media')->sole()->published_export_id);
    }

    public function test_cleanup_deletes_only_owned_stale_files_within_the_attempt_prefix(): void
    {
        $old = $this->request();
        $new = $this->request();
        $new->handle();

        // A trusted application workflow can include borrowed inputs in its inventory.
        $borrowed = 'conversions/'.$old->exportId.'/borrowed.mp4';

        Storage::disk('recipes')->put($borrowed, 'shared');
        Storage::disk('recipes')->put($old->path, 'old derivative');

        $files = [
            ['disk' => 'recipes', 'path' => $old->path, 'size' => 14, 'role' => 'media', 'owned' => true],
            ['disk' => 'recipes', 'path' => $borrowed, 'size' => 6, 'role' => 'media', 'owned' => false],
            ['disk' => 'recipes', 'path' => 'source.mp4', 'size' => 27, 'role' => 'media', 'owned' => true],
        ];
        Event::dispatch(new QueuedExportCompleted($old->exportId, $files));
        $inventory = app(RecipeInventory::class);
        $inventory->deleteStale($new->exportId);

        Storage::disk('recipes')->assertExists($new->path);

        $inventory->deleteStale($old->exportId);
        $inventory->deleteStale($old->exportId);

        Storage::disk('recipes')->assertMissing($old->path);
        Storage::disk('recipes')->assertExists([$borrowed, 'source.mp4', $new->path]);
        $this->assertSame($new->exportId, DB::table('recipe_media')->sole()->published_export_id);
    }
}

// Ordinary application code: this class is applied, never stored in the export plan.
class RecipeWebVideo
{
    public const VERSION = 'web-v2';

    public static function apply(PendingExport $export): PendingExport
    {
        return $export->video('libx264', crf: 23, preset: 'medium')
            ->audio('aac', bitrate: '128k')
            ->resize(width: 640)
            ->outputOptions(['-movflags', '+faststart']);
    }
}

// Test application fixture, not a package persistence API.
class RecipeInventory
{
    public function handle(QueuedExportCompleted $event): void
    {
        // The export ID must already belong to a trusted application request.
        $request = DB::table('recipe_conversions')
            ->where('export_id', $event->exportId)
            ->first();
        if ($request === null) {
            throw new RuntimeException('Unknown conversion.');
        }
        DB::transaction(function () use ($event, $request) {
            // All receipt writers and publication updates use this same lock.
            DB::table('recipe_media')
                ->where('id', $request->media_id)
                ->lockForUpdate()
                ->first();
            $disk = Storage::disk('receipts');
            $path = $event->exportId.'.json';
            // Storage is intentionally outside database rollback.
            if (! $disk->exists($path) && ! $disk->put($path, json_encode([
                'exportId' => $event->exportId, 'files' => $event->files, 'playback' => $event->playback,
            ], JSON_THROW_ON_ERROR), ['visibility' => 'private'])) {
                throw new RuntimeException('Cannot retain completion receipt.');
            }
            $this->reconcile($event->exportId);
        });
    }

    public function reconcile(string $exportId): void
    {
        $receipt = json_decode(Storage::disk('receipts')->get($exportId.'.json'), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($exportId, $receipt) {
            $request = DB::table('recipe_conversions')
                ->where('export_id', $exportId)
                ->first();
            $media = DB::table('recipe_media')
                ->where('id', $request->media_id)
                ->lockForUpdate()
                ->first();
            $request = DB::table('recipe_conversions')
                ->where('export_id', $exportId)
                ->lockForUpdate()
                ->first();
            if ($request->status !== 'pending') {
                return;
            }
            $current = $media->current_export_id === $exportId
                && $media->source_version === $request->source_version
                && $media->profile_version === $request->profile_version;
            DB::table('recipe_conversions')
                ->where('export_id', $exportId)
                ->update([
                    'status' => $current ? 'ready' : 'stale', 'inventory' => json_encode($receipt, JSON_THROW_ON_ERROR),
                ]);
            if ($current) {
                DB::table('recipe_media')
                    ->where('id', $media->id)
                    ->update(['published_export_id' => $exportId]);
            }
        });
    }

    public function deleteStale(string $exportId): void
    {
        DB::transaction(function () use ($exportId) {
            $request = DB::table('recipe_conversions')
                ->where('export_id', $exportId)
                ->first();
            $media = DB::table('recipe_media')
                ->where('id', $request->media_id)
                ->lockForUpdate()
                ->first();
            if ($request->status !== 'stale' || $media->published_export_id === $exportId
                || $media->current_export_id === $exportId) {
                return;
            }
            foreach (json_decode($request->inventory, true, flags: JSON_THROW_ON_ERROR)['files'] as $file) {
                if ($file['owned'] === true && $file['disk'] === 'recipes'
                    && str_starts_with($file['path'], 'conversions/'.$exportId.'/')
                    && ! in_array('..', explode('/', $file['path']), true)) {
                    if (! Storage::disk($file['disk'])->delete($file['path'])) {
                        throw new RuntimeException('Cannot delete retired output.');
                    }
                }
            }
        });
    }
}

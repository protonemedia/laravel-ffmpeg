# Integrate exports with a Laravel application

Keep profiles, conversion records, and media-library relationships in your application. The package supplies builders, Laravel jobs, completion inventories, and playback descriptors. These recipes do not require another adapter or a package-owned model.

## Apply a profile before queueing

Create `app/Media/WebVideo.php` with an ordinary class. Change `VERSION` whenever the output settings change.

```php
<?php

namespace App\Media;

use ProtoneMedia\LaravelFFMpeg\PendingExport;

class WebVideo
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
```

The profile types `PendingExport`, the builder that `export()` returns. Specialized builders are separate classes, such as `PendingHlsExport` from `hls()`, so type a profile for one of them on that class.

Use a fresh builder for each conversion. Configure named disks that every worker can access, and store each source revision at an immutable path.

```php
use App\Media\WebVideo;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// Synchronous request, command, or application job.
$result = WebVideo::apply(
    FFMpeg::disk('media')
        ->open('sources/revision-1.mp4')
        ->export()
        ->toDisk('derived')
)
    ->save('conversions/sync-attempt/web.mp4');
$inventory = $result->files()
    ->map
    ->toArray()
    ->all();

// A separate, fresh queued conversion.
$queued = WebVideo::apply(
    FFMpeg::disk('media')
        ->open('sources/revision-1.mp4')
        ->export()
        ->toDisk('derived')
)
    ->queued(queue: 'media');
$exportId = $queued->id;
$job = $queued->job("conversions/{$exportId}/web.mp4");
// Persist the request and its identity before dispatching $job. See below.
```

The profile runs before `queued()` snapshots the builder. The serialized plan contains native settings, not the profile instance. Do not attach an Eloquent model, filesystem object, uploaded file, service, or open stream to the plan. Queue callbacks must be static closures that capture only scalar values or arrays. Resolve services inside the worker instead.

## Retain inventories before updating media records

Use an application conversion record for each attempt. Identify the requested conversion by the media ID, immutable source revision, profile name, and profile version. Store the attempt's `exportId` separately. A retry of persistence uses that same ID. A new encoding attempt gets a new ID and destination prefix.

1. In a database transaction, lock the media row. Insert the conversion request, then set the media row's current export ID and desired source and profile versions.
2. Commit before dispatch. Use `Bus::dispatch($job)` after the transaction, or Laravel's after-commit dispatch inside it. Use an application outbox if dispatch must survive a crash between commit and enqueue.
3. Register a synchronous listener for `QueuedExportCompleted`. Keep `laravel-ffmpeg.enable_events` enabled. Verify that the export ID belongs to a trusted request.
4. Write a private completion receipt containing `exportId`, `files`, and `playback` to durable storage before updating application state. Check write failures, or configure the disk with `throw => true`. Serialize receipt writers per media row, or use atomic create-if-absent storage. An `exists()` followed by `put()` alone is not a concurrency guard.
5. In a transaction, lock the media and conversion rows in a consistent order. If the conversion is already applied, return without replacing its inventory. Otherwise, persist the full receipt. Set the media's published export ID only if the current export ID, source version, and profile version still match the request. Mark any other completion stale.

Register the application listener in a service provider, after implementing its storage policy:

```php
use App\Listeners\RetainConversionInventory;
use Illuminate\Support\Facades\Event;
use ProtoneMedia\LaravelFFMpeg\Events\QueuedExportCompleted;

Event::listen(QueuedExportCompleted::class, RetainConversionInventory::class);
```

Each `files` entry contains `disk`, `path`, `size`, `role`, and `owned`. Preserve all entries, not just the first file. Preserve the optional `playback` array for HLS. Do not guess a versioned manifest path from the requested destination. Keep receipts and inventories private, and generate authorized delivery URLs when requested. Do not persist expiring signed URLs as file identity.

The [tested application fixture](../tests/NativeIntegrationRecipeTest.php) provides a small schema, a synchronous receipt listener, transactional stale-result checks, reconciliation, and ownership-aware cleanup. Its `RecipeInventory` class is application example code, not a package API. Rename the tables and disks to match your application. Use a shared durable disk instead of the test's `Storage::fake()` disks.

### Recover when persistence fails

A completion event follows file publication. If its synchronous listener throws, the job can fail even though the files already exist. Database rollback does not remove those files. Do not delete outputs on every listener exception, and do not assume that retrying the export only retries persistence. It can encode again and produce another versioned HLS inventory.

Run an application reconciliation command over pending conversion records and their retained receipts. Apply each receipt with the same transaction and stale-result guard as the listener. The fixture's `reconcile()` method does this without rerunning FFmpeg. Replay duplicate receipts safely.

There is still a crash window between publication and receipt storage. A missing receipt is not evidence that no files exist. Alert on pending attempts without receipts. For a single-file export, inspect and validate its recorded destination before recovery. For HLS, recover the full trusted playback inventory or create a new attempt. Do not invent an inventory by treating every object under a shared prefix as owned. Retain uncertain files until an operator or an application retention policy can resolve them.

### Delete only retired files that the attempt owns

Apply a retention period before cleanup. Under the same media-row lock used by publication, confirm that the attempt is neither current nor published. Delete only inventory entries with `owned === true`, an allowed disk, and the attempt's allocated destination prefix. Reject path traversal. Keep borrowed entries even when they are inside that prefix.

`GeneratedFile::delete()` already refuses borrowed files, but a persisted array does not enforce that check for you. Never recursively delete the source directory or infer ownership from a filename. For HLS, also check whether another retained presentation references an artifact before deletion. Keep cleanup retryable because database transactions cannot roll back storage deletes.

## Run Plank conversions in an application job

Keep Plank MediaLibrary's upload and relationship handling. Dispatch a normal Laravel job after your upload transaction commits. Do not register FFmpeg as an image-variant plugin. Video processing needs a queue timeout, an immutable source, and an inventory of outputs, rather than an image transformation callback.

Pass the Plank media ID and your conversion request ID as scalar job arguments. In `handle()`, reload the Plank record and your trusted source mapping. Obtain the named disk and relative path from that mapping. Never pass a temporary upload path or the Plank model into a queued export plan.

For example, use this structure in an application job that implements `ShouldQueue`. Here, `ConversionRequest` is **your** model with the fields described above. `ConversionInventory` is **your** service that retains a receipt and applies the transaction from the preceding section.

```php
public function handle(): void
{
    $request = \App\Models\ConversionRequest::findOrFail($this->requestId);
    $media = \Plank\Mediable\Media::findOrFail($request->media_id);
    // Resolve only trusted application fields, captured when requesting conversion.
    $result = \App\Media\WebVideo::apply(
        \ProtoneMedia\LaravelFFMpeg\Support\FFMpeg::disk($request->source_disk)
            ->open($request->source_path)
            ->export()
            ->toDisk($request->output_disk)
    )
        ->save("conversions/{$request->export_id}/web.mp4");

    app(\App\Media\ConversionInventory::class)
        ->retain(
            new \ProtoneMedia\LaravelFFMpeg\Events\QueuedExportCompleted(
                $request->export_id,
                $result->files()
                    ->map
                    ->toArray()
                    ->all(),
            ),
        );
}
```

This job executes `save()` synchronously inside the worker. It passes the returned inventory to the same application persistence policy. It does not rely on a `QueuedExportCompleted` event being emitted by synchronous `save()`. Alternatively, let the application job prepare and dispatch the package's `$queued->job(...)`, then retain the real completion event through the registered listener. Do not run both paths for the same attempt.

Check source and profile versions before expensive work, then check them again when applying the inventory. Attach a completed derivative to your owning model through your installed Plank version's supported import and relationship APIs only after the stale-result check. Track whether Plank copies, moves, or borrows the output. If it moves the file, update the retained location. If it copies the file, record the new owner before retiring the original. Do not delete Plank-managed originals as conversion cleanup.

## Test the application policy with Laravel tools

Use `inspect()` to assert profile options without storage access or processes. Use `Bus::fake()` to assert dispatch and job metadata, not encoding success. Use `Storage::fake()` for input staging, publication, receipts, and cleanup assertions.

For application-policy tests, use `Process::fake()` with `Process::preventStrayProcesses()`. Return FFmpeg 9.0.x version output and valid FFprobe JSON. The fake encoder must create the staged destination file as well as return success, because publication needs a real file. Do not fake the completion event when testing the listener.

Run the complete fixture without FFmpeg binaries:

```sh
vendor/bin/phpunit tests/NativeIntegrationRecipeTest.php
```

The tests run a serialized public export job and observe database rows and stored files. They cover duplicate completion, superseded jobs, changed source and profile versions, persistence rollback after publication, receipt reconciliation, and owned versus borrowed cleanup. They do not prove codec correctness, cross-process locking, or your Plank version's import behavior. Add real FFmpeg acceptance tests and a production-database concurrency test for those boundaries.

Use current lifecycle events for telemetry: `ExportStarting`, `ExportProgress`, `ExportCompleted`, `ExportFailed`, and `ExportCancelled`. Use `QueuedExportCompleted` for the final queued workflow inventory, including parallel HLS. Per-operation progress is not a durable conversion status. Keep progress callbacks small, and use scalar identifiers in static queued callbacks. Synchronous callback and listener exceptions can interrupt execution. An asynchronous listener can observe progress but cannot cancel the running encoder.

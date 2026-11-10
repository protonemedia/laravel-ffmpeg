# Integrating Exports With Your Application

- [Introduction](#introduction)
- [Applying Profiles Before Queueing](#apply-a-profile-before-queueing)
- [Retaining Inventories Before Updating Media Records](#retain-inventories-before-updating-media-records)
    - [Recovering When Persistence Fails](#recover-when-persistence-fails)
    - [Deleting Retired Files the Attempt Owns](#delete-only-retired-files-that-the-attempt-owns)
- [Running Plank Conversions in an Application Job](#run-plank-conversions-in-an-application-job)
- [Testing the Application Policy](#test-the-application-policy-with-laravel-tools)

<a name="introduction"></a>
## Introduction

Laravel FFmpeg supplies export builders, Laravel jobs, completion inventories, and playback descriptors, while profiles, conversion records, and media-library relationships stay in your application, so these recipes need no other adapter or package-owned model.

<a name="apply-a-profile-before-queueing"></a>
## Applying Profiles Before Queueing

A profile is an ordinary class that applies your output settings to a builder. For example, you may create `app/Media/WebVideo.php`, changing its `VERSION` whenever the settings change:

```php
<?php

namespace App\Media;

use ProtoneMedia\LaravelFFMpeg\PendingExport;

class WebVideo
{
    public const VERSION = 'web-v2';

    public static function apply(PendingExport $export): PendingExport
    {
        return $export
            ->video('libx264', crf: 23, preset: 'medium')
            ->audio('aac', bitrate: '128k')
            ->resize(width: 640)
            ->outputOptions(['-movflags', '+faststart']);
    }
}
```

This profile types the `PendingExport` builder that the `export` method returns. Specialized builders are separate classes, such as the `PendingHlsExport` returned by the `hls` method, so type a profile for one of them on that class.

You should use a fresh builder for each conversion, read from and write to named disks that every worker can access, and store each source revision at an immutable path. To convert synchronously in a request, command, or application job, you may apply the profile and call the `save` method:

```php
use App\Media\WebVideo;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$export = FFMpeg::disk('media')
    ->open('sources/revision-1.mp4')
    ->export()
    ->toDisk('derived');

$result = WebVideo::apply($export)
    ->save('conversions/sync-attempt/web.mp4');

$inventory = $result
    ->files()
    ->map
    ->toArray()
    ->all();
```

A queued conversion needs its own fresh builder, to which you may apply the profile before calling the `queued` method:

```php
$export = FFMpeg::disk('media')
    ->open('sources/revision-1.mp4')
    ->export()
    ->toDisk('derived');

$queued = WebVideo::apply($export)
    ->queued(queue: 'media');

$exportId = $queued->id;

$job = $queued->job("conversions/{$exportId}/web.mp4");

// Persist the request and its identity before dispatching $job, as described below.
```

The profile runs before the `queued` method snapshots the builder, so the serialized plan contains native settings rather than the profile instance.

> [!WARNING]
> Never attach an Eloquent model, filesystem object, uploaded file, service, or open stream to the plan. Queue callbacks must be static closures that capture only scalar values or arrays, so resolve services inside the worker instead.

<a name="retain-inventories-before-updating-media-records"></a>
## Retaining Inventories Before Updating Media Records

You should keep an application conversion record for each attempt. The media ID, immutable source revision, profile name, and profile version identify the requested conversion, while the attempt's `exportId` is stored separately: retrying persistence reuses that ID, whereas a new encoding attempt gets a new ID and destination prefix. A typical flow looks like this:

1. In a database transaction, lock the media row, insert the conversion request, and then set the media row's current export ID and desired source and profile versions.
2. Commit before dispatching, either by calling `Bus::dispatch($job)` after the transaction or by using Laravel's after-commit dispatch inside it. If dispatch must survive a crash between the commit and the enqueue, use an application outbox.
3. Register a synchronous listener for the `QueuedExportCompleted` event, keep the `laravel-ffmpeg.enable_events` option enabled, and verify that the export ID belongs to a trusted request.
4. Before updating application state, write a private completion receipt containing the `exportId`, `files`, and `playback` values to durable storage. Check for write failures, or configure the disk with `throw => true`. Serialize receipt writers per media row or use atomic create-if-absent storage, since an `exists` call followed by a `put` call is not a concurrency guard on its own.
5. In a transaction, lock the media and conversion rows in a consistent order. If the conversion has already been applied, return without replacing its inventory. Otherwise, persist the full receipt and set the media's published export ID only if the current export ID, source version, and profile version still match the request, marking any other completion as stale.

Once your listener implements this storage policy, you may register it in a service provider:

```php
use App\Listeners\RetainConversionInventory;
use Illuminate\Support\Facades\Event;
use ProtoneMedia\LaravelFFMpeg\Events\QueuedExportCompleted;

Event::listen(QueuedExportCompleted::class, RetainConversionInventory::class);
```

Each `files` entry contains `disk`, `path`, `size`, `role`, and `owned` keys. Preserve every entry rather than only the first file, along with the optional `playback` array for HLS, and never guess a versioned manifest path from the requested destination.

> [!WARNING]
> Keep receipts and inventories private and generate authorized delivery URLs when they are requested. Do not persist expiring signed URLs as file identity.

The [tested application fixture](../tests/IntegrationRecipeTest.php) provides a small schema, a synchronous receipt listener, transactional stale-result checks, reconciliation, and ownership-aware cleanup. Its `RecipeInventory` class is application example code rather than a package API, so rename the tables and disks to match your application and use a shared durable disk instead of the test's `Storage::fake()` disks.

<a name="recover-when-persistence-fails"></a>
### Recovering When Persistence Fails

The completion event follows file publication, so if its synchronous listener throws, the job can fail even though the files already exist, and a database rollback does not remove them. You should not delete outputs on every listener exception, and retrying the export does more than retry persistence: it can encode again and produce another versioned HLS inventory.

Instead, you may run an application reconciliation command over pending conversion records and their retained receipts, applying each receipt with the same transaction and stale-result guard as the listener. The fixture's `reconcile` method does this without rerunning FFmpeg. Replaying a duplicate receipt must be safe.

> [!WARNING]
> There is still a crash window between publication and receipt storage, so a missing receipt is not evidence that no files exist. You should alert on pending attempts without receipts.

For a single-file export, inspect and validate its recorded destination before recovery. For HLS, recover the full trusted playback inventory or create a new attempt, and never invent an inventory by treating every object under a shared prefix as owned. Retain uncertain files until an operator or an application retention policy can resolve them.

<a name="delete-only-retired-files-that-the-attempt-owns"></a>
### Deleting Retired Files the Attempt Owns

Cleanup should start only after a retention period. Under the same media-row lock used by publication, confirm that the attempt is neither current nor published, then delete only inventory entries that have `owned === true`, an allowed disk, and a path inside the attempt's allocated destination prefix. Reject path traversal, and keep borrowed entries even when they are inside that prefix.

> [!WARNING]
> The `delete` method of `GeneratedFile` already refuses borrowed files, but a persisted array does not enforce that check for you. Never recursively delete the source directory or infer ownership from a filename.

For HLS, also check whether another retained presentation references an artifact before deleting it. Keep cleanup retryable, since database transactions cannot roll back storage deletes.

<a name="run-plank-conversions-in-an-application-job"></a>
## Running Plank Conversions in an Application Job

When you use Plank MediaLibrary, you may keep its upload and relationship handling and dispatch a normal Laravel job after your upload transaction commits. Do not register FFmpeg as an image-variant plugin, since video processing needs a queue timeout, an immutable source, and an inventory of outputs rather than an image transformation callback.

Pass the Plank media ID and your conversion request ID to the job as scalar arguments. In the `handle` method, reload the Plank record and your trusted source mapping, and obtain the named disk and relative path from that mapping. Never pass a temporary upload path or the Plank model into a queued export plan.

For example, an application job may use the following structure, where `ConversionRequest` is your model with the fields described above and `RetainConversionInventory` is your listener from the preceding section:

```php
<?php

namespace App\Jobs;

use App\Listeners\RetainConversionInventory;
use App\Media\WebVideo;
use App\Models\ConversionRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Plank\Mediable\Media;
use ProtoneMedia\LaravelFFMpeg\Events\QueuedExportCompleted;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class ConvertMediaVideo implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $mediaId,
        public int $requestId,
    ) {}

    public function handle(RetainConversionInventory $inventory): void
    {
        $request = ConversionRequest::findOrFail($this->requestId);
        $media = Media::findOrFail($request->media_id);

        // Resolve only trusted application fields, captured when requesting the conversion.
        $export = FFMpeg::disk($request->source_disk)
            ->open($request->source_path)
            ->export()
            ->toDisk($request->output_disk);

        $result = WebVideo::apply($export)
            ->save("conversions/{$request->export_id}/web.mp4");

        $inventory->handle(new QueuedExportCompleted(
            $request->export_id,
            $result
                ->files()
                ->map
                ->toArray()
                ->all(),
        ));
    }
}
```

This job calls the `save` method synchronously inside the worker and passes the returned inventory to the same application persistence policy, because a synchronous `save` call does not emit a `QueuedExportCompleted` event. Alternatively, the application job may prepare and dispatch the package's `$queued->job(...)`, then retain the real completion event through the registered listener.

> [!WARNING]
> Never run both paths for the same attempt.

Check the source and profile versions before expensive work and again when applying the inventory. Only after that stale-result check should you attach a completed derivative to your owning model, using the import and relationship APIs your installed Plank version supports. Track whether Plank copies, moves, or borrows the output: if it moves the file, update the retained location, and if it copies the file, record the new owner before retiring the original. Conversion cleanup must never delete Plank-managed originals.

<a name="test-the-application-policy-with-laravel-tools"></a>
## Testing the Application Policy

The `inspect` method lets you assert profile options without storage access or processes, `Bus::fake()` lets you assert dispatch and job metadata rather than encoding success, and `Storage::fake()` covers input staging, publication, receipts, and cleanup assertions.

For application-policy tests, you may use `Process::fake()` together with `Process::preventStrayProcesses()`, returning FFmpeg 9.0.x version output and valid FFprobe JSON. Because publication needs a real file, the fake encoder must create the staged destination file as well as return success. When testing the listener, do not fake the completion event.

You may run the complete fixture without FFmpeg binaries:

```sh
vendor/bin/phpunit tests/IntegrationRecipeTest.php
```

These tests run a serialized public export job and observe database rows and stored files, covering duplicate completion, superseded jobs, changed source and profile versions, persistence rollback after publication, receipt reconciliation, and owned versus borrowed cleanup.

> [!NOTE]
> The fixture does not prove codec correctness, cross-process locking, or your Plank version's import behavior, so add real FFmpeg acceptance tests and a production-database concurrency test for those boundaries.

For telemetry, you may listen to the current lifecycle events: `ExportStarting`, `ExportProgress`, `ExportCompleted`, `ExportFailed`, and `ExportCancelled`. Use `QueuedExportCompleted` for the final inventory of a queued workflow, including parallel HLS, since per-operation progress is not a durable conversion status. Keep progress callbacks small and use scalar identifiers in static queued callbacks. Synchronous callback and listener exceptions can interrupt execution, while an asynchronous listener can observe progress but cannot cancel the running encoder.

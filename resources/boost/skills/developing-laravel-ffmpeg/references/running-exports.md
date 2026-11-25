# Running Exports

## Results and Failures

The `save` method runs the operation and returns the files it published. Failures throw a `MediaException`:

```php
use Illuminate\Support\Facades\Log;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

try {
    $result = FFMpeg::disk('uploads')
        ->open('videos/intro.mov')
        ->export()
        ->video('libx264')
        ->audio('aac')
        ->toDisk('videos')
        ->save('videos/intro.mp4');
} catch (MediaException $exception) {
    Log::error('The video could not be encoded.', [
        'phase' => $exception->phase,
        'category' => $exception->category,
    ]);

    throw $exception;
}

$file = $result->file();

$url = $file->temporaryUrl(now()->addMinutes(10));

$size = $file->size;
```

Each `GeneratedFile` has `disk`, `path`, `size`, `role`, and `owned` properties, plus the `url`, `temporaryUrl`, `readStream`, and `delete` methods. The exception's `phase` names the step that failed, such as `staging`, `encoding`, or `publication`. Keep its `diagnostics` out of public responses, because they may contain URLs. A failed export never deletes the files it already published, and lists them in the exception's `published` property.

## Progress and Events

The `onProgress` method receives a `Progress` instance. Its `percentage`, `speed`, and `remainingSeconds` properties are null outside the encoding phase and whenever FFmpeg cannot tell:

```php
use Illuminate\Support\Facades\Cache;
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('uploads')
    ->open('videos/intro.mov')
    ->export()
    ->video('libx264')
    ->audio('aac')
    ->onProgress(function (Progress $progress) {
        if ($progress->isEncoding() && $progress->percentage !== null) {
            Cache::put('videos.intro.progress', $progress->percentage);
        }
    })
    ->save('videos/intro.mp4');
```

To stop the encoder, throw `ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled` from the callback. Every export also dispatches the `ExportStarting`, `ExportProgress`, `ExportCompleted`, `ExportFailed`, and `ExportCancelled` events from the `ProtoneMedia\LaravelFFMpeg\Events` namespace, so a listener may observe all exports without configuring each one.

## Timeouts

The `timeout` method limits one execution in seconds, from staging to publication, and `idleTimeout` limits how long FFmpeg may run without output:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('uploads')
    ->open('videos/intro.mov')
    ->export()
    ->timeout(900)
    ->idleTimeout(30)
    ->video('libx264')
    ->audio('aac')
    ->save('videos/intro.mp4');
```

## Queued Exports

The `queued` method moves the configured export to a queue worker. Its `save` method returns Laravel's `PendingDispatch`, and `configureJob` adjusts the job before dispatch:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$pending = FFMpeg::disk('uploads')
    ->open('videos/intro.mov')
    ->export()
    ->video('libx264', preset: 'fast')
    ->audio('aac')
    ->toDisk('videos')
    ->queued(connection: 'redis', queue: 'media')
    ->configureJob(function ($job) {
        $job->tries = 3;
        $job->timeout = 1800;
    });

$exportId = $pending->id; // Store it to match the completion event.

$pending->save('videos/intro.mp4')
    ->afterCommit();
```

Queued inputs and destinations must be named disks that every worker can read, so store uploads on a disk before queueing them. Callbacks such as `onProgress` must be static closures that capture only scalars or arrays.

The job timeout is the export's timeout plus 30 seconds, which is 3630 seconds by default. Set the queue connection's `retry_after` above it, because Laravel's connections default to 90 seconds and then hand a running export to a second worker, which encodes it again or fails the job with a `MaxAttemptsExceededException`. The `ffmpeg:doctor` command warns about a `retry_after` that does not exceed the job timeout.

The `QueuedExportCompleted` event carries the `exportId` and the published `files` as arrays, plus a `playback` inventory for HLS. Listeners receive every queued export, including duplicate deliveries, so update by the stored ID instead of throwing when no record matches. A listener exception fails the job after its files were published:

```php
use App\Models\Video;
use Illuminate\Support\Facades\Event;
use ProtoneMedia\LaravelFFMpeg\Events\QueuedExportCompleted;

Event::listen(function (QueuedExportCompleted $event) {
    Video::where('export_id', $event->exportId)
        ->update(['files' => $event->files, 'status' => 'ready']);
});
```

## Stopping Workers

A worker that receives `SIGTERM`, `SIGINT`, or `SIGQUIT` stops FFmpeg, deletes the workspace, and publishes no further files. The attempt throws a `MediaException` with the `interrupted` category, so Laravel retries it while `tries` remain and fails it otherwise. A job that reaches its timeout stops FFmpeg and deletes the workspace in the same way before Laravel fails it.

`SIGKILL` cannot be intercepted, so FFmpeg keeps running and its workspace stays behind. Configure Supervisor with `stopasgroup=true` and `killasgroup=true`, as Laravel's queue documentation does, so both signals also reach FFmpeg. Give `stopwaitsecs` enough time for the worker to finish its current storage transfer and clean up:

```ini
[program:laravel-worker]
command=php /var/www/html/artisan queue:work redis --queue=media
stopasgroup=true
killasgroup=true
stopwaitsecs=60
```

## Job Chains and Batches

The `job` method builds the job without dispatching it, for use in `Bus::chain()` and `Bus::batch()`:

```php
use App\Jobs\NotifyVideoReady;
use App\Models\Video;
use Illuminate\Support\Facades\Bus;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$video = Video::findOrFail(42);

$job = FFMpeg::disk('uploads')
    ->open('videos/intro.mov')
    ->export()
    ->video('libx264')
    ->audio('aac')
    ->toDisk('videos')
    ->queued()
    ->job('videos/intro.mp4');

Bus::chain([$job, new NotifyVideoReady($video->id)])
    ->onQueue('media')
    ->dispatch();
```

An HLS export queued with `queued(parallel: true)` encodes independent variants on separate workers in a Laravel batch, so it needs the batch table. The finalizer publishes a versioned master playlist, so read its path from the `QueuedExportCompleted` event instead of the path you passed to `save`. The export owns the files of its parts, including their keys, so deleting the owned files of that event removes the whole export.

A failed part cancels the batch, so no master is published. Once every part has run, the package dispatches an `ExportFailed` event whose `operationId` is the export ID and whose `published` property lists every file the completed parts published, so you may delete them:

```php
use App\Models\Video;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Events\ExportFailed;

Event::listen(function (ExportFailed $event) {
    // Other failed exports report their own operation ID, so only stored export IDs match.
    if (! Video::where('export_id', $event->operationId)->exists()) {
        return;
    }

    foreach ($event->published as $file) {
        Storage::disk($file['disk'])->delete($file['path']);
    }
});
```

## Deleting Outputs

Delete outputs through the root result's inventory. The `delete` method removes exactly that path and returns `false` without touching storage for a file the export does not own, such as a segment it shares with another presentation:

```php
use ProtoneMedia\LaravelFFMpeg\GeneratedFile;

// $result is the ExportResult or HlsExportResult returned by save().
$result->files()
    ->where('owned', true)
    ->each(fn (GeneratedFile $file) => $file->delete());
```

The `files` of a `QueuedExportCompleted` event carry the same `owned` flag, so you may delete each owned file of a queued export with `Storage::disk($file['disk'])->delete($file['path'])`.

Temporary workspaces are removed after every operation, including failures, interrupted workers, and timeouts. Only a worker killed with `SIGKILL` leaves its workspace behind.

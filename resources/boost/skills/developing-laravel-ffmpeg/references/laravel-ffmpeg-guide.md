# Laravel FFMpeg v9 reference

## Inputs and storage

`FFMpeg::disk($disk)->open($key)` returns a `Media`. The disk accepts a configured name or a filesystem instance. String keys are relative to the disk. Absolute local paths require an `Illuminate\Http\File` object.

`FFMpeg::openUrl($url, headers: [...], download: false)` opens an HTTP input. Remote URLs require application-level authorization and validation before use.

For a single disk-key input, the output disk defaults to the input disk. `toDisk()` sets an explicit destination. Remote single-file inputs are staged locally. Remote manifests with dependent files cannot be staged as a single file.

## Conversion and filters

`video()` and `audio()` accept encoder names, not PHP-FFMpeg format objects. Integer bitrates are bits per second. Unit strings include `'1500k'` and `'128k'`.

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$result = FFMpeg::disk('uploads')
    ->open('video.mp4')
    ->export()
    ->toDisk('converted')
    ->video('libx264', crf: 23, preset: 'medium')
    ->audio('aac', bitrate: '128k')
    ->fit(1280, 720)
    ->save('video.mp4');

$file = $result->file();
```

`resize(width: 640)` preserves aspect ratio. Supplying both resize dimensions stretches the image. `fit(1280, 720)` contains the image in that canvas. `fit(1280, 720, mode: 'cover')` crops to fill it.

Stream copying uses `video('copy')->audio('copy')` with a compatible output container. Filters require encoding rather than copying the filtered stream.

`watermark()` accepts media from a disk, including a different disk than the video.

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$logo = FFMpeg::disk('assets')
    ->open('logo.png');

$result = FFMpeg::disk('uploads')
    ->open('video.mp4')
    ->export()
    ->toDisk('converted')
    ->video('libx264', crf: 23)
    ->audio('aac', bitrate: '128k')
    ->watermark($logo, position: 'bottom-right', x: 10, y: 10, width: 100)
    ->save('watermarked.mp4');
```

## Frames and previews

`frame()` returns a pending frame operation. `get()` returns JPEG bytes without publishing a file. `save()` returns an `ExportResult`.

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$media = FFMpeg::disk('uploads')
    ->open('video.mp4');
$result = $media->frame(at: 1)
    ->toDisk('thumbnails')
    ->save('thumb.jpg');
$jpeg = $media->frame(at: '00:00:01.000')
    ->get();
```

`frames(every: 5)` and `frames(count: 10)` select interval-based or count-based extraction. `storyboard(every: 5, columns: 5, rows: 5)` creates preview sheets. These pending operations accept `toDisk()` and `save()` without an intervening `export()`.

## HLS

`hls(segmentDuration: 6)` starts an HLS export with `libx264` video and `aac` audio defaults. Named variants receive an `HlsVariant` callback. `save()` returns an `HlsExportResult` with a generated-file inventory and `playlist()`.

```php
use ProtoneMedia\LaravelFFMpeg\HlsVariant;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$result = FFMpeg::disk('uploads')
    ->open('video.mp4')
    ->hls(segmentDuration: 6)
    ->toDisk('streams')
    ->variant('720p', static function (HlsVariant $variant): void {
        $variant->video('libx264', bitrate: '2500k')
            ->audio('aac', bitrate: '128k')
            ->fit(1280, 720);
    })
    ->variant('360p', static function (HlsVariant $variant): void {
        $variant->video('libx264', bitrate: '800k')
            ->audio('aac', bitrate: '96k')
            ->fit(640, 360);
    })
    ->save('video/master.m3u8');

$playlist = $result->playlist();
```

`fmp4()` selects fragmented MP4 segments. AES-128 encryption is limited to MPEG-TS HLS. Variant destination paths are configured on `variant()`, while the destination disk belongs to the HLS export.

## Progress and queues

`onProgress()` receives `ProtoneMedia\LaravelFFMpeg\Progress`, with a `phase` and nullable `percentage`, `elapsedSeconds`, `speed`, and `remainingSeconds` fields. Progress includes staging, probing, encoding, validation, and publication phases.

Global `Events\ExportProgress` listeners receive an operation ID, output destinations, and the same `Progress` value without requiring `onProgress()`. `ExportCompleted` follows publication and cleanup. `QueuedExportCompleted` represents successful whole-workflow completion, not each parallel HLS part. Disabling `laravel-ffmpeg.enable_events` suppresses these events but preserves callbacks and queue chains.

`queued()` snapshots the pending operation. Its `save()` returns Laravel's `PendingDispatch`, not an `ExportResult`. Its `job()` builds a job without dispatch for Laravel chains or batches.

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$dispatch = FFMpeg::disk('uploads')
    ->open('video.mp4')
    ->export()
    ->toDisk('converted')
    ->video('libx264', crf: 23)
    ->audio('aac', bitrate: '128k')
    ->queued(queue: 'media')
    ->save('video.mp4');
```

Queued inputs and outputs require durable named disks accessible to workers. Filesystem objects, uploads, local `File` inputs, and open streams are rejected. Queued callbacks must be static closures with only scalar or array captures.

`queued(parallel: true)` is for HLS. Parallel HLS requires a shared named destination disk and Laravel batch support.

## Results and lifecycle

`ExportResult::files()` returns `GeneratedFile` objects with `disk`, `path`, `size`, `role`, and `owned` fields. `file()` selects one output. `processes()` returns process summaries. A generated file provides `readStream()` and disk-dependent `url()` and `temporaryUrl()` methods.

Temporary workspaces are cleaned up after operations, including failures. A hard worker kill cannot run PHP cleanup, so the application needs safe stale-workspace retention. Cleanup does not delete published output files. `GeneratedFile::delete()` deletes only an owned file. The application controls output retention and shared-reference checks.

## Configuration

The package merges `config/config.php` into `laravel-ffmpeg`. Publishing the configuration is optional. The configuration includes `ffmpeg.binaries`, `ffmpeg.threads`, `ffprobe.binaries`, `timeout`, `idle_timeout`, and `temporary_files_root`.

FFmpeg and FFprobe 9.0.x must be installed on every machine that executes media operations. Encoder availability depends on the FFmpeg build.

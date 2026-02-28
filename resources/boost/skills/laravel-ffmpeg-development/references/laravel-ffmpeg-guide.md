# Laravel FFMpeg Reference

Complete reference for `protonemedia/laravel-ffmpeg`. Full documentation: https://github.com/protonemedia/laravel-ffmpeg#readme

## What this package provides

- A Laravel-first fluent wrapper around PHP-FFMpeg.
- Input/output routed via Laravel’s filesystem disks.
- Helpers for common operations (transcoding, resizing, filters, watermarking).
- Higher-level exporters for HLS (including encrypted + rotating keys).
- Utilities for frame extraction, tiles/sprites, VTT preview thumbnails.
- Support for multiple inputs/outputs, concatenation, and process output inspection.

## Installation

1) Ensure FFmpeg is installed:

```bash
ffmpeg -version
```

2) Install the package:

```bash
composer require pbmedia/laravel-ffmpeg
```

3) If you’re not using package discovery, register provider/facade (see README).

4) Publish config:

```bash
php artisan vendor:publish --provider="ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider"
```

## Basic usage

Convert an audio/video file:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use FFMpeg\Format\Audio\Aac;

FFMpeg::fromDisk('songs')
    ->open('yesterday.mp3')
    ->export()
    ->toDisk('converted_songs')
    ->inFormat(new Aac)
    ->save('yesterday.aac');
```

### Opening from a filesystem instance

```php
$media = FFMpeg::fromFilesystem($filesystem)->open('yesterday.mp3');
```

### Opening uploaded files

```php
FFMpeg::open($request->file('video'));
```

### Open from URL

```php
FFMpeg::openUrl('https://example.com/video.mp4');

FFMpeg::openUrl('https://example.com/video.mp4', [
    'Authorization' => 'Basic ...',
]);
```

## Progress monitoring

```php
FFMpeg::open('steve_howe.mp4')
    ->export()
    ->onProgress(function ($percentage, $remaining = null, $rate = null) {
        // $percentage: 0-100
        // $remaining: seconds (if available)
        // $rate: encode rate (if available)
    });
```

## Error handling

Encoding failures throw `ProtoneMedia\LaravelFFMpeg\Exporters\EncodingException`.

It exposes:

- `getCommand()` — the exact FFmpeg command.
- `getErrorOutput()` — full stderr log.

```php
use ProtoneMedia\LaravelFFMpeg\Exporters\EncodingException;

try {
    FFMpeg::open('yesterday.mp3')->export()->inFormat(new Aac)->save('yesterday.aac');
} catch (EncodingException $e) {
    $command = $e->getCommand();
    $log = $e->getErrorOutput();
}
```

## Filters

### Via closure (VideoFilters)

```php
use FFMpeg\Filters\Video\VideoFilters;

FFMpeg::fromDisk('videos')
    ->open('steve_howe.mp4')
    ->addFilter(function (VideoFilters $filters) {
        $filters->resize(new \FFMpeg\Coordinate\Dimension(640, 480));
    })
    ->export()
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->save('small_steve.mkv');
```

### Via filter objects

```php
$start = \FFMpeg\Coordinate\TimeCode::fromSeconds(5);
$clip = new \FFMpeg\Filters\Video\ClipFilter($start);

FFMpeg::open('steve_howe.mp4')->addFilter($clip);
```

### After `export()`

```php
FFMpeg::open('steve_howe.mp4')
    ->export()
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->addFilter(function (VideoFilters $filters) {
        $filters->resize(new \FFMpeg\Coordinate\Dimension(640, 480));
    })
    ->save('small_steve.mkv');
```

### Resizing helper

```php
FFMpeg::open('steve_howe.mp4')
    ->export()
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->resize(640, 480) // mode + aspect-ratio options exist (see README)
    ->save('steve_howe_resized.mp4');
```

### Custom raw options

```php
FFMpeg::open('steve_howe.mp4')->addFilter(['-itsoffset', 1]);
// or
FFMpeg::open('steve_howe.mp4')->addFilter('-itsoffset', 1);
```

## Watermarks

```php
use ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFactory;

FFMpeg::open('steve_howe.mp4')
    ->addWatermark(function (WatermarkFactory $watermark) {
        $watermark->fromDisk('local')
            ->open('logo.png')
            ->right(25)
            ->bottom(25);
    });
```

### Manipulating watermarks (spatie/image)

The README shows installing `spatie/image` and then calling manipulation methods on the factory.

## Export without transcoding (CopyFormat)

```php
use ProtoneMedia\LaravelFFMpeg\FFMpeg\CopyFormat;

FFMpeg::open('video.mp4')
    ->export()
    ->inFormat(new CopyFormat)
    ->save('video.mkv');
```

## Chaining multiple exports

`save()` returns a fresh opener so you can chain:

```php
FFMpeg::open('my_movie.mov')
    ->export()->toDisk('ftp')->inFormat(new \FFMpeg\Format\Video\WMV)->save('my_movie.wmv')
    ->export()->toDisk('s3')->inFormat(new \FFMpeg\Format\Video\X264)->save('my_movie.mkv');
```

## Frames, thumbnails, tiles, and VTT

### Single frame

```php
FFMpeg::fromDisk('videos')
    ->open('steve_howe.mp4')
    ->getFrameFromSeconds(10)
    ->export()
    ->toDisk('thumbnails')
    ->save('FrameAt10sec.png');
```

Get frame contents (no filesystem write):

```php
$contents = FFMpeg::open('video.mp4')
    ->getFrameFromSeconds(2)
    ->export()
    ->getFrameContents();
```

### Multiple frames

```php
FFMpeg::open('video.mp4')
    ->exportFramesByInterval(2)
    ->save('thumb_%05d.jpg');
```

### Tiles + optional VTT

```php
use ProtoneMedia\LaravelFFMpeg\Filters\TileFactory;

FFMpeg::open('steve_howe.mp4')
    ->exportTile(function (TileFactory $factory) {
        $factory->interval(10)
            ->scale(320, 180)
            ->grid(5, 5)
            ->generateVTT('thumbnails.vtt');
    })
    ->save('tile_%05d.jpg');
```

## Multiple inputs

Open multiple files (possibly across disks):

```php
FFMpeg::open(['video1.mp4', 'video2.mp4']);

FFMpeg::fromDisk('uploads')->open('video1.mp4')
    ->fromDisk('archive')->open('video2.mp4');
```

When using multiple inputs, add output mappings so FFmpeg knows how to map streams.

```php
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use \FFMpeg\Format\Video\X264;

FFMpeg::fromDisk('local')
    ->open(['video.mp4', 'audio.m4a'])
    ->export()
    ->addFormatOutputMapping(
        new X264,
        Media::make('local', 'new_video.mp4'),
        ['0:v', '1:a']
    )
    ->save();
```

## Concatenation

Without transcoding:

```php
FFMpeg::fromDisk('local')
    ->open(['video.mp4', 'video2.mp4'])
    ->export()
    ->concatWithoutTranscoding()
    ->save('concat.mp4');
```

With transcoding:

```php
FFMpeg::fromDisk('local')
    ->open(['video.mp4', 'video2.mp4'])
    ->export()
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->concatWithTranscoding(true, true)
    ->save('concat.mp4');
```

## Duration helpers

```php
$media = FFMpeg::open('wwdc_2006.mp4');

$seconds = $media->getDurationInSeconds();
$ms = $media->getDurationInMiliseconds();
```

## Temporary files on remote disks

Clean up temp files:

```php
FFMpeg::cleanupTemporaryFiles();
```

You can configure the temp root via `temporary_files_root`.

## HLS

### Basic HLS export

```php
use \FFMpeg\Format\Video\X264;

$low = (new X264)->setKiloBitrate(250);
$mid = (new X264)->setKiloBitrate(500);
$high = (new X264)->setKiloBitrate(1000);

FFMpeg::fromDisk('videos')
    ->open('steve_howe.mp4')
    ->exportForHLS()
    ->setSegmentLength(10)
    ->setKeyFrameInterval(48)
    ->addFormat($low)
    ->addFormat($mid)
    ->addFormat($high)
    ->save('adaptive_steve.m3u8');
```

### Encrypted HLS

```php
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSExporter;

$key = HLSExporter::generateEncryptionKey();

FFMpeg::open('steve_howe.mp4')
    ->exportForHLS()
    ->withEncryptionKey($key)
    ->addFormat($low)
    ->addFormat($mid)
    ->addFormat($high)
    ->save('adaptive_steve.m3u8');
```

### Rotating keys

```php
FFMpeg::open('steve_howe.mp4')
    ->exportForHLS()
    ->withRotatingEncryptionKey(function ($filename, $contents) {
        // store each key securely
    }, 1)
    ->addFormat($low)
    ->save('adaptive_steve.m3u8');
```

Pitfall (README): slow filesystems can cause errors like `No key URI specified...`. You may configure `temporary_files_encrypted_hls` (e.g. `/dev/shm`).

## Dynamic playlist protection

`DynamicHLSPlaylist` can rewrite playlist URLs (keys/media/playlists) so you can protect access via routes/middleware.

See README for full example using `FFMpeg::dynamicHLSPlaylist()`.

## Process output inspection

```php
$processOutput = FFMpeg::open('video.mp4')
    ->export()
    ->addFilter(['-filter:a', 'volumedetect', '-f', 'null'])
    ->getProcessOutput();

$processOutput->all();
$processOutput->errors();
$processOutput->out();
```

## Advanced: underlying driver access

The opener proxies unknown methods to the underlying PHP-FFMpeg media object.

Invoke the opener to get the underlying instance:

```php
$media = FFMpeg::fromDisk('videos')->open('video.mp4');
$base = $media(); // FFMpeg\Media\MediaTypeInterface
```

## Common pitfalls / gotchas

- **FFmpeg availability:** failures often trace back to missing binaries or permissions.
- **Chaining semantics:** `save()` returning a fresh opener enables chaining; don’t break this.
- **Remote disks:** temp files are involved; cleanup/configuration matters.
- **HLS constraints:** segment length/keyframe interval have lower bounds in newer versions (see README Upgrading notes).

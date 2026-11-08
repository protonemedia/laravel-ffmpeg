---
name: laravel-ffmpeg-development
description: Build and work with pbmedia/laravel-ffmpeg features including audio/video conversion, HLS adaptive streaming, frame extraction, watermarking, tile generation, and progress monitoring through Laravel's filesystem integration.
license: MIT
metadata:
  author: Protone Media
---

# Laravel FFMpeg Development

## Overview
Use pbmedia/laravel-ffmpeg to process audio and video files in Laravel. Supports format conversion, HLS adaptive bitrate streaming, frame extraction, watermarks, tile/sprite generation, concatenation, and progress monitoring — all integrated with Laravel's filesystem disks.

## When to Activate
- Activate when working with video/audio conversion, transcoding, or format changes in Laravel.
- Activate when code references the `FFMpeg` facade, `MediaOpener`, `MediaExporter`, `HLSExporter`, or related classes from `ProtoneMedia\LaravelFFMpeg`.
- Activate when the user wants to create HLS streams, extract frames, add watermarks, concatenate media, or generate tile/sprite previews.

## Scope
- In scope: opening media files, format conversion, HLS exports, frame extraction, watermarking, tile generation, concatenation, filters, progress monitoring, dynamic HLS playlists.
- Out of scope: direct FFmpeg CLI usage without the package, non-Laravel PHP projects, image-only manipulation without video/audio.

## Workflow
1. Identify the task (format conversion, HLS export, frame extraction, watermarking, etc.).
2. Read `references/laravel-ffmpeg-guide.md` and focus on the relevant section.
3. Apply the patterns from the reference, keeping code minimal and Laravel-native.

## Core Concepts

### Opening Media
Use the `FFMpeg` facade to open files from any Laravel filesystem disk:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::fromDisk('local')
    ->open('video.mp4');

FFMpeg::fromDisk('s3')
    ->open('videos/intro.mp4');

FFMpeg::openUrl('https://example.com/video.mp4');
```

### Format Conversion
```php
use FFMpeg\Format\Video\X264;

FFMpeg::fromDisk('uploads')
    ->open('input.mp4')
    ->export()
    ->toDisk('converted')
    ->inFormat(new X264)
    ->save('output.mp4');
```

### HLS Adaptive Streaming
```php
use FFMpeg\Format\Video\X264;

FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->exportForHLS()
    ->setSegmentLength(10)
    ->setKeyFrameInterval(48)
    ->addFormat((new X264)->setKiloBitrate(1000))
    ->addFormat((new X264)->setKiloBitrate(500))
    ->save('adaptive.m3u8');
```

### Frame Extraction
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->getFrameFromSeconds(10)
    ->export()
    ->toDisk('thumbnails')
    ->save('thumb.png');
```

### Watermarking
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->export()
    ->addWatermark(function ($watermark) {
        $watermark->fromDisk('local')
            ->open('logo.png')
            ->right(10)
            ->bottom(10);
    })
    ->inFormat(new X264)
    ->save('watermarked.mp4');
```

## Do and Don't

Do:
- Always call `->save()` to finalize the export — without it, no output is generated.
- Use `->inFormat()` to specify the output codec when converting.
- Use `->fromDisk()` and `->toDisk()` to work with Laravel filesystem disks.
- Use `->setSegmentLength()` and `->setKeyFrameInterval()` when creating HLS exports.
- Use `->onProgress()` to monitor long-running conversions.
- Call `FFMpeg::cleanupTemporaryFiles()` after processing to free disk space.

Don't:
- Don't forget to publish the config with `php artisan vendor:publish --provider="ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider"`.
- Don't omit `->inFormat()` when exporting — the exporter needs a format to transcode.
- Don't use `->concatWithoutTranscoding()` when input files have different codecs or resolutions — use `->concatWithTranscoding()` instead.
- Don't set segment length or key frame interval below 2 for HLS exports.

## References
- `references/laravel-ffmpeg-guide.md`

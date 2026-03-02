---
name: laravel-ffmpeg-development
description: Build and work with pbmedia/laravel-ffmpeg features including converting audio/video files, creating HLS streams with optional encryption, extracting frames and tiles, adding watermarks, concatenating media, and integrating with Laravel's Filesystem.
license: MIT
metadata:
  author: Protone Media
---

# Laravel FFMpeg Development

## Overview
Use pbmedia/laravel-ffmpeg to convert audio and video files within Laravel. Supports HLS streaming with AES-128 encryption, watermarks, frame/tile exports, concatenation, multiple inputs/outputs, and integration with Laravel's Filesystem disks.

## When to Activate
- Activate when working with audio or video conversion, transcoding, or processing in Laravel.
- Activate when code references the `FFMpeg` facade, `MediaOpener`, `MediaExporter`, `HLSExporter`, `WatermarkFactory`, `TileFactory`, or the `ProtoneMedia\LaravelFFMpeg` namespace.
- Activate when the user wants to create HLS playlists, extract frames, add watermarks, concatenate media files, or generate tiles/sprites.

## Scope
- In scope: media conversion, format exports, HLS with encryption, frame extraction, tile/sprite generation, watermarks, concatenation, progress monitoring, process output analysis.
- Out of scope: general file storage without FFmpeg processing, non-Laravel frameworks, direct PHP-FFMpeg usage without this package.

## Workflow
1. Identify the task (conversion, HLS export, frame extraction, watermark, concatenation, etc.).
2. Read `references/laravel-ffmpeg-guide.md` and focus on the relevant section.
3. Apply the patterns from the reference, keeping code minimal and Laravel-native.

## Core Concepts

### Basic Conversion
Open a file from a disk, export to a format, and save:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::fromDisk('songs')
    ->open('yesterday.mp3')
    ->export()
    ->toDisk('converted_songs')
    ->inFormat(new \FFMpeg\Format\Audio\Aac)
    ->save('yesterday.aac');
```

### Frame Extraction
```php
FFMpeg::open('video.mp4')
    ->getFrameFromSeconds(10)
    ->export()
    ->toDisk('thumbnails')
    ->save('frame.png');
```

### HLS Export
```php
$lowBitrate = (new X264)->setKiloBitrate(250);
$highBitrate = (new X264)->setKiloBitrate(1000);

FFMpeg::open('video.mp4')
    ->exportForHLS()
    ->setSegmentLength(10)
    ->addFormat($lowBitrate)
    ->addFormat($highBitrate)
    ->save('playlist.m3u8');
```

### Watermarks
```php
use ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFactory;

FFMpeg::open('video.mp4')
    ->addWatermark(function (WatermarkFactory $watermark) {
        $watermark->fromDisk('local')
            ->open('logo.png')
            ->right(25)
            ->bottom(25);
    });
```

### Tiles and Sprites
```php
use ProtoneMedia\LaravelFFMpeg\Filters\TileFactory;

FFMpeg::open('video.mp4')
    ->exportTile(function (TileFactory $factory) {
        $factory->interval(5)
            ->scale(160, 90)
            ->grid(3, 5)
            ->generateVTT('thumbnails.vtt');
    })
    ->save('tile_%05d.jpg');
```

## Do and Don't

Do:
- Always call `->export()` before `->inFormat()` and `->save()`.
- Use `->fromDisk()` and `->toDisk()` to work with Laravel filesystem disks.
- Use `->onProgress()` on the exporter for progress monitoring.
- Use `CopyFormat` when you want to change the container without re-encoding.
- Use `->addFormat()` with a callback to apply per-format filters in HLS exports.
- Call `FFMpeg::cleanupTemporaryFiles()` after processing remote disk files.
- Store HLS encryption keys securely and use `DynamicHLSPlaylist` for protected playback.

Don't:
- Don't forget to call `->save()` to finalize the export.
- Don't use `addFilter` for HLS when migrating from v6 or earlier — use `addLegacyFilter` and verify.
- Don't set HLS segment length or keyframe interval below `2`.
- Don't skip publishing the config file if you need to customize binary paths or temporary file locations.
- Don't pass filters directly to the format object for progress monitoring — use `->onProgress()` on the exporter instead.

## References
- `references/laravel-ffmpeg-guide.md`

---
name: laravel-ffmpeg-development
description: Build and work with protonemedia/laravel-ffmpeg features including transcoding audio/video, exporting HLS streams, extracting frames, applying filters and watermarks, and working with Laravel filesystem disks.
license: MIT
metadata:
  author: ProtoneMedia
---

# Laravel FFMpeg Development

## Overview
Use protonemedia/laravel-ffmpeg to process audio and video in Laravel. Supports transcoding, HLS adaptive streaming, frame extraction, watermarks, concatenation, and more — all routed through Laravel filesystem disks.

## When to Activate
- Activate when working with audio/video transcoding, HLS exports, or media processing in Laravel.
- Activate when code references the `FFMpeg` facade, `ProtoneMedia\LaravelFFMpeg` classes, or the `laravel-ffmpeg` config.
- Activate when the user wants to convert, stream, extract frames from, or inspect audio/video files.

## Scope
- In scope: transcoding, HLS exports, filters, watermarks, frame extraction, concatenation, progress monitoring, error handling, configuration.
- Out of scope: modifying this package’s internal source code unless the user explicitly says they are contributing to the package.

## Workflow
1. Identify the task (transcoding, HLS export, frame extraction, filters, etc.).
2. Read `references/laravel-ffmpeg-guide.md` and focus on the relevant section.
3. Apply the patterns from the reference, keeping code minimal and Laravel-native.

## Core Concepts

### Basic Transcoding
Open a file from a Laravel disk, export to a format, and save:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use FFMpeg\Format\Video\X264;

FFMpeg::fromDisk(‘videos’)
    ->open(‘input.mp4’)
    ->export()
    ->toDisk(‘converted’)
    ->inFormat(new X264)
    ->save(‘output.mp4’);
```

### HLS Adaptive Streaming
```php
$low = (new X264)->setKiloBitrate(250);
$high = (new X264)->setKiloBitrate(1000);

FFMpeg::open(‘input.mp4’)
    ->exportForHLS()
    ->addFormat($low)
    ->addFormat($high)
    ->save(‘stream.m3u8’);
```

### Frame Extraction
```php
FFMpeg::open(‘input.mp4’)
    ->getFrameFromSeconds(10)
    ->export()
    ->toDisk(‘thumbnails’)
    ->save(‘thumb.png’);
```

## Do and Don’t

Do:
- Always call `->inFormat()` before `->save()` when transcoding.
- Use `->fromDisk()` / `->toDisk()` to route I/O through Laravel filesystem disks.
- Use `->onProgress()` for long-running exports to track progress.
- Catch `EncodingException` to inspect the FFmpeg command and error output.
- Call `FFMpeg::cleanupTemporaryFiles()` after working with remote disks.

Don’t:
- Don’t forget to ensure FFmpeg binaries are installed and accessible on the server.
- Don’t omit `->inFormat()` on an export — the save will fail without a target format (unless using `CopyFormat`).
- Don’t invent undocumented methods/options; stick to the docs and reference.
- Don’t hard-code file paths; use Laravel disks for input and output.

## References
- `references/laravel-ffmpeg-guide.md`

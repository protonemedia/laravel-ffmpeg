---
name: developing-laravel-ffmpeg
description: "Builds and changes media processing with pbmedia/laravel-ffmpeg v9 in Laravel: encoding, probing and validating uploads, resizing, watermarks, thumbnails, storyboards, GIFs, audio mixing, HLS and DASH streaming, protected playback, progress, queued exports, and tests. Use it to upgrade v8 code too."
license: MIT
---

# Develop with Laravel FFMpeg

Version 9 has a new API. Code written for v8 or PHP-FFMpeg does not run, so write each call from this skill or the installed source, not from memory of earlier versions.

## Workflow

1. Read the reference for the task. Every example opens its own source, so you may copy one whole.
    - [Encoding](references/encoding.md): opening and probing media, validating uploads, codecs and stream copy, clipping, resizing and filters, watermarks, tracks, speed, joining clips, and multiple outputs.
    - [Frames and previews](references/frames-and-previews.md): thumbnails, frame sequences, storyboards, GIFs, waveform images, title cards, and camera snapshots.
    - [Audio](references/audio.md): background music, mixing, loudness normalization, waveform peaks, and analysis.
    - [Streaming](references/streaming.md): HLS variants and encryption, fMP4, DASH, protected playback routes, and continuous HLS.
    - [Running exports](references/running-exports.md): results, failures, progress, events, timeouts, queued exports, job chains, and deleting outputs.
    - [Testing](references/testing.md): inspecting commands, faking FFmpeg, faking queued exports, and checking the FFmpeg build.
    - [Upgrading from v8](references/upgrading.md): dependencies, and the v9 counterpart of each v8 format, filter, frame, HLS, playlist, progress, and exception call.
2. For an option the reference does not show, read the matching `###` section of `vendor/pbmedia/laravel-ffmpeg/README.md`, then the method's docblock in `vendor/pbmedia/laravel-ffmpeg/src/`. To port a v8 call that the upgrading reference does not list, read the matching section of `vendor/pbmedia/laravel-ffmpeg/UPGRADE.md`.
3. Choose encoders that the deployment's FFmpeg build includes, such as `libx264` and `aac`. The `php artisan ffmpeg:doctor --encoder=libx264` command checks the build.

## Rules

- Open a disk-relative path with `FFMpeg::disk('uploads')->open('videos/intro.mov')`. Pass an upload or an absolute local file as an `UploadedFile` or `Illuminate\Http\File` instance, and open an HTTP source with `FFMpeg::openUrl()`. A path string is never a URL or an absolute path.
- Call `export()` before configuring a plain encode. The `frame`, `frames`, `storyboard`, `gif`, `waveform`, `still`, `speed`, `backgroundMusic`, `mixAudio`, `normalizeLoudness`, `waveformPeaks`, `concatTranscoded`, `hls`, and `dash` methods return their own builders, so call `toDisk()` and `save()` on them without `export()`. The `concat` method returns a `Media`, so it does need `export()`.
- To type-hint a helper, use the classes in the `ProtoneMedia\LaravelFFMpeg` namespace: `open()` and `concat()` return a `Media`, `export()` a `PendingExport`, `hls()` a `PendingHlsExport`, `dash()` a `PendingDashExport`, `frame()` a `PendingFrame`, `frames()` a `PendingFrames`, `storyboard()` a `PendingStoryboard`, `gif()` a `PendingGif`, `waveform()` a `PendingWaveform`, `still()` a `PendingStill`, `speed()` a `PendingSpeed`, `backgroundMusic()` and `mixAudio()` a `PendingAudioMix`, `normalizeLoudness()` a `PendingLoudness`, `waveformPeaks()` a `PendingWaveformPeaks`, `concatTranscoded()` a `PendingConcat`, and `queued()` a `PendingQueuedExport`.
- Choose codecs with `video()` and `audio()`. A bitrate is an integer in bits per second or a unit string such as `'128k'`, and `crf` and `bitrate` exclude each other.
- Filters need encoding. A stream passed `copy` cannot be resized, filtered, watermarked, or clipped accurately.
- The `watermark()` method takes the opened image and an optional closure that receives a `Watermark`, as in `->watermark($logo, fn (Watermark $watermark) => $watermark->right(20)->bottom(20)->size(width: 160))`. It has no position or size arguments.
- The `save()` method returns a result, such as an `ExportResult` or `HlsExportResult`, not the builder. Read the outputs from `$result->file()` and `$result->files()`.
- Queued exports read and write named disks that every worker can reach, and their callbacks are static closures that capture only scalars or arrays. The `queued()->save()` call returns Laravel's `PendingDispatch`, and the `QueuedExportCompleted` event delivers the outputs.
- Failures throw `ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException`, whose `phase`, `category`, and `diagnostics` properties explain the cause.

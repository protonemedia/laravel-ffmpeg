---
name: developing-laravel-ffmpeg
description: "Builds media processing with pbmedia/laravel-ffmpeg v9. Use for conversion, HLS, frames, watermarks, storage, progress, or queued exports in Laravel."
license: MIT
---

# Develop with Laravel FFMpeg

Use the typed v9 API in `ProtoneMedia\LaravelFFMpeg`. PHP-FFMpeg format objects and legacy exporter builders are not part of this API.

## Workflow

1. Check the installed package version and the application's filesystem disks.
2. Read [the v9 reference](references/laravel-ffmpeg-guide.md) for the operation you need.
3. Choose FFmpeg encoder names available in the deployment, such as `libx264` and `aac`.
4. Configure the pending operation, then call `save()` to execute or dispatch it. Use `inspect()` for redacted command inspection without execution.
5. Test the generated media and the destination disk. For queued work, verify that workers can access the named disks.

## Rules

- Open disk-relative keys with `FFMpeg::disk('uploads')->open('video.mp4')`. Use `Illuminate\Http\File` for an absolute local file.
- Configure encoding with `video()` and `audio()`. Bitrates are positive integers in bits per second or unit strings such as `'1500k'`.
- Use `Media::frame()`, `frames()`, `storyboard()`, and `hls()` directly. Do not add an extra `export()` after these methods.
- Pass a `Media` object to `watermark()`, not a legacy watermark callback.
- Receive a `Progress` object in `onProgress()`. Its percentage and time estimates can be null.
- Use durable named disks for queued exports. Do not capture service objects in queued callbacks.
- Let each operation clean up its temporary workspace. Published outputs remain until the application deletes them.
- Do not use `fromDisk()`, `inFormat()`, `addFormat()`, `exportForHLS()`, `getFrameFromSeconds()`, or `cleanupTemporaryFiles()` from older releases.

For operations beyond this reference, read the installed package source and its current documentation instead of adapting legacy examples by name alone.

{{-- Laravel FFMpeg package guidance. License: MIT. --}}
# Laravel FFMpeg

- `pbmedia/laravel-ffmpeg` v9 runs FFmpeg 9.0 or later through Laravel's Process, Storage, Http, and Queue features. Its API is new in v9, so code from v8 tutorials, issues, and forum answers does not run.
- Activate the `developing-laravel-ffmpeg` skill before writing or changing code that uses `ProtoneMedia\LaravelFFMpeg`, such as encoding, probing, frames, watermarks, audio mixing, HLS or DASH streaming, protected playback, or queued exports.
- Replace each v8 call with its v9 equivalent:
    - `FFMpeg::fromDisk('uploads')` becomes `FFMpeg::disk('uploads')`.
    - `->inFormat(new X264)` becomes `->video('libx264')->audio('aac')`.
    - `->addFilter(...)` becomes a builder method such as `->resize(width: 1280)`, `->contain(1280, 720)`, `->cover(1280, 720)`, `->clip(12.5, duration: 8)`, or `->videoFilter('hqdn3d')`.
    - `->addWatermark(fn ($factory) => ...)` becomes `->watermark($logo, fn (Watermark $watermark) => $watermark->right(20)->bottom(20))`, where `$logo` is an opened `Media`.
    - `->exportForHLS()->addFormat(...)` becomes `->hls()->variant('720p', fn (HlsVariant $variant) => ...)`.
    - `->getFrameFromSeconds(10)->export()->save('poster.jpg')` becomes `->frame(at: 10)->save('poster.jpg')`.
    - `->getDurationInSeconds()` becomes `->probe()->duration()`.
    - `->concatWithoutTranscoding()` and `->concatWithTranscoding()` become `$first->concat($second)` and `$first->concatTranscoded($second)`.
    - `FFMpeg::dynamicHLSPlaylist()` becomes `FFMpeg::disk('streams')->playlist($path)`, or the `Hls` facade's signed playback routes.
    - `->onProgress(fn ($percentage) => ...)` becomes `->onProgress(fn (Progress $progress) => ...)`.
    - `EncodingException` becomes `MediaException`, and `FFMpeg::cleanupTemporaryFiles()` has no replacement because every operation removes its own workspace.

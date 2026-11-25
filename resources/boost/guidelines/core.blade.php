{{-- Laravel FFMpeg package guidance. License: MIT. --}}
# Laravel FFMpeg

- `pbmedia/laravel-ffmpeg` v9 runs FFmpeg 9.0 or later through Laravel's Process, Storage, Http, and Queue features. Its API is new in v9, so code from v8 tutorials, issues, and forum answers does not run.
- Activate the `developing-laravel-ffmpeg` skill before writing or changing code that uses `ProtoneMedia\LaravelFFMpeg`, such as encoding, probing, frames, watermarks, audio mixing, HLS or DASH streaming, protected playback, or queued exports. To upgrade v8 code, read its `references/upgrading.md` file, which maps every v8 call.
- Upgrade with `composer require pbmedia/laravel-ffmpeg:^9.0`, which needs PHP ^8.4 and `laravel/framework` ^13.44.1. Composer removes `php-ffmpeg/php-ffmpeg`, so delete every `FFMpeg\...` import. Watermark image edits need `intervention/image:^4.2` instead of `spatie/image`. Republish the config with `php artisan vendor:publish --provider="ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider" --tag=config --force`, and check the binaries with `php artisan ffmpeg:doctor`.
- Replace each v8 call with its v9 equivalent:
    - `FFMpeg::fromDisk('uploads')` becomes `FFMpeg::disk('uploads')`.
    - `->inFormat(new X264)` encoded two passes at 1000 kb/s video and 128 kb/s audio, so it becomes `->video('libx264', bitrate: '1000k')->audio('aac', bitrate: '128k')->twoPass()`. A plain `->video('libx264')->audio('aac')` encodes one pass at constant quality.
    - `->addFilter(...)` becomes a builder method such as `->resize(width: 1280)`, `->contain(1280, 720)`, `->cover(1280, 720)`, `->clip(12.5, duration: 8)`, or `->videoFilter('hqdn3d')`.
    - `->addWatermark(fn ($factory) => ...)` becomes `->watermark($logo, fn (Watermark $watermark) => $watermark->right(20)->bottom(20))`, where `$logo` is an opened `Media`.
    - `->exportForHLS()->addFormat(...)` becomes `->hls()->variant('720p', fn (HlsVariant $variant) => ...)`.
    - `->getFrameFromSeconds(10)->export()->save('poster.jpg')` becomes `->frame(at: 10)->save('poster.jpg')`.
    - `->getDurationInSeconds()` becomes `->probe()->duration()`, which returns a `?float`, so `(int) round($duration)` gives v8's whole seconds.
    - `->concatWithoutTranscoding()` and `->concatWithTranscoding()` become `$first->concat($second)` and `$first->concatTranscoded($second)`.
    - `FFMpeg::dynamicHLSPlaylist()` becomes `FFMpeg::disk('streams')->playlist($path)`, or the `Hls` facade's signed playback routes.
    - `->onProgress(fn ($percentage, $remaining, $rate) => ...)` becomes `->onProgress(fn (Progress $progress) => ...)` with the `percentage`, `remainingSeconds`, and `speed` properties, where `speed` is a multiple of real time. Each is a `?float`, such as a percentage of `27.09333`, and with `twoPass()` the percentage runs from 0 to 100 and `remainingSeconds` restarts once per pass.
    - `EncodingException` becomes `MediaException`, and `FFMpeg::cleanupTemporaryFiles()` has no replacement because every operation removes its own workspace.

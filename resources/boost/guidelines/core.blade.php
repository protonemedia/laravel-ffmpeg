{{-- Laravel FFMpeg package guidance. License: MIT. --}}
# Laravel FFMpeg

- `pbmedia/laravel-ffmpeg` v9 uses typed media and pending exports, not PHP-FFMpeg format objects or legacy exporters.
- Activate the `developing-laravel-ffmpeg` skill for media conversion, HLS, frame extraction, watermarks, queued exports, or code that uses `ProtoneMedia\LaravelFFMpeg`.
- Use `FFMpeg::disk(...)->open(...)`, `export()->video(...)->audio(...)`, and `hls()->variant(...)`. Do not generate legacy `fromDisk()`, `inFormat()`, or `exportForHLS()` calls.

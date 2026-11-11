# Upgrade Guide

- [Upgrading To 9.0 From 8.x](#upgrade-9.0)

<a name="upgrade-9.0"></a>
## Upgrading To 9.0 From 8.x

> [!WARNING]
> This guide describes the `9.x` development branch, not a published release.

Version 9 replaces PHP-FFMpeg with Laravel's Process, Storage, and Http components. The old builders, format objects, driver forwarding, and filter factories have been removed. This is a breaking upgrade, not a compatibility layer, so you should review each section below that applies to your application.

#### High Impact Changes

- [Updating Dependencies](#updating-dependencies)
- [Opening Media](#opening-media)
- [Format Objects](#format-objects)
- [Driver Forwarding and Raw Options](#driver-forwarding-and-raw-options)
- [Filters and Clipping](#filters-and-clipping)
- [HLS Exports](#hls-exports)
- [HLS Playlists and Protected Playback](#hls-playlists-and-protected-playback)

#### Medium Impact Changes

- [Configuration](#configuration)
- [Probing and Metadata](#probing-and-metadata)
- [Watermarks](#watermarks)
- [Concatenation](#concatenation)
- [Stream Selection and Output Metadata](#stream-selection-and-output-metadata)
- [Frames, Storyboards, and Analysis](#frames-storyboards-and-analysis)
- [Progress Callbacks and Events](#progress-callbacks-and-events)
- [Exceptions and Publication](#exceptions-and-publication)

#### Low Impact Changes

- [Audio Mixing](#audio-mixing)
- [Playback Speed and GIFs](#playback-speed-and-gifs)
- [Temporary Files and Deleting Outputs](#temporary-files-and-deleting-outputs)
- [Builder and Result Types](#builder-and-result-types)
- [Queued Exports](#queued-exports)

<a name="updating-dependencies"></a>
### Updating Dependencies

**Likelihood Of Impact: High**

Version 9 requires PHP 8.4 or later and Laravel 13.20 or later. You should update the following dependencies in your application's `composer.json` file:

- `php` to `^8.4`
- `laravel/framework` to `^13.20`
- `pbmedia/laravel-ffmpeg` to `9.x-dev`

```shell
composer require pbmedia/laravel-ffmpeg:9.x-dev
```

You should also install matching FFmpeg and FFprobe 9.0.x binaries. The tested patch release is 9.0.2. Install the codecs, filters, fonts, and HTTPS support that your application needs.

<a name="configuration"></a>
### Configuration

**Likelihood Of Impact: Medium**

You should republish the configuration file and review your application's values:

```shell
php artisan vendor:publish --provider="ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider" --tag=config --force
```

The `ffmpeg.binaries`, `ffprobe.binaries`, `ffmpeg.threads`, `timeout`, and `temporary_files_root` options remain. The new `idle_timeout` option is separate from the total `timeout` deadline. The legacy `log_channel` option and the encrypted-HLS temporary root, `temporary_files_encrypted_hls`, no longer apply, so you may remove them:

```php
// config/laravel-ffmpeg.php
return [
    'timeout' => 3600,

    // New in 9.x: how many seconds a process may run without output.
    'idle_timeout' => null,

    // New in 9.x: redact credentials from diagnostics and process summaries.
    'redact_credentials' => env('FFMPEG_REDACT_CREDENTIALS', false),

    // Removed in 9.x: 'log_channel' and 'temporary_files_encrypted_hls'.
];
```

You should set `FFMPEG_BINARIES` and `FFPROBE_BINARIES` to the matching pair. Set `FFMPEG_REDACT_CREDENTIALS=true` to redact signed URLs and credentials from diagnostics and process summaries.

<a name="opening-media"></a>
### Opening Media

**Likelihood Of Impact: High**

The `fromDisk()` and `fromFilesystem()` methods have been replaced by `disk()`. You should call `disk('uploads')` to select a Storage disk, or `disk($filesystem)` to use a filesystem instance you supply. `FFMpeg::open($key)` opens a disk-relative key on the current `filesystems.default` disk. When you pass a Laravel `File` or `UploadedFile` directly to `FFMpeg::open()`, it reads that local file instead of a Storage key.

The `openUrl()` method still accepts request headers, but headers now require `download: true`:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// $filesystem is an Illuminate\Contracts\Filesystem\Filesystem instance,
// $request is the current request, and $url and $token are your own values.

// Version 8.x
$media = FFMpeg::fromDisk('uploads')
    ->open('lesson.mov');

$media = FFMpeg::fromFilesystem($filesystem)
    ->open('lesson.mov');

$private = FFMpeg::openUrl($url, ['Authorization' => 'Bearer '.$token]);

// Version 9.x
$media = FFMpeg::disk('uploads')
    ->open('lesson.mov');

$media = FFMpeg::disk($filesystem)
    ->open('lesson.mov');

$media = FFMpeg::open($request->file('video'));

$public = FFMpeg::openUrl('https://cdn.example.com/lesson.mp4');

$private = FFMpeg::openUrl($url, headers: ['Authorization' => 'Bearer '.$token], download: true);
```

`FFMpeg::openUrl()` uses the same HTTP rules as the disk selector. Downloads use Laravel's Http client with TLS verification and reject redirects. Direct URLs enter the media process arguments, so you should not include reusable credentials in them. See [credential redaction](README.md#credential-redaction) for the redaction rules.

Version 9 can also capture snapshots from RTSP cameras and pass input-scoped options, such as hardware decoding flags, to `open()`:

```php
// $cameraUrl is a trusted RTSP URL from your application's configuration.
$camera = FFMpeg::openRtsp($cameraUrl, transport: 'tcp', timeout: 10);

$camera->frame()
    ->toDisk('images')
    ->save('snapshot.jpg');

$media = FFMpeg::disk('uploads')
    ->open('lesson.mov', inputOptions: ['-hwaccel', 'cuda', '-hwaccel_device', '0', '-hwaccel_output_format', 'cuda']);
```

`openRtsp()` returns an `RtspCamera` instance, not a `Media` descriptor for live exports. Its `frame()` method captures a private PNG and then uses the ordinary frame pipeline. The transport is `tcp` or `udp`, and the timeout must be positive and at most 300 seconds. Actual TCP and UDP fixtures pass, but compatibility with arbitrary cameras is not claimed. Camera URLs must be trusted configuration. You should restrict network access and protect the credentials that are visible in process arguments.

Hardware decoding flags passed as validated pairs through `inputOptions:` are input-scoped and are not passed to FFprobe. They do not select an output encoder, generate transfer filters, or provide a CPU fallback. Hardware execution has not been validated here, so you should test your deployed build and GPU separately. See [hardware acceleration](README.md#hardware-acceleration).

<a name="format-objects"></a>
### Format Objects

**Likelihood Of Impact: High**

PHP-FFMpeg format objects such as `X264` are no longer accepted, and the `inFormat()` method has been removed. Instead, you should choose the encoders with the `video()` and `audio()` methods:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// Version 8.x
FFMpeg::fromDisk('uploads')
    ->open('lesson.mov')
    ->export()
    ->toDisk('videos')
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->save('lesson.mp4');

// Version 9.x
$media = FFMpeg::disk('uploads')
    ->open('lesson.mov');

$result = $media->export()
    ->video('libx264', crf: 23)
    ->audio('aac', bitrate: '128k')
    ->toDisk('videos')
    ->save('lesson.mp4');
```

Bitrates accept positive integers in bits per second, such as `2500000`, or unit strings, such as `'2500k'`. Floats and unitless strings are invalid. The `save()` method returns a result, not the builder, so you may no longer chain another export after it.

PHP-FFMpeg's `Vorbis` format used FFmpeg's native `vorbis` encoder, which is experimental and only encodes stereo. You should call `audio('vorbis')` with `audioOptions(['-strict', 'experimental', '-ac', '2'])`, or use `libvorbis` if your FFmpeg build includes it.

<a name="driver-forwarding-and-raw-options"></a>
### Driver Forwarding and Raw Options

**Likelihood Of Impact: High**

Builders no longer forward method calls to PHP-FFMpeg, and there is no arbitrary before-save command mutation. You should use explicit codecs, scoped `inputOptions:`, `globalOptions()`, `outputOptions()`, and filters instead. The `inspect()` method returns the argument plan without reading inputs or launching a process:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// Version 8.x
$command = FFMpeg::open('lesson.mp4')
    ->export()
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->addFilter(['-movflags', '+faststart'])
    ->beforeSaving(fn (array $commands) => [...$commands, '-tune', 'film'])
    ->getCommand('lesson-web.mp4');

// Version 9.x
$export = FFMpeg::open('lesson.mp4')
    ->export()
    ->video('libx264', bitrate: '2500k')
    ->globalOptions(['-filter_threads', '2'])
    ->outputOptions(['-movflags', '+faststart', '-tune', 'film']);

$arguments = $export->inspect();

$result = $export->save('lesson-web.mp4');
```

<a name="probing-and-metadata"></a>
### Probing and Metadata

**Likelihood Of Impact: Medium**

Because driver forwarding has been removed, methods such as `getDurationInSeconds()` and `getVideoStream()` are no longer available. You should call `probe()` and read the returned `Metadata` instance instead:

```php
// $media is a Media instance from FFMpeg::open() or FFMpeg::disk()->open().

// Version 8.x
$seconds = $media->getDurationInSeconds();

$codec = $media->getVideoStream()
    ->get('codec_name');

// Version 9.x
$metadata = $media->probe();

$seconds = $metadata->duration();

$codec = $metadata->video()
    ?->codec();

$width = $metadata->video()
    ?->width();

$audioStreams = $metadata->audioStreams();
$chapters = $metadata->chapters();
$data = $metadata->toArray();
```

Version 9 can also cache probe results in a cache repository that you choose:

```php
use Illuminate\Support\Facades\Cache;

// $media is a Media instance from FFMpeg::open() or FFMpeg::disk()->open().
$metadata = $media->probeCached(
    Cache::store('redis'),
    versionedKey: 'tenant:42:asset:7:object-version:abc123',
    ttl: 600,
);
```

You should use `probeCached()` only with an application-owned source-version key and cache repository, and change the key when the object changes. A cache hit does not read or authorize the source. Your application owns freshness, tenant isolation, eviction, and metadata security. `probe()` and exports still probe normally.

<a name="filters-and-clipping"></a>
### Filters and Clipping

**Likelihood Of Impact: High**

The `addFilter()` callbacks, PHP-FFMpeg filter objects, and filter factories have been removed. You should call the dedicated filter and clipping methods on the builder instead:

```php
use FFMpeg\Filters\Video\VideoFilters;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// $media is a Media instance from FFMpeg::open() or FFMpeg::disk()->open().

// Version 8.x
FFMpeg::open('lesson.mp4')
    ->addFilter(function (VideoFilters $filters) {
        $filters->resize(new \FFMpeg\Coordinate\Dimension(1280, 720));
    })
    ->addFilter(new \FFMpeg\Filters\Video\ClipFilter(
        \FFMpeg\Coordinate\TimeCode::fromSeconds(12.5),
        \FFMpeg\Coordinate\TimeCode::fromSeconds(8),
    ))
    ->export()
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->save('clip.mp4');

// Version 9.x
$media->export()
    ->video('libx264')
    ->audio('copy')
    ->resize(width: 1280)
    ->crop(1280, 720)
    ->rotate(90)
    ->save('portrait.mp4');

$media->export()
    ->video('libx264')
    ->audio('aac')
    ->clip('00:00:12.500', duration: 8)
    ->save('clip.mp4');

$media->export()
    ->video('copy')
    ->audio('copy')
    ->clip(12.5, end: 20.5, seek: 'keyframe')
    ->save('copy.mkv');
```

Ordinary filters run in registration order. All output video filters run before generated watermark overlays, even filters called after `watermark()`. A one-dimension resize preserves the display aspect ratio, while two explicit dimensions stretch. You should use `contain()` or `cover()` for an exact canvas, as in Laravel's image manipulation. Stream copy rejects filters and accurate clipping. Keyframe cuts can begin before the requested time. Timeline changes omit chapters unless they are explicitly mapped.

For portrait sources, you should use `resize(height: 1920)` to preserve the autorotated display aspect ratio, or `contain(1080, 1920)` for an exact canvas. Probe width and height remain the encoded dimensions, before display rotation.

You should use `frameRate()`, `flipHorizontally()`, `flipVertically()`, `grayscale()`, and `deinterlace()` instead of filter-factory callbacks. Deinterlace before resizing. `volume()` takes a linear multiplier, and `delayAudio()` inserts silence on all channels. `fadeIn()` and `fadeOut()` affect video and audio by default, and a fade-out requires an explicit start in seconds. You may disable either stream type with `video: false` or `audio: false`.

You should use `drawText()` and `burnSubtitles()` for staged fonts, literal text, and subtitle files. Watermark images are edited inside the `watermark()` callback. `videoFilter()` and `audioFilter()` remain available for trusted expressions.

You may use `lut3d($look)` with a `Media` descriptor for a privately staged LUT. In each `output()` callback, call `lut3d($look)->resize(...)` to configure that output independently. The helper does not share LUT processing or generate a `split`. You should keep raw `resource()` and `filterComplex()` graphs when you need to apply a LUT once before splitting it into outputs. See the [composition example](README.md#compose-multiple-inputs-and-outputs).

<a name="watermarks"></a>
### Watermarks

**Likelihood Of Impact: Medium**

The `addWatermark()` method and the `WatermarkFactory` have been replaced by the `watermark()` method on export builders, which accepts the opened image and an optional callback. Version 8 forwarded unknown `WatermarkFactory` methods, such as `width()`, `blur()`, or `greyscale()`, to Spatie Image. Version 9 edits the image with Laravel's [image manipulation](https://laravel.com/docs/13.x/images) instead, so you should install `intervention/image:^4.2`. You should open the image like any other media instead of calling `open()` inside the callback:

```php
use ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFactory;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Watermark;

// $media is a Media instance from FFMpeg::open() or FFMpeg::disk()->open().

// Version 8.x
$media->addWatermark(function (WatermarkFactory $watermark) {
    $watermark->fromDisk('assets')
        ->open('logo.png')
        ->right(25)
        ->bottom(25)
        ->width(100)
        ->blur(10)
        ->greyscale();
});

// Version 9.x
$logo = FFMpeg::disk('assets')
    ->open('logo.png');

$media->export()
    ->watermark($logo, fn (Watermark $watermark) => $watermark
        ->right(25)
        ->bottom(25)
        ->size(width: 100)
        ->blur(10)
        ->grayscale())
    ->video('libx264')
    ->save('branded.mp4');
```

The `Watermark` instance offers Laravel's edit methods with the same names and arguments: `grayscale()`, `blur()`, `sharpen()`, `rotate()`, `crop()`, `flipHorizontally()`, and `flipVertically()`. You should replace Spatie's `width()` and `height()` with `size()`, which FFmpeg applies in output pixels after the edits.

`watermark()` adds the inputs and maps automatically for PNG, JPEG, or GIF images. Its optional callback receives a `Watermark` instance and runs immediately. The mark defaults to the bottom-right corner, at its original size and fully opaque. `left()`, `right()`, `top()`, and `bottom()` take a margin from that edge, and `center()` centers every axis that no edge method pins. Marks run after all output video filters, regardless of call order:

```php
// $media is a Media instance from FFMpeg::open() or FFMpeg::disk()->open().
// $logo is the watermark image opened in the previous example.
$media->export()
    ->watermark($logo, fn (Watermark $watermark) => $watermark
        ->right(20)
        ->bottom(15)
        ->size(width: 160))
    ->resize(width: 1280)
    ->video('libx264')
    ->audio('copy')
    ->save('branded.mp4');
```

Repeated marks, multiple ordinary outputs, and HLS variants are supported. Custom maps, graphs, additional media inputs, and video copy require another composition policy. You should use `addInput()` and `filterComplex()` for custom graphs.

<a name="concatenation"></a>
### Concatenation

**Likelihood Of Impact: Medium**

The `concatWithoutTranscoding()` and `concatWithTranscoding()` methods have been replaced by `concat()` and `concatTranscoded()` on the first `Media` instance:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// Version 8.x
FFMpeg::fromDisk('uploads')
    ->open(['lesson.mp4', 'second.mp4'])
    ->export()
    ->concatWithoutTranscoding()
    ->save('joined.mkv');

FFMpeg::fromDisk('uploads')
    ->open(['lesson.mp4', 'second.mp4'])
    ->export()
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->concatWithTranscoding($hasVideo = true, $hasAudio = true)
    ->save('joined.mp4');

// Version 9.x
$media = FFMpeg::disk('uploads')
    ->open('lesson.mp4');

$second = FFMpeg::disk('uploads')
    ->open('second.mp4');

$media->concat($second)
    ->export()
    ->video('copy')
    ->audio('copy')
    ->save('joined.mkv');

$media->concatTranscoded($second)
    ->conformVideo(1280, 720, frameRate: 30, fit: 'contain')
    ->conformAudio(sampleRate: 48000, channels: 2)
    ->video('libx264')
    ->audio('aac')
    ->save('joined.mp4');
```

`concat()` joins compatible streams without silently transcoding. `concatTranscoded()` returns an export builder with one output, and you should choose `conformVideo()` and either `conformAudio()` or `withoutAudio()` before saving. Transcoded concatenation selects each input's first motion-video and audio streams, preserves audio delays, and requires finite sources with zero container and video origins. It rejects root filters, custom graphs, and stream copy. Sources without audio require `withoutAudio()` or a custom graph, since the helper does not create missing audio tracks. Source chapters are not combined.

<a name="audio-mixing"></a>
### Audio Mixing

**Likelihood Of Impact: Low**

If you mixed audio with a custom `amix` graph and output mapping, you should use the `backgroundMusic()` or `mixAudio()` methods instead:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// Version 8.x
FFMpeg::fromDisk('local')
    ->open(['lesson.mp4', 'background.mp3'])
    ->export()
    ->addFilter('[0:a][1:a]', 'amix=inputs=2', '[a]')
    ->addFormatOutputMapping(
        new \FFMpeg\Format\Video\X264,
        \ProtoneMedia\LaravelFFMpeg\Filesystem\Media::make('local', 'with-music.mp4'),
        ['0:v', '[a]'],
    )
    ->save();

// Version 9.x
$media = FFMpeg::disk('local')
    ->open('lesson.mp4');

$music = FFMpeg::disk('music')
    ->open('background.mp3');

$media->backgroundMusic($music, volume: 0.2)
    ->at(2.5)
    ->video('copy')
    ->audio('aac')
    ->save('with-music.mp4');

$media->mixAudio($music, volume: 0.4, originalVolume: 0.8, normalize: true)
    ->fadeIn(1, video: false)
    ->video('copy')
    ->audio('aac')
    ->save('mixed.mp4');
```

`backgroundMusic()` loops by default. You may pass `loop: false` for one playback, or use `mixAudio()` for one-shot mixing with explicit gains. Call `loop()` on the returned builder to repeat the added track. The primary container's duration sets the output length: short nonlooping audio ends in silence, and longer audio is trimmed. Silent video and audio-only primaries are supported.

You may use `at(float $seconds)` to start only the added audio later. It accepts finite nonnegative offsets, rounded to the nearest 48 kHz sample. Later calls replace the offset, and `at(0)` restores the default. The original timing and gains do not change. A loop repeats the added samples after the initial delay without repeating that delay. Offsets at or beyond the primary duration add no audio and do not extend the output.

Mixing selects the first audio track from each input and the primary's first motion-video track when present. Output audio is stereo at 48000 Hz and must be encoded. Video copy is allowed without video filters. Audio filters and `volume()` run after mixing. Added audio starts at its first decoded sample after any `at()` delay, and the original audio retains its offset relative to the video.

The default `normalize: false` adds the weighted signals and can clip. With original audio present, `normalize: true` divides both padded branches equally by two, without changing the gain when a track ends. This is not loudness normalization.

Both inputs require finite positive durations, and the added input requires audio. Video primaries require known zero container and video origins and a known nonnegative original-audio origin. Looping URL audio requires `openUrl($url, download: true)`. The builder supports one output and rejects maps, custom graphs, watermarks, clips, and two-pass encoding, so you should save a clip first if needed. See [audio mixing](README.md#mix-audio-and-add-background-music) for more examples.

<a name="playback-speed-and-gifs"></a>
### Playback Speed and GIFs

**Likelihood Of Impact: Low**

If you changed playback speed with raw filter arguments, or built GIFs with your own palette graph, you should use the `speed()` and `gif()` methods instead:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// Version 8.x
FFMpeg::open('lesson.mp4')
    ->export()
    ->addFilter(['-filter:v', 'setpts=0.5*PTS', '-filter:a', 'atempo=2'])
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->save('faster.mp4');

// Version 9.x
$media = FFMpeg::open('lesson.mp4');

$media->speed(2)
    ->video('libx264')
    ->audio('aac')
    ->save('faster.mp4');

$media->gif(width: 480, frameRate: 12, loop: true)
    ->clip(4, duration: 3)
    ->save('preview.gif');
```

`speed()` retimes the video and preserves the audio pitch. It requires a finite positive factor, zero container and video origins, and nonnegative audio origins. It rejects clipping, input options, custom graphs, watermark inputs, multiple outputs, and stream copy. To retime a clip, you should save that clip first.

`gif()` owns the palette graph, maps, and encoder, and it produces no audio. Accurate clipping happens before palette generation. A private lossless intermediate avoids buffering the entire clip in memory, at the cost of disk space and additional processing passes, so you should keep clips short and dimensions small. Custom maps, graphs, additional inputs or outputs, audio settings, and encoder overrides are rejected.

<a name="stream-selection-and-output-metadata"></a>
### Stream Selection and Output Metadata

**Likelihood Of Impact: Medium**

The `addFormatOutputMapping()` method has been removed. You should select streams with `map()`, `mapVideo()`, and `mapAudio()`, and choose the metadata and chapter policy explicitly:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// Version 8.x
FFMpeg::fromDisk('local')
    ->open(['video.mp4', 'audio.m4a'])
    ->export()
    ->addFormatOutputMapping(
        new \FFMpeg\Format\Video\X264,
        \ProtoneMedia\LaravelFFMpeg\Filesystem\Media::make('local', 'new_video.mp4'),
        ['0:v', '1:a'],
    )
    ->save();

// Version 9.x
$export = FFMpeg::disk('local')
    ->open('video.mp4')
    ->export();

$audio = $export->addInput(FFMpeg::disk('local')
    ->open('audio.m4a'));

$export->map('0:v:0')
    ->map("{$audio}:a:0")
    ->metadataFrom(0)
    ->chaptersFrom(0)
    ->video('libx264')
    ->audio('aac')
    ->save('new_video.mp4');
```

Without maps, ordinary exports select the first motion-video and audio streams. Existing subtitles and cover art require explicit maps and container-compatible codecs; the `coverArt()` attachment helper below owns its maps instead. General multiple-input outputs require explicit maps and a metadata and chapter policy. Stream tags and dispositions address the output order, not input indexes:

```php
// $media is a Media instance from FFMpeg::open() or FFMpeg::disk()->open().
$media->export()
    ->mapVideo()
    ->mapAudio(language: 'eng')
    ->video('copy')
    ->audio('copy')
    ->withoutMetadata(chapters: true)
    ->streamMetadata('a:0', 'language', 'eng')
    ->disposition('a:0', ['default'])
    ->save('english.mkv');

$media->export()
    ->coverArt(FFMpeg::disk('assets')
        ->open('cover.png'))
    ->save('album.mp4');

$media->export()
    ->map('0:v:0')
    ->map('0:v:0')
    ->map('0:a:0')
    ->audio('copy')
    ->videoStream(0, fn ($track) => $track->codec('libx264')
        ->crf(20)
        ->preset('medium'))
    ->videoStream(1, fn ($track) => $track->codec('mpeg4')
        ->bitrate('800k'))
    ->save('tracks.mp4');

$media->export()
    ->map('0:s:0')
    ->outputOptions(['-c:s', 'webvtt'])
    ->save('captions.vtt');
```

You should use `coverArt()` instead of a custom attached-picture graph for MP4, M4A, or MP3. It attaches one standalone PNG or JPEG and copies all motion-video and audio tracks. It refuses existing cover art, and it rejects encoding, filters, clipping, custom maps, graphs, and disposition overrides.

`videoStream($track, $configure)` uses the zero-based output video order after mapping, not input indexes. Its callback configures `codec()`, `bitrate()`, `crf()`, and `preset()` through `VideoStreamSettings`. Unspecified settings inherit the `video()` defaults. You should configure each track once; inherited bitrate and CRF conflicts are errors. Per-track overrides do not support two-pass encoding or create filter chains. The WebVTT recipe extracts a text subtitle track, not bitmap subtitles or speech.

<a name="frames-storyboards-and-analysis"></a>
### Frames, Storyboards, and Analysis

**Likelihood Of Impact: Medium**

The `getFrameFromSeconds()`, `getFrameContents()`, `exportFramesByInterval()`, `exportTile()`, and `getProcessOutput()` methods have been replaced by `frame()`, `frames()`, `storyboard()`, and `analyze()`:

```php
use ProtoneMedia\LaravelFFMpeg\Filters\TileFactory;

// $media is a Media instance from FFMpeg::open() or FFMpeg::disk()->open().

// Version 8.x
$jpeg = $media->getFrameFromSeconds(12.5)
    ->export()
    ->getFrameContents();

$media->exportFramesByInterval(2, 320)
    ->save('frames/%05d.jpg');

$media->exportTile(fn (TileFactory $factory) => $factory
    ->interval(5)
    ->scale(160)
    ->grid(4, 3)
    ->margin(2)
    ->padding(1)
    ->generateVTT('previews/lesson.vtt'))
    ->save('sheets/%03d.jpg');

$output = $media->export()
    ->addFilter(['-filter:a', 'volumedetect', '-f', 'null'])
    ->getProcessOutput();

// Version 9.x
$jpeg = $media->frame(at: 12.5)
    ->get();

$media->frames(every: 2)
    ->resize(width: 320)
    ->save('frames/%05d.jpg');

$media->storyboard(every: 5, columns: 4, rows: 3, margin: 2, padding: 1)
    ->resize(width: 160)
    ->vtt('previews/lesson.vtt')
    ->save('sheets/%03d.jpg');

$media->waveform()
    ->save('waveform.png');

$analysis = $media->analyze('volumedetect');
$analysis->errorOutput();
$analysis->throw();
```

The `get()` method returns raw JPEG bytes, not Base64. Frames, image sequences, and storyboards of anamorphic video are stretched to square pixels at the display aspect ratio, so PHP-FFMpeg's `fixDisplayRatio()` frame filter is no longer needed. Images of anamorphic sources may therefore be wider than in version 8, while square-pixel sources keep their dimensions. Storyboard geometry applies to each tile before assembly. `waveform()` writes an image, while `waveformPeaks()` returns versioned amplitude data. Analysis-only nonzero exits remain inspectable until `throw()` is called.

Waveform `colors` accepts a string or a nonempty list of color strings. For example, `waveform(colors: 'blue')` selects one color. A scalar string is not a delimited list. RGB and RGBA hexadecimal colors require a `#` or `0x` prefix.

You may use the new `still()` method to hold a PNG or JPEG image for a finite positive duration:

```php
FFMpeg::disk('images')
    ->open('title.png')
    ->still(duration: 5, frameRate: 25)
    ->toDisk('videos')
    ->save('title.mp4');
```

Input dimensions must be even and at least two pixels, and input options are unsupported. The frame rate is an integer from 1 to 240 and defaults to 25. The single-output builder defaults to H.264 with `yuv420p` and creates no audio. Local, staged Storage, and downloaded images are supported. The output duration can round to frame boundaries.

<a name="hls-exports"></a>
### HLS Exports

**Likelihood Of Impact: High**

The `exportForHLS()` builder, its format objects, and its encryption methods have been replaced by `hls()`, `variant()`, and `encrypt()`:

```php
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\HlsVariant;

// $media is a Media instance from FFMpeg::open() or FFMpeg::disk()->open().

// Version 8.x
$media->exportForHLS()
    ->setSegmentLength(6)
    ->withRotatingEncryptionKey(fn ($filename, $contents) => Storage::disk('secrets')
        ->put($filename, $contents), 3)
    ->addFormat((new \FFMpeg\Format\Video\X264)->setKiloBitrate(800), function ($media) {
        $media->scale(640, 360);
    })
    ->toDisk('streams')
    ->save('lesson/master.m3u8');

// Version 9.x
$result = $media->hls(segmentDuration: 6)
    ->variant('360p', fn (HlsVariant $variant) => $variant
        ->resize(height: 360)
        ->video('libx264', bitrate: '800k'))
    ->encrypt('secrets', fn ($path) => route('lesson.key', ['key' => basename($path)]), rotateEvery: 3)
    ->toDisk('streams')
    ->save('lesson/master.m3u8');

$playlist = $result->playlist();

$files = $result->variant('360p')
    ->files();
```

Variants configure encoding only. MPEG-TS remains the default segment format. Private key storage is explicit, and key routes return 16 raw bytes after authorization. Keys rotate deterministically by segment group, with a random IV per segment.

You should replace the legacy `setPlaylistType()` method with `playlistType()`, passing `vod`, `event`, or `live`. The default is VOD. EVENT writes its type tag, while live omits the type tag rather than passing an unsupported FFmpeg value. All modes still finish encoding and validation before publication and retain `ENDLIST` and every segment; this is not continuous live publication. Parallel queued composition remains VOD-only, so you should use an ordinary queued export for other playlist types.

HLS exports accept watermarks, and the root result can select a subset of variants for playback:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Watermark;

// $media is a Media instance from FFMpeg::open() or FFMpeg::disk()->open().
// $lesson is your Eloquent model with an array-cast hls_playback column.
$badge = FFMpeg::disk('assets')
    ->open('badge.gif');

$animated = $media->hls()
    ->watermark($badge, fn (Watermark $watermark) => $watermark
        ->size(width: 96)
        ->loop())
    ->variant('720p', fn ($variant) => $variant->resize(height: 720)
        ->video('libx264', bitrate: '2500k'))
    ->toDisk('streams')
    ->save('animated/master.m3u8');

$lesson->hls_playback = $animated->playbackForVariants(['720p'])
    ->toArray();
```

An animated GIF plays once and then holds its final frame. You may call `loop()` on the watermark to repeat it for the whole video, whatever loop count the GIF embeds. Watermark images reject input options such as `-ignore_loop`. All ordinary filters, including variant resizing, precede overlays. `playbackForVariants()` requires nonempty, unique, known variant names and retains their required rendition, initialization, and key dependencies. Protected responses filter the master declarations without changing Storage files. You should authorize the selected inventory before issuing URLs; public disk URLs bypass this selection.

To combine completed presentations for the same timeline, see [`PendingHlsMerge`](README.md#combine-completed-hls-presentations). It writes only a fresh, version-suffixed master, not new media. Inputs need trusted v9 inventories, disjoint destinations, compatible VOD clocks and packaging, and a common media disk. Its result owns only the new master. Borrowed media stays owned by the source inventories, so you should retain application reference counts before deletion.

<a name="hls-playlists-and-protected-playback"></a>
### HLS Playlists and Protected Playback

**Likelihood Of Impact: High**

The `dynamicHLSPlaylist()` method and its URL resolvers have been replaced by `playlist()` on the disk selector, with additive URL hooks:

```php
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// Version 8.x
return FFMpeg::dynamicHLSPlaylist()
    ->fromDisk('streams')
    ->open('lesson/master.m3u8')
    ->setKeyUrlResolver(fn ($key) => route('lesson.key', ['key' => $key]))
    ->setMediaUrlResolver(fn ($filename) => Storage::disk('streams')
        ->url($filename))
    ->setPlaylistUrlResolver(fn ($filename) => route('lesson.playlist', ['playlist' => $filename]));

// Version 9.x
return FFMpeg::disk('streams')
    ->playlist('lesson/master.m3u8')
    ->mediaUrlsUsing(fn ($target, $source, $uri) => $target === null ? $uri : Storage::disk('streams')
        ->temporaryUrl($target, now()->addMinutes(10)));
```

Playlist hooks are additive and cannot replace generated references or encryption lines. URL resolvers receive the canonical target, the containing playlist, and the original URI, not just a basename.

To clean up an old same-disk presentation, you may call `playlist($path)->deleteAllFiles($approve)`. The required approval callback receives every unique canonical disk path, including the opened playlist, and must return exactly `true` before any deletion starts. You should verify exclusive ownership and prevent concurrent playlist changes in your application. URL resolvers are ignored, and external references and malformed playlists fail before deletion. Adapter failures can leave a partial deletion. You should prefer the trusted root result inventory when available, especially with private keys on another disk or borrowed merged artifacts.

For protected Laravel playback, you should replace handwritten playlist and key routes with `Support\Hls`. Persist `$result->playback()->toArray()` from the root export result in the lesson's JSON column with an `array` cast for the full presentation. A variant's `playback()` also retains its needed private-key references internally, while its `files()` still excludes shared key files. Restore the inventory with `Http\HlsPresentation::fromArray($lesson->hls_playback)` in the definition's resolver.

Register `Hls::define()` in a service provider with a mandatory authorization callback, such as `fn (Lesson $lesson) => Gate::authorize('view', $lesson)`. You should register `Route::model('lesson', Lesson::class)` explicitly, since Laravel does not infer model bindings from definition callbacks. Register `Hls::routes()` in your routes file, then issue `Hls::url(..., expires: now()->addMinutes(10))` from an authorized endpoint. Follow the [complete protected playback setup](README.md#serve-protected-hls-with-laravel).

Routes default to the `['web', 'auth']` middleware and support route caching. Definition and application configuration closures are not cached route actions. If Sanctum is installed, you may replace the middleware with `['api', 'auth:sanctum']`. Deliberate bearer-URL playback can use `['web']` only with authorized URL issuance. There is no automatic global authentication in signed-only mode. URLs are bearer credentials, and the definition's authorization callback remains mandatory on every request.

All child URLs keep the entry URL's exact deadline without renewal. They preserve validated signed query context, such as grants or editions, with route parameters taking precedence. Every request revalidates the signature and authorization. Only inventory-owned resources are served, using opaque IDs and `private, no-store` headers. Media supports single byte ranges and `HEAD`, and key responses contain 16 raw bytes. You should keep inventories server-side and use named disks with private, immutable published media and keys. Publish replacements to new paths.

Keep the raw playlist API above for custom URL policies. You should not expose a generic disk download route. Your application still owns CORS and trusted proxy configuration. Signed playback and AES-128 encryption are not DRM.

<a name="progress-callbacks-and-events"></a>
### Progress Callbacks and Events

**Likelihood Of Impact: Medium**

The `onProgress()` callback now receives a single `Progress` instance instead of the percentage, remaining seconds, and rate as separate arguments:

```php
use ProtoneMedia\LaravelFFMpeg\Progress;

// $media is a Media instance from FFMpeg::open() or FFMpeg::disk()->open().

// Version 8.x
$media->export()
    ->onProgress(function ($percentage, $remaining, $rate) {
        info("{$percentage}% transcoded, {$remaining} seconds left at rate {$rate}");
    })
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->save('lesson.mp4');

// Version 9.x
$result = $media->export()
    ->video('libx264')
    ->onProgress(function (Progress $progress) {
        // The percentage, speed, and remainingSeconds properties can be null.
        // The pass and totalPasses properties describe encoding passes.
        info("{$progress->percentage}% transcoded in pass {$progress->pass} of {$progress->totalPasses}");
    })
    ->save('lesson.mp4');
```

Completion follows validation, publication, and cleanup. You may throw `ExportCancelled` from a callback to stop the operation. Other callback exceptions propagate unchanged.

Global `Events\ExportProgress` listeners receive the operation ID, the destination outputs, and the same structured `Progress` value as `onProgress()`, so no per-export callback is required. Synchronous progress listeners can cancel or fail an operation; asynchronous listeners cannot block encoding. Set `FFMPEG_ENABLE_EVENTS=false` to suppress package events, including `QueuedExportCompleted`, while retaining callbacks and native queue chains.

<a name="exceptions-and-publication"></a>
### Exceptions and Publication

**Likelihood Of Impact: Medium**

The `EncodingException` class and its `getCommand()` and `getErrorOutput()` methods have been removed. Failures now throw a `MediaException` that reports the phase, the category, bounded diagnostics, and the successfully published siblings:

```php
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Exporters\EncodingException;

// $media is a Media instance from FFMpeg::open() or FFMpeg::disk()->open().

// Version 8.x
try {
    $media->export()
        ->inFormat(new \FFMpeg\Format\Video\X264)
        ->save('lesson.mp4');
} catch (EncodingException $exception) {
    $command = $exception->getCommand();
    $errorLog = $exception->getErrorOutput();
}

// Version 9.x
try {
    $result = $media->export()
        ->video('libx264')
        ->save('lesson.mp4');

    $file = $result->file();
} catch (MediaException $exception) {
    $phase = $exception->phase;
    $category = $exception->category;
    $diagnostics = $exception->diagnostics;
    $published = $exception->published;
}
```

All artifacts validate before the first Storage write, but publication is not transactional. A later transfer failure can leave earlier outputs or replaced objects. Failures report successful writes without deleting them. Streams returned by `GeneratedFile::readStream()` belong to the caller and must be closed.

<a name="temporary-files-and-deleting-outputs"></a>
### Temporary Files and Deleting Outputs

**Likelihood Of Impact: Low**

There is no global `cleanupTemporaryFiles()` call, so you should remove it. To delete generated outputs, you should iterate the result's files instead:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// Version 8.x
FFMpeg::cleanupTemporaryFiles();

// Version 9.x
// $result is the root result returned by save().
// After authorization and checking that no other presentation needs these files:
foreach ($result->files() as $file) {
    if ($file->owned && ! $file->delete()) {
        throw new \RuntimeException('Cannot delete '.$file->path);
    }
}
```

You should use the root HLS result's inventory for deletion, not a variant's partial view. `GeneratedFile::delete(): bool` deletes the exact stored destination through its captured filesystem. Borrowed files with `owned=false` return `false` without a filesystem call. Adapter failures return `false` or throw according to the filesystem's behavior. The application owns authorization, reference tracking, deletion order, and retries. There is no directory-wide or transactional delete.

<a name="builder-and-result-types"></a>
### Builder and Result Types

**Likelihood Of Impact: Low**

Each export builder is now its own class with no parent class. For example, `export()` returns `PendingExport`, `hls()` returns `PendingHlsExport`, and `gif()` returns `PendingGif`. A parameter typed `PendingExport` accepts only `export()` builders, so you should type a profile for a specialized builder on that builder's class:

```php
use ProtoneMedia\LaravelFFMpeg\Exporters\MediaExporter;
use ProtoneMedia\LaravelFFMpeg\PendingHlsExport;
use ProtoneMedia\LaravelFFMpeg\Result;

// Version 8.x: HLSExporter extends MediaExporter, so this accepted both builders.
function profile(MediaExporter $export): MediaExporter
{
    return $export->toDisk('streams');
}

// Version 9.x
function hlsProfile(PendingHlsExport $export): PendingHlsExport
{
    return $export->toDisk('streams');
}

function inventory(Result $result): array
{
    return $result->files()
        ->map
        ->toArray()
        ->all();
}
```

Builders define only the methods that change their output. An unsupported call, such as `twoPass()` on `dash()` or `audio()` on `frame()`, is an undefined method and throws `BadMethodCallException`, because builders are `Macroable`. You may register a macro to add your own methods. You should not catch `InvalidArgumentException` to detect unsupported methods; it still reports invalid arguments to supported methods.

Results do not extend each other either. `HlsExportResult`, `DashExportResult`, `LoudnessResult`, and `WaveformPeaksResult` are not `ExportResult` instances. Every export result implements `ProtoneMedia\LaravelFFMpeg\Result`, which provides `files()`, `file()`, and `processes()`, so you should type code that accepts any export result on `Result`.

<a name="queued-exports"></a>
### Queued Exports

**Likelihood Of Impact: Low**

Version 9 queues native exports itself, so you may replace an application job that wrapped the conversion. You should configure the native export before calling `queued()`:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// Version 8.x
// ConvertLesson is your application's job that runs the export in its handle() method.
dispatch(new ConvertLesson('lesson.mp4'));

// Version 9.x
FFMpeg::disk('uploads')
    ->open('lesson.mp4')
    ->export()
    ->toDisk('media')
    ->video('libx264')
    ->audio('aac')
    ->queued(connection: 'redis', queue: 'conversions')
    ->save('lesson.mp4')
    ->afterCommit();
```

A queued `save()` returns Laravel's `PendingDispatch`, not an export result. You should listen for `QueuedExportCompleted` to retain file metadata and protected-HLS inventories. Use `job()` to build jobs for native chains or ordinary batches without dispatching them immediately.

You should store uploads on durable named disks before dispatch. Workers need matching disk configuration, FFmpeg binaries, and the application's encryption key. Filesystem instances and request-local `File` inputs are not portable queue inputs. Worker callbacks require static closures with scalar or array captures. Set the queue visibility above the job timeout. Retries restart conversions rather than resuming media checkpoints.

Hard worker kills cannot run PHP cleanup, so you should configure safe retention for stale private workspaces as well as published artifacts. A recovered retry does not clean an interrupted attempt's workspace.

For independent HLS groups, `queued(parallel: true)` uses a Laravel batch and a final publication job. Shared rendition groups remain together. The versioned final master and borrowed-source ownership follow `mergeHls()`. You should persist the event's actual playback inventory rather than constructing a filename. Keep part artifacts and batch records until finalization finishes. Use a chain rather than nesting a parallel workflow inside another batch. See the [queue examples](README.md#queue-conversions) for retry, result, and retention details.

See the [README](README.md) for executable API examples.

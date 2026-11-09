# Laravel FFmpeg

[![Latest Version on Packagist](https://img.shields.io/packagist/v/pbmedia/laravel-ffmpeg.svg?style=flat-square)](https://packagist.org/packages/pbmedia/laravel-ffmpeg)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Tests](https://github.com/protonemedia/laravel-ffmpeg/actions/workflows/run-tests.yml/badge.svg?branch=9.x&event=push)](https://github.com/protonemedia/laravel-ffmpeg/actions/workflows/run-tests.yml?query=branch%3A9.x)
[![Total Downloads](https://img.shields.io/packagist/dt/pbmedia/laravel-ffmpeg.svg?style=flat-square)](https://packagist.org/packages/pbmedia/laravel-ffmpeg)

Laravel FFmpeg provides a fluent API for encoding video, processing audio, and publishing adaptive streams. It uses Laravel's Process, Storage, Http, and Queue APIs, so you can work with your application's existing disks and queue connections.

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$result = FFMpeg::open('lessons/original.mp4')
    ->export()
    ->resize(width: 1280)
    ->video('libx264', crf: 23)
    ->audio('aac', bitrate: '128k')
    ->save('lessons/720p.mp4');
```

You can run an export immediately or [dispatch it to a queue](#queue-conversions). Each completed export provides an inventory of its generated files, including the playlists, segments, and keys needed for streaming playback.

> [!IMPORTANT]
> Version 9 is in development. These examples follow the `9.x` branch, not a published release. Version 9 replaces PHP-FFMpeg and removes its format and filter objects. If you are upgrading an existing application, consult the [upgrade guide](UPGRADING.md).

## Documentation

- [Getting started](#getting-started)
  - [Installation](#installation)
  - [Finding FFmpeg](#find-and-verify-the-executables)
  - [Checking capabilities](#inspect-capabilities)
- [Working with media](#working-with-media)
  - [Encoding and clipping](#encode-and-copy)
  - [Inputs and probing](#inputs-and-probing)
  - [Tracks and metadata](#tracks-and-metadata)
- [Transforming media](#transforming-media)
  - [Geometry and filters](#geometry-and-filters)
  - [Frames and storyboards](#frames-timelapse-and-storyboards)
  - [Playback speed and GIFs](#change-playback-speed-and-make-gifs)
  - [Audio mixing](#mix-audio-and-add-background-music)
  - [Waveform data](#waveform-peaks-data)
  - [Loudness normalization](#two-pass-loudness-normalization)
  - [Watermarks](#add-a-watermark)
  - [Concatenation](#join-clips)
  - [Multiple outputs](#compose-multiple-inputs-and-outputs)
- [Streaming](#streaming)
  - [Adaptive HLS and encryption](#adaptive-hls-and-encryption)
  - [fMP4 and alternate tracks](#fmp4-and-alternate-hls-groups)
  - [DASH and shared segments](#dash-and-shared-hls-segments)
  - [Continuous HLS](#publish-continuous-hls)
  - [Protected playback](#serve-protected-hls-with-laravel)
  - [Custom playlist responses](#customize-raw-hls-responses)
- [Running exports](#running-exports)
  - [Progress, results, and failures](#progress-results-and-failures)
  - [Execution limits](#limit-an-operations-execution-time)
  - [Queues](#queue-conversions)
  - [Parallel HLS](#run-independent-hls-conversions-in-parallel)
  - [Completion inventories](#retain-queue-results)
- [Deployment and development](#deployment-and-development)
- [Application integration recipes](docs/integrations.md)
- [API reference and acceptance contract](REWRITE.md)

## Getting started

### Installation

Laravel FFmpeg requires:

- PHP 8.4 or later.
- Laravel 12 or 13.
- Matching FFmpeg and FFprobe 9.0.x binaries. Setup and CI use 9.0.2.

Install the development branch with Composer:

```bash
composer require pbmedia/laravel-ffmpeg:9.x-dev
```

Laravel discovers the service provider and facade automatically.

#### Publishing the configuration

To customize binary paths, timeouts, or queue defaults, publish the package's configuration file using the `vendor:publish` Artisan command:

```bash
php artisan vendor:publish --provider="ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider" --tag=config
```

The published configuration file is located at `config/laravel-ffmpeg.php`. Package discovery and configuration caching do not launch FFmpeg. The package validates the binaries when you use them.

Set the executable paths and an existing, writable temporary directory in your environment:

```dotenv
FFMPEG_BINARIES=/opt/ffmpeg/bin/ffmpeg
FFPROBE_BINARIES=/opt/ffmpeg/bin/ffprobe
FFMPEG_TEMPORARY_FILES_ROOT=/var/tmp/media
```

FFmpeg needs the encoders, filters, and protocols used by your application. The repository's `.agents/install-ffmpeg` builds the tested binary pair with libx264, libx265, libass, fonts, image support, DASH decoding, and HTTPS.

#### Find and verify the executables

The defaults resolve `ffmpeg` and `ffprobe` through the running process's `PATH`. Your shell, web server, and queue workers can have different paths and permissions.

```bash
command -v ffmpeg
command -v ffprobe
```

Common locations are `/usr/bin` for Linux distribution packages, `/opt/homebrew/bin` for Apple Silicon Homebrew, and `/usr/local/bin` for Intel Homebrew. Manual builds use their installation directory, such as `/opt/ffmpeg-9.0.2/bin`. These locations do not guarantee a supported version. Both executables must report the same 9.0.x release.

Set `FFMPEG_BINARIES` and `FFPROBE_BINARIES` to the full executable paths, not their containing directories. Alternatively, edit `ffmpeg.binaries` and `ffprobe.binaries` in the published `config/laravel-ffmpeg.php` file.

On a Linux deployment, test the configured paths as the actual web-server or worker user. Replace `www-data` and the paths with your deployment's values:

```bash
sudo -u www-data /opt/ffmpeg-9.0.2/bin/ffmpeg -version
sudo -u www-data /opt/ffmpeg-9.0.2/bin/ffprobe -version
```

If the shell command succeeds but the application fails, check the application's cached configuration, executable permissions, and directory traversal permissions. Rebuild Laravel's configuration cache and restart long-running queue workers after changing binary paths. Also check that the temporary root and Storage destinations are writable by those users.

A launch failure means the selected executable could not start. A version failure means the pair is unsupported or mismatched. An encoding failure means FFmpeg started but rejected the command or media. Inspect `MediaException::phase`, `category`, and bounded `diagnostics` to distinguish these cases. Categories name the condition rather than the operation, for example `metadata` for an unknown duration or presentation origin and `mapping` for a missing track. Check required encoders and filters with the configured executable's `-encoders` and `-filters` options. Raw commands and unredacted process exceptions can contain credentials; do not publish them in logs or error responses.

#### Inspect capabilities

Before processing media, you can use the `ffmpeg:doctor` Artisan command to check the features available in your FFmpeg installation:

```bash
php artisan ffmpeg:doctor --encoder=libx264 --muxer=dash --filter=scale
php artisan ffmpeg:doctor --json
```

`ffmpeg:doctor` checks matching binary versions, compiled-in support, and an actual temporary-workspace write. Missing requirements produce a nonzero exit code. Repeat `--encoder`, `--muxer`, `--filter`, `--protocol`, `--bsf`, or `--hwaccel` to check deployment requirements.

You can also inspect capabilities from your application using the `capabilities` method:

```php
$capabilities = FFMpeg::capabilities();
$capabilities->has('encoders', 'libx264');
$capabilities->require(['encoders' => ['libx264', 'aac'], 'muxers' => ['dash']]);
$capabilities->toArray();
```

Each call captures a fresh report. Compiled-in hardware support does not prove device access, driver compatibility, or encoder usability.

## Working with media

The examples below use the `FFMpeg` facade. Inputs and destinations are relative to a Laravel Storage disk; `open()` uses your default disk unless you select another one.

### Encode and copy

To encode a video, open the source and call the `export` method. You can then configure the video and audio codecs before saving the result to a destination disk:

```php
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$media = FFMpeg::disk('uploads')
    ->open('lesson.mov');

$result = $media->export()
    ->video('libx264', crf: 23, preset: 'medium')
    ->audio('aac', bitrate: '128k')
    ->resize(width: 1280)
    ->toDisk('videos', ['visibility' => 'private'])
    ->save('lesson.mp4');
```

#### Copying streams

If you only need to change the container, pass `copy` to the `video` and `audio` methods. This copies the encoded streams without re-encoding them:

```php
$media->export()
    ->video('copy')
    ->audio('copy')
    ->save('lesson.mkv');
```

Each call to `export` creates an independent configuration. Stream copying requires a compatible output container and cannot be combined with filters or accurate clipping.

#### Configuring video quality

The `crf` argument controls constant-quality encoding. To target a bitrate instead, pass a positive integer in bits per second, such as `2500000`, or a unit string such as `'2500k'`. Floats and unitless strings such as `'2500000'` are invalid. You cannot combine CRF and a target bitrate.

#### Two-pass encoding and clipping

Use two-pass encoding for a target video bitrate. Choose accurate clipping when you need an exact cut, or keyframe seeking when copying compatible streams:

```php
$media->export()
    ->video('libx264', bitrate: '2500k')
    ->audio('aac')
    ->twoPass()
    ->save('two-pass.mp4');

$media->export()
    ->video('libx264')
    ->audio('aac')
    ->clip('00:00:12.500', duration: 8)
    ->save('accurate.mp4');

$media->export()
    ->video('copy')
    ->audio('copy')
    ->clip(12.5, end: 20.5, seek: 'keyframe')
    ->save('keyframe.mkv');
```

Two-pass video encoding owns private statistics for each output. Keyframe cuts can start before the requested position. Accurate cuts transcode the affected streams. Timeline changes omit chapters unless explicitly mapped.

### Inputs and probing

The `open` method accepts a Storage path, an uploaded file, or a local file object. To open an HTTP source, use the `openUrl` method:

```php
$media = FFMpeg::open('lesson.mp4'); // Current filesystems.default.
$media = FFMpeg::disk($filesystem)
    ->open('lesson.mp4');
$media = FFMpeg::open($request->file('video'));
$media = FFMpeg::open(new \Illuminate\Http\File('/absolute/path/lesson.mp4'));

$public = FFMpeg::openUrl('https://cdn.example.com/lesson.mp4');
$private = FFMpeg::openUrl($url, headers: ['Authorization' => 'Bearer '.$token], download: true);
```

`FFMpeg::open($key)` resolves string keys against the current `filesystems.default`. Use `FFMpeg::disk('uploads')->open($key)` to select another Storage disk. A `File` or `UploadedFile` uses the supplied local file, not a Storage key. Existing descriptors retain their disk when configuration changes.

Storage paths are disk-relative keys, not URLs or local absolute paths. Descriptor construction and `inspect()` do no I/O. Local inputs can be read in place; other filesystems stream into a private operation workspace. Scoped and read-only input adapters retain their boundaries. Read-only destinations fail publication.

> [!WARNING]
> Authenticated HTTP inputs require `download: true`. Downloads stream to private disk with deadlines and reject redirects. Direct public or signed URLs enter FFmpeg arguments. Never put reusable credentials in them. HTTPS verification stays enabled. See the [HTTP input reference](REWRITE.md#http-inputs) for transport details.

#### Inspecting media

The `probe` method returns metadata about the source, including its duration, streams, and chapters:

```php
$metadata = $media->probe();
$metadata->duration(); // Nullable seconds.
$metadata->video()
    ->width();
$metadata->audioStreams();
$metadata->chapters();
$metadata->get('format.tags.title');
$metadata->toArray();
```

#### Caching metadata

To cache a probe result, pass a Laravel cache repository, a versioned cache key, and a lifetime in seconds to the `probeCached` method:

```php
use Illuminate\Support\Facades\Cache;

$metadata = $media->probeCached(
    Cache::store('redis'),
    versionedKey: 'tenant:42:lesson:7:object-version:abc123',
    ttl: 600,
);
```

Change `versionedKey` whenever the source bytes or object version changes. Your application controls cache freshness, tenant isolation, access, and eviction. A cache hit does not check Storage or reauthorize the source. Protect cached metadata as source data. Ordinary exports still probe their staged inputs.

#### Hardware acceleration

To configure hardware decoding, pass input options when opening the source. Select the corresponding encoder on the export:

```php
$gpuInput = FFMpeg::disk('uploads')
    ->open('lesson.mp4', inputOptions: [
        '-hwaccel', 'cuda', '-hwaccel_device', '0', '-hwaccel_output_format', 'cuda',
    ]);
$gpuInput->export()
    ->video('h264_nvenc')
    ->audio('copy')
    ->save('gpu.mp4');
```

GPU flags configure input options. They do not select hardware automatically or provide a CPU fallback. This example requires a CUDA-capable FFmpeg build, an NVIDIA device, and a compatible decoder and encoder. Hardware execution has not been validated in this checkout. Filters that consume CPU frames can require an explicit hardware download and format conversion.

#### Capturing camera snapshots

Use the `openRtsp` method to capture a frame from a trusted camera URL:

```php
FFMpeg::openRtsp($cameraUrl, transport: 'tcp', timeout: 10)
    ->frame()
    ->resize(width: 640)
    ->toDisk('images')
    ->save('camera.jpg');
```

`openRtsp()` returns an `RtspCamera` for bounded snapshots, not a live-export descriptor. `frame()` captures one frame into a private PNG before ordinary image processing and publication. TCP and UDP fixture captures pass. Transport accepts `tcp` or `udp`; timeout is positive and at most 300 seconds. Camera URLs are trusted application configuration. Restrict reachable hosts and protect credentials, which can appear in process arguments. See the [input reference](REWRITE.md#cached-probing-and-hardware-inputs).

#### Query frames and packets

When you need frame or packet data rather than general metadata, use the `probeQuery` method. You can select streams, limit the interval, and request specific fields:

```php
$query = $media->probeQuery()
    ->selectStreams('v:0')
    ->keyframes()
    ->readInterval(12, 5)
    ->entries(['frame' => ['pts_time', 'key_frame', 'pict_type']])
    ->limits(outputBytes: 4194304, timeout: 30)
    ->get();

$query->frames();
$query->bounds();
$query->toArray();

$packets = $media->probeQuery()
    ->selectStreams('a:0')
    ->packets()
    ->readPackets(100, start: 2)
    ->countPackets()
    ->get();
```

`probeQuery()` returns a separate `ProbeResult`, not full-asset `Metadata`. The default query reads the first 10 seconds. `frames()`, `packets()`, and `keyframes()` select the record type. `countFrames()` and `countPackets()` add bounded stream counts. FFprobe seeking is approximate, and a packet limit is not a frame limit.

Time intervals are at most 300 seconds. Packet limits are at most 100,000 demuxed packets. Captured output defaults to 4 MiB and cannot exceed 16 MiB. The cumulative query timeout defaults to 30 seconds and cannot exceed 120 seconds. Global process limits still apply. Queries retain the input's staging, transport restrictions, and redaction rules.

### Tracks and metadata

Choose streams by type, language, or explicit FFmpeg map. Set container metadata and output-track dispositions on the export:

```php
$media->export()
    ->mapVideo()
    ->mapAudio(language: 'eng')
    ->video('copy')
    ->audio('copy')
    ->metadata('title', 'English lesson')
    ->streamMetadata('a:0', 'language', 'eng')
    ->disposition('a:0', ['default', 'comment'])
    ->save('english.mkv');

$media->export()
    ->map('0:V:0')
    ->map('0:a')
    ->map('0:s')
    ->map('0:v:1')
    ->video('copy')
    ->audio('copy')
    ->outputOptions(['-c:s', 'copy'])
    ->save('subtitles-and-cover.mp4');

$media->export()
    ->video('copy')
    ->audio('copy')
    ->withoutMetadata(chapters: true)
    ->save('without-tags.mkv');
```

Without maps, exports select the first motion-video and audio stream. Explicit maps replace that selection. Map subtitles and cover art explicitly and choose container-compatible codecs. `Stream` exposes `language()`, `isForced()`, `isDefault()`, and `isCoverArt()`. Stream tags and dispositions address output order. Raw fields stay available through `get()`.

```php
$media->export()
    ->coverArt(FFMpeg::disk('assets')
        ->open('album.png'))
    ->toDisk('videos')
    ->save('with-cover.mp4');

$media->export()
    ->map('0:a:0')
    ->map('0:v:0')
    ->map('0:v:0')
    ->audio('copy')
    ->videoStream(0, fn ($track) => $track->codec('libx264')
        ->crf(20)
        ->preset('medium'))
    ->videoStream(1, fn ($track) => $track->codec('mpeg4')
        ->bitrate('800k'))
    ->save('two-video-tracks.mp4');

$media->export()
    ->map('0:s:0')
    ->outputOptions(['-c:s', 'webvtt'])
    ->toDisk('captions')
    ->save('english.vtt');
```

`coverArt()` attaches one standalone PNG or JPEG to MP4, M4A, or MP3 while copying all motion-video and audio tracks. It refuses existing attached art rather than replacing it. Encoding, filters, clipping, custom maps, and disposition overrides are unsupported in this mode.

`videoStream()` addresses zero-based output video order after mapping, not the input stream index or overall output order. Each track can be configured once. Its codec, bitrate, CRF, and preset override type-wide `video()` settings. CRF and bitrate cannot combine, including inherited settings. Per-track settings do not support two-pass encoding. The WebVTT recipe selects a text subtitle track explicitly; it does not recognize speech or convert bitmap subtitles to text.

#### Configure encoders and individual audio tracks

To configure individual output audio tracks, use the `audioStream` method. The following example maps the source's second audio track first, then applies separate settings to each output track:

```php
$media->export()
    ->map('0:v:0')
    ->map('0:a:1')
    ->map('0:a:0')
    ->video('libx264', crf: 22)
    ->audio('aac', bitrate: '128k')
    ->videoOptions(['-x264-params', 'keyint=48:scenecut=0'])
    ->color('bt709', 'bt709', 'bt709')
    ->audioStream(0, fn ($audio) => $audio->sampleRate(48000)
        ->channels(2))
    ->audioStream(1, fn ($audio) => $audio->bitrate('96k')
        ->filter('volume=0.5'))
    ->save('selected.mp4');
```

`audioStream()` and `videoStream()` address zero-based **output** track order after mapping. Their callbacks run during configuration and retain scalar snapshots, not closures. Audio settings include codec, bitrate, sample rate, channel count, sample format, channel layout, compression level, and filters. Per-track filters follow shared filters. Missing output tracks fail before encoding.

`videoOptions()` and `audioOptions()` accept curated option/value pairs. Supported controls include numeric x264/x265 parameters, selected VPx/AV1 and NVENC settings, and color signaling. Unknown parameters and file-producing encoder options are rejected. Availability depends on your FFmpeg build and hardware. The acceptance build has no AV1 encoder or GPU device.

`color()` sets frame and codec metadata without converting pixel values. Use a color-conversion filter when changing the image's color space. The GIF, loudness, audio-mix, concat, and DASH builders have no per-track methods, and DASH representations reject per-track overrides.

`outputOptions()` also supports selected single-file muxer settings and argument-free bitstream filters, for example `['-bsf:v', 'h264_mp4toannexb']` during a copy remux. These options cannot choose sidecar paths. `globalOptions()` accepts named hardware initialization and derivation, for example `['-init_hw_device', 'vaapi=base:/dev/dri/renderD128', '-init_hw_device', 'qsv=derived@base', '-filter_hw_device', 'derived']`. Derived devices must reference an earlier declaration. No automatic hardware fallback is provided.

## Transforming media

Transformations are configured on a new export builder. Ordinary filters run in the order you register them; operations that own a graph document their separate rules below.

### Geometry and filters

Crop, rotate, pad, or fit a video to your output canvas:

```php
$media->export()
    ->video('libx264')
    ->audio('copy')
    ->crop(1280, 720, x: 40, y: 20)
    ->rotate(90)
    ->pad(800, 1280, color: 'black')
    ->save('portrait.mp4');

$media->export()
    ->fit(1280, 720, mode: 'contain'); // Black bars.
$media->export()
    ->fit(1280, 720, mode: 'cover');   // Center crop.
$media->export()
    ->video('libx264')
    ->audio('aac')
    ->deinterlace()
    ->frameRate('30000/1001')
    ->flipHorizontal()
    ->grayscale()
    ->volume(0.5)
    ->delayAudio(0.25)
    ->fadeIn(1)
    ->fadeOut(1, start: 9)
    ->save('treated.mp4');
```

Ordinary filters run in registration order. All output video filters run before generated `watermark()` overlays, even filters called after `watermark()`. One-dimension `resize()` preserves display aspect ratio; two dimensions stretch. `fit()` produces square pixels. Crop and pad use positive even dimensions and centered offsets by default. Rotation accepts clockwise quarter turns and negative equivalents, after input autorotation. FFmpeg validates source-dependent geometry.

```php
$media->export()
    ->resize(height: 1920)
    ->video('libx264')
    ->audio('copy')
    ->save('portrait-height.mp4');
$media->export()
    ->fit(1080, 1920, mode: 'contain')
    ->video('libx264')
    ->audio('copy')
    ->save('portrait-canvas.mp4');
```

For portrait sizing, `resize(height: 1920)` preserves the autorotated display aspect ratio rather than forcing a landscape width. Use `fit(1080, 1920)` for an exact portrait canvas. Raw probe width and height remain encoded dimensions, before display rotation.

`frameRate()` drops or duplicates frames without changing playback speed. Deinterlace before resizing; the default `frame` mode preserves frame count, while `mode: 'field'` produces a frame per field. `flipVertical()` is also available. `volume()` takes a linear multiplier, where zero mutes. `delayAudio()` inserts silence on every channel and extends the audio. Fades affect both video and audio unless you pass `video: false` or `audio: false`; `fadeOut()` requires an explicit start in seconds. Filtered streams must be encoded, not copied. Use `videoFilter()` and `audioFilter()` for other trusted FFmpeg expressions.

```php
$media->export()
    ->drawText('100%: café [literal]', FFMpeg::disk('assets')
        ->open('Brand.ttf'), size: 24, x: 12, y: 18)
    ->burnSubtitles(FFMpeg::disk('assets')
        ->open('captions.srt'))
    ->video('libx264')
    ->audio('copy')
    ->save('captioned.mp4');
```

Text and subtitle helpers stage private resources instead of interpolating file paths into arbitrary filter strings. Filters are trusted application code, not an untrusted-media sandbox.

### Frames, timelapse, and storyboards

Extract a single image, sample several frames, or build a storyboard with WebVTT cues:

```php
$media->frame(at: 12.5)
    ->resize(width: 640)
    ->toDisk('images')
    ->save('poster.jpg');
$bytes = $media->frame(at: 12.5)
    ->get();
$media->frames(every: 2)
    ->resize(width: 320)
    ->save('frames/frame-%05d.jpg');
$media->frames(count: 10)
    ->save('frames/frame-%05d.png');

FFMpeg::disk('images')
    ->openSequence('frame-%04d.png', count: 90, framerate: '30000/1001', start: 1)
    ->export()
    ->video('libx264')
    ->save('timelapse.mp4');

$media->storyboard(every: 5, columns: 5, rows: 3, margin: 2, padding: 1)
    ->resize(width: 160)
    ->vtt('cues/lesson.vtt')
    ->toDisk('images')
    ->save('sheets/lesson-%03d.jpg');

$media->waveform(width: 800, height: 120, colors: 'blue')
    ->clip(2, duration: 10)
    ->save('waveform.png');
```

Image patterns require one integer placeholder. Storyboard VTT references are relative to the cue file, even when images use a different directory. JPEG quality uses `outputOptions(['-q:v', '3'])`. A waveform PNG is distinct from waveform-peaks data.

```php
FFMpeg::disk('images')
    ->open('title.png')
    ->still(duration: 5, frameRate: 25)
    ->resize(width: 1280)
    ->toDisk('videos')
    ->save('title.mp4');
```

`still()` returns a single-output video builder with H.264 and `yuv420p` defaults. Duration must be finite and positive; integer frame rate ranges from 1 to 240. Input must be a standalone PNG or JPEG with even dimensions of at least two pixels, without input options. Local, staged Storage, and downloaded images are supported. No audio track is created, so the builder has no audio, clipping, or extra-input methods. Video codec, geometry, filter, metadata, and publication methods work as on `export()`. Frame rounding can affect the final duration.

Waveform `colors` accepts a string or a nonempty list of strings. One string means one color, not a delimited list. Use names such as `'blue'` or RGB/RGBA hexadecimal colors with a `#` or `0x` prefix.

### Change playback speed and make GIFs

The `speed` method changes video timing and audio tempo without changing audio pitch. Pass a factor greater than one to speed up playback, or a factor below one to slow it down:

```php
$media->speed(2)
    ->video('libx264')
    ->audio('aac')
    ->save('double-speed.mp4');
$media->speed(0.5)
    ->video('libx264')
    ->audio('aac')
    ->save('half-speed.mp4');
```

To create an animated preview, use the `gif` method. The following example converts a three-second clip into a looping GIF:

```php
$media->gif(width: 480, frameRate: 12, loop: 0)
    ->clip(4, duration: 3)
    ->toDisk('images')
    ->save('preview.gif');
```

`speed()` changes video timing and audio tempo without changing audio pitch. It requires a finite positive factor, zero video origins, nonnegative audio origins, and a zero container origin when the source has video. An audio-only source that starts later is retimed from its first sample without its leading offset. It scales an existing audio delay with playback speed. Stream copy, clipping, input options, custom graphs, watermark inputs, and multiple outputs are not supported. Encode a clip first if you need to retime only part of a source.

`gif()` selects the first motion-video stream and omits audio. Width defaults to 480 and frame rate to 12. `loop: 0` loops forever; `-1` disables looping. Accurate `clip()` runs before palette generation. The helper writes a private lossless intermediate, generates a palette, then encodes the GIF. This avoids buffering the entire clip in memory, but adds disk use and processing passes. Keep previews short and small. The GIF builder offers video filters, resources, metadata, clipping, and publication; it has no map, graph, extra input or output, audio, encoder, or raw output option methods.

### Mix audio and add background music

Use the `backgroundMusic` method to add a quieter, looping audio track to a video. You can delay the music with the `at` method or disable looping with the `loop` argument:

```php
$video = FFMpeg::open($request->file('video'));
$music = FFMpeg::disk('music')
    ->open('background.mp3');

// Start music at 2.5 seconds, then repeat it at 20% volume until the video ends.
$video->backgroundMusic($music)
    ->at(2.5)
    ->video('copy')
    ->audio('aac', bitrate: '192k')
    ->save('with-music.mp4');

// Play the added track once, then fill the remaining time with silence.
$video->backgroundMusic($music, volume: 0.2, loop: false)
    ->video('copy')
    ->audio('aac')
    ->save('one-shot.mp4');

// Set both gains, divide the mixed signal by two, then fade the final audio.
$video->mixAudio($music, volume: 0.4, originalVolume: 0.8, normalize: true)
    ->fadeIn(1, video: false)
    ->fadeOut(2, start: 18, video: false)
    ->video('copy')
    ->audio('aac')
    ->save('mixed.mp4');
```

`mixAudio()` combines the first audio stream of each input and plays the added track once by default. Call `loopAudio()` to repeat that track. `backgroundMusic($music, volume: 0.2, loop: true)` is shorthand with quieter, looping music and unchanged original volume.

#### Timing and duration

The primary container's duration sets the output length. A short nonlooping track ends in silence; looping repeats it until the primary ends. Longer added audio is trimmed. A silent video receives only the added music. Audio-only inputs also work, including WAV files.

`at($seconds)` delays only the added track, after resetting that track to its first decoded sample. The default is zero. Offsets must be finite and nonnegative and round to the nearest 48 kHz sample. Original timing and gains do not change. A loop starts at the offset, then repeats continuously without repeating the initial silence. An offset at or beyond the primary duration contributes no audio and does not extend the output. Calling `at()` again replaces the offset.

The mix is stereo at 48000 Hz and requires audio encoding. Video copy is allowed unless you add video filters. `volume()`, `audioFilter()`, and audio fades run after mixing. The added track starts at its first decoded sample after any `at()` delay; original audio retains its offset relative to video.

#### Mixing levels

Gains are finite nonnegative amplitude multipliers. The default `normalize: false` adds the signals and can clip. With original audio present, `normalize: true` divides both weighted branches equally by two. Silence padding keeps the gain constant when either track ends. This is not loudness normalization.

Both sources need finite positive durations, and the added source must contain audio. A primary with video requires zero container and video timestamp origins and a known nonnegative original-audio origin. To loop URL audio, open it with `FFMpeg::openUrl($url, download: true)` first. Mixing supports one output and owns the graph and maps. Custom maps, graphs, watermarks, clips, and two-pass encoding are unsupported. Save a clip first if you need to mix only part of a source. See the [audio mixing reference](REWRITE.md#audio-mixing) for the method signatures and timing policy.

### Waveform-peaks data

Generate min/max sample data for a waveform renderer, independently of waveform PNG images:

```php
$peaks = $media->waveformPeaks(samplesPerBucket: 256)
    ->audioTrack(1)
    ->clip(10, duration: 30)
    ->mono()
    ->buckets(1000)
    ->get();

$peaks->sampleRate;
$peaks->duration();
$peaks->channels; // Channel-major signed 16-bit [min, max] pairs.
$peaks->bucketFrames;
$peaks->toArray();

$result = $media->waveformPeaks()
    ->buckets(2000)
    ->toDisk('waveforms')
    ->save('lesson.json');

$result->peaks; // The same WaveformPeaks that get() returns.
```

`get()` returns the peaks without publishing a file. `save()` publishes the version-1 JSON and returns a result with the computed `peaks`, so you do not need a second run to read them.

Separate channels are the default. `mono()` mixes channels with equal weights before reduction. `buckets(n)` produces at most `n` nonempty buckets; otherwise each bucket contains up to `samplesPerBucket` decoded frames. Partial final buckets have no padding. Version-1 JSON includes the selected sample rate, channel mode, frame count, and bucket timing. Reduction uses bounded reads and caps results at 100,000 channel-buckets. Private PCM disk usage grows with decoded duration and is cleaned after the operation.

### Two-pass loudness normalization

To normalize audio to a measured loudness target, use the `normalizeLoudness` method. You can inspect the measurements and the normalization mode on the returned result:

```php
$result = $media->normalizeLoudness(integrated: -18, truePeak: -2, range: 7,
    linear: true, sampleRate: 48000, audio: 1)
    ->clip(3, duration: 4)
    ->audioFilter('pan=mono|c0=c0,volume=0.5')
    ->audio('pcm_s24le')
    ->toDisk('audio')
    ->save('normalized.wav');

$result->measurement;
$result->normalization;
$result->requestedMode;
$result->actualMode(); // FFmpeg can fall back to dynamic normalization.
```

Normalization selects one audio track and produces one audio-only output. It decodes the trimmed, preprocessed samples once into a private lossless intermediate, then measures and normalizes those same samples. Channel processing uses `audioFilter()`; `sampleRate:` owns the output rate. Silence remains silence, with nonfinite statistics represented by null. Graphs, additional inputs or outputs, stream copy, and video settings are rejected.

### Add a watermark

The `watermark` method overlays an image on your video. Open the image on its source disk, then choose its position, size, and opacity:

```php
$logo = FFMpeg::disk('assets')
    ->open('logo.png');

$media->export()
    ->resize(width: 1280)
    ->watermark($logo, position: 'bottom-right', x: 20, y: 15, width: 160, opacity: 0.8)
    ->video('libx264')
    ->audio('copy')
    ->toDisk('videos')
    ->save('branded.mp4');
```

`watermark()` adds the image input and maps for you. It accepts PNG, JPEG, and GIF images from local or non-local Storage. Marks render after all output video filters, regardless of where you call `watermark()`. Offsets are inward margins from the chosen corner. Anchors also include `top-left`, `top-right`, `bottom-left`, and `center`. Call the helper again to add another mark.

Watermarks work with ordinary single or multiple media outputs, HLS variants, and copied audio, but require video encoding. Custom maps, custom graphs, and additional media inputs require an explicit overlay graph instead. For image preprocessing, call `prepareImage()` with a callback that rewrites the private writable copy it receives. On Laravel 13.20 or later, Laravel's [image manipulation](https://laravel.com/docs/13.x/images) can do this:

```php
use Illuminate\Support\Facades\Image;

$logo = FFMpeg::disk('assets')
    ->open('logo.png')
    ->prepareImage(function (string $privatePath) {
        file_put_contents($privatePath, Image::fromPath($privatePath)
            ->scale(width: 160)
            ->toBytes());
    });
```

### Join clips

Use `concatTranscoded` to join clips with different dimensions, frame rates, or audio layouts. If the streams are already compatible, use `concat` with stream copying instead:

```php
$first = FFMpeg::disk('clips')
    ->open('first.mp4');
$second = FFMpeg::disk('archive')
    ->open('second.mp4');

// Different dimensions, frame rates, or audio layouts: encode a common format.
$first->concatTranscoded($second)
    ->normalizeVideo(1280, 720, frameRate: 30, fit: 'contain')
    ->normalizeAudio(sampleRate: 48000, channels: 2)
    ->video('libx264')
    ->audio('aac')
    ->toDisk('videos')
    ->save('joined.mp4');

// Compatible streams: join without re-encoding.
$first->concat($second)
    ->export()
    ->video('copy')
    ->audio('copy')
    ->save('joined.mkv');
```

`concatTranscoded()` requires a video policy and either `normalizeAudio()` or `withoutAudio()`. `contain` adds black bars; `cover` crops; `stretch` changes the aspect ratio. It selects each source's first motion-video and audio streams and preserves delayed audio through silence. Sources must be finite with zero container/video origins. Audio normalization requires audio in every source; it does not invent a track for a silent clip. The helper owns one output and its graph, so it has no filter, map, or graph methods, and stream copy is rejected. See the [concat reference](REWRITE.md#concatenation) for limits and raw graphs.

Copy concatenation requires compatible streams and never falls back to transcoding. Transcoded concatenation decodes and encodes every clip. Neither method combines source chapters. Both publish to the first input's output disk unless you call `toDisk()`.

### Compose multiple inputs and outputs

Use `lut3d()` for a staged LUT without a raw graph. Each output can apply the LUT and resize independently:

```php
use ProtoneMedia\LaravelFFMpeg\ExportOutput;

$look = FFMpeg::disk('assets')
    ->open('looks/grade.cube');
$media->export()
    ->output('full.mp4', fn (ExportOutput $out) => $out
        ->lut3d($look)
        ->resize(width: 1920)
        ->video('libx264')
        ->audio('aac')
        ->toDisk('videos'))
    ->output('preview.mp4', fn (ExportOutput $out) => $out
        ->lut3d($look)
        ->resize(width: 640)
        ->video('libx264')
        ->audio('aac')
        ->toDisk('previews'))
    ->save();
```

`lut3d(Media $look)` uses private staging and preserves filter call order. It rejects media input options on the LUT descriptor. Each output runs its filters independently. There is no shared LUT or automatic `split` optimization. To process the LUT once before splitting, keep the raw `resource()` and `filterComplex()` graph shown in the [composition reference](REWRITE.md#composition-and-resources).

#### Custom filter graphs

Use `addInput()` and `filterComplex()` when you need custom branching, stream selection, or an HLS watermark:

```php
use ProtoneMedia\LaravelFFMpeg\ExportOutput;

$export = $media->export();
$logo = $export->addInput(FFMpeg::disk('assets')
    ->open('logo.png'));

$result = $export
    ->filterComplex('[0:v]['.$logo.':v]overlay=x=W-w-20:y=H-h-20,split[full][small];[small]scale=640:360[preview]')
    ->output('full.mp4', fn (ExportOutput $out) => $out
        ->map('[full]')
        ->map('0:a:0?')
        ->video('libx264')
        ->audio('copy')
        ->metadataFrom(0)
        ->chaptersFrom(null)
        ->toDisk('videos'))
    ->output('preview.mp4', fn (ExportOutput $out) => $out
        ->map('[preview]')
        ->video('libx264')
        ->withoutMetadata(chapters: true)
        ->toDisk('previews'))
    ->save();
```

Mixed-disk inputs stage once per execution. Each output requires explicit maps, metadata/chapter policy, and a destination. Consume each graph label once; use `split` or `asplit` to branch. Resources such as LUTs use `resource('look', $media)` and whole named placeholders such as `lut3d=file={{look}}`.

## Streaming

Use `hls()` or `dash()` for finite presentations that publish after encoding and validation. Use `liveHls()` to publish a sliding playlist while the encoder runs.

### Adaptive HLS and encryption

Define the variants your player can select. Store encryption keys on a private disk and provide an application-authorized key URL:

```php
use ProtoneMedia\LaravelFFMpeg\HlsVariant;

$result = $media->hls(segmentDuration: 6)
    ->variant('360p', fn (HlsVariant $variant) => $variant
        ->resize(height: 360)
        ->video('libx264', bitrate: '800k'))
    ->variant('720p', fn (HlsVariant $variant) => $variant
        ->resize(height: 720)
        ->video('libx264', bitrate: '2500k'),
        playlist: 'lists/720p/index.m3u8', segments: 'segments/720p/part-%03d.ts')
    ->encrypt('secrets', fn (string $keyPath) => route('lesson.key', ['key' => basename($keyPath)]),
        rotateEvery: 3, directory: 'lessons/42/keys')
    ->toDisk('streams')
    ->save('lessons/42/master.m3u8');

$result->playlist();
$result->variant('720p')
    ->files();
$result->files()
    ->where('role', 'hls-key');
```

#### Playlist types

`playlistType('vod')` is the default. Use `playlistType('event')` for `EXT-X-PLAYLIST-TYPE:EVENT`, or `playlistType('live')` to omit the type tag. Both MPEG-TS and fMP4 retain every generated segment and end with `EXT-X-ENDLIST`. Parallel queued composition and `mergeHls()` require VOD. A single queued export supports all three types.

> [!NOTE]
> Playlist types do not enable playback during encoding. These exports publish only after encoding and validation. For a sliding playlist that updates while the encoder runs, use [continuous HLS](#publish-continuous-hls).

#### HEVC and hardware encoders

For HEVC HLS, select `video('libx265', crf: 28, preset: 'fast')` on the export or a variant. Add `outputOptions(['-tag:v', 'hvc1'])` so FFmpeg advertises the HEVC codec in the master. MPEG-TS and fMP4 encode and decode with this configuration. Check player support separately. Hardware encoders use the same `video()` API, for example `video('hevc_nvenc')`, with input-scoped decoding options when needed. There is no automatic hardware selection or software fallback. The deployed binary, drivers, device, and filter graph must support the selected path.

#### Watermarks and variant selection

HLS variants support static or animated watermarks. After encoding, use the `playbackForVariants` method to retain a playback inventory for only the variants a viewer may access:

```php
$animated = FFMpeg::disk('assets')
    ->open('badge.gif', inputOptions: ['-ignore_loop', '0']);
$media->hls()
    ->watermark($animated, width: 96, x: 16, y: 16)
    ->variant('720p', fn ($variant) => $variant->resize(height: 720)
        ->video('libx264', bitrate: '2500k'))
    ->toDisk('streams')
    ->save('animated/master.m3u8');

$selected = $result->playbackForVariants(['720p']);
$lesson->hls_playback = $selected->toArray();
```

HLS watermark inputs accept static PNG and JPEG or animated GIF. `['-ignore_loop', '0']` honors the GIF's embedded loop instructions; it does not force a finite GIF to loop forever. With `1`, FFmpeg ignores those instructions. After image EOF, the overlay holds the last frame. All ordinary video filters still precede overlays, including each variant's resize, regardless of method call order.

`playbackForVariants()` returns a playback inventory for a nonempty set of unique variant names. It retains required audio, subtitle, initialization, and key dependencies. Protected playback renders a filtered master without modifying published playlists. Persist this selected inventory instead of the full `playback()` inventory when a viewer may access only those variants.

To combine completed presentations for the same timeline without copying media, see [`PendingHlsMerge`](REWRITE.md#compose-completed-hls-presentations). It requires compatible completed v9 inventories and publishes only a new version-suffixed master. The result owns that master and borrows existing media. Keep the source inventories and application reference counts before deleting any shared files.

#### Encryption and publication

MPEG-TS is the default packaging. Custom paths are disk-relative, with playlist references relative to each containing playlist. Keys live on an explicitly selected private disk and publish before media. A supplied `key:` must contain 16 raw bytes and cannot combine with rotation. Generated keys are fresh for each save; rotation groups share keys across variants. Every segment has an independent random IV.

Your application authorizes key requests and serves the 16 raw bytes. AES-128 is not DRM. OpenSSL is required for encryption. Segments precede playlists, with the master last. All expected artifacts validate before publication, but writes are not transactional.

#### Customizing playlists

The `playlistUsing` method accepts a callback that can add comments and supported non-resource tags to a generated playlist:

```php
$media->hls()
    ->variant('720p', fn ($variant) => $variant->resize(height: 720))
    ->playlistUsing(fn (string $text, string $path, ?string $variant) => str_replace("#EXTM3U\n", "#EXTM3U\n# Lesson playlist\n", $text))
    ->save('master.m3u8');
```

Playlist hooks can add comments and supported non-resource tags. They cannot remove or change generated timing, references, stream attributes, or encryption directives.

### fMP4 and alternate HLS groups

Call the `fmp4` method to package HLS as fragmented MP4. You can define shared audio and subtitle groups, then reference those groups from each variant:

```php
$result = $media->hls(segmentDuration: 6)
    ->fmp4()
    ->video('libx264', bitrate: '2500k')
    ->twoPass()
    ->variant('360p', fn (HlsVariant $variant) => $variant
        ->resize(height: 360)
        ->video(bitrate: '800k')
        ->audioGroup('sound')
        ->subtitlesGroup('captions'))
    ->variant('720p', fn (HlsVariant $variant) => $variant
        ->resize(height: 720)
        ->audioGroup('sound')
        ->subtitlesGroup('captions'),
        playlist: 'video/720p/index.m3u8', segments: 'video/720p/part-%03d.m4s',
        init: 'video/720p/start.mp4')
    ->audioRendition('sound', 'English', fn (HlsVariant $rendition) => $rendition
        ->map('0:a:0')
        ->audio('aac', bitrate: '128k'), language: 'en', default: true)
    ->audioRendition('sound', 'Español', fn (HlsVariant $rendition) => $rendition
        ->map('0:a:1')
        ->audio('aac', bitrate: '128k'), language: 'es')
    ->subtitleRendition('captions', 'English', fn (HlsVariant $rendition) => $rendition
        ->map('0:s:0'), language: 'en', default: true)
    ->toDisk('streams')
    ->save('lesson/master.m3u8');

$result->audioRendition('sound', 'English')
    ->playlist();
$result->subtitleRendition('captions', 'English')
    ->files();
$result->files()
    ->where('role', 'hls-init');
```

Each audio rendition encodes once and can serve several variants. Renditions require one explicit map of their declared type. Variants with an audio group omit muxed audio. Subtitle renditions use one full-presentation WebVTT segment and require known duration without a complex timing graph. Shared subtitle groups require aligned variant presentation clocks.

fMP4 owns one initialization file per audio/video playlist and uses `.m4s` segments. AES-128 encryption is supported only with MPEG-TS and is rejected with fMP4 or alternate subtitles. Referencing variants and renditions require matching clips. `twoPass()` measures bitrate-configured video variants without producing first-pass HLS files. Init files and media publish before playlists. Dynamic responses rewrite init references through `mediaUrlsUsing()` and traverse alternate playlists through `playlistUrlsUsing()`.

### DASH and shared HLS segments

The `dash` method creates a DASH presentation. Define adaptation sets for video and audio, then add representations to each set. To publish HLS playlists that reuse the same media segments, call `sharedHls`:

```php
$result = $media->dash(segmentDuration: 2)
    ->adaptationSet('picture', 'video')
    ->adaptationSet('sound', 'audio')
    ->representation('low', 'picture', fn ($video) => $video
        ->map('0:v:0')
        ->resize(width: 640)
        ->video(bitrate: '800k'))
    ->representation('high', 'picture', fn ($video) => $video
        ->map('0:v:0')
        ->video(bitrate: '2500k'))
    ->representation('audio', 'sound', fn ($audio) => $audio
        ->map('0:a:0')
        ->audio(bitrate: '128k'))
    ->sharedHls()
    ->toDisk('videos')
    ->save('course/manifest.mpd');

$result->manifest();
$result->hlsPlaylist(); // Available only with sharedHls().
$result->playback();    // Protected HLS inventory; requires sharedHls().
$result->representation('low')
    ->files();
$result->adaptationSets();
```

DASH supports finite, clear H.264/AAC presentations. Each representation maps exactly one stream into a declared adaptation set. Video boundaries align within a set. All representations use the same accurate clip. The builder owns codecs, muxers, and GOP alignment. Live DASH, two-pass encoding, encryption, DRM, and protected playback descriptors are not supported.

One encoding invocation creates the representations. With `sharedHls()`, both formats reference the same initialization files and media segments. The package validates bounded XML and the complete owned inventory before publication. Segments publish before playlists, with the MPD last. Publication across objects is not atomic. Results report `dash-init`, `dash-segment`, `dash-manifest`, and optional HLS roles. Ordinary queued exports, execution limits, cancellation, progress, and completion events apply.

With `sharedHls()`, `playback()` returns an `HlsPresentation` for the [protected HLS routes](#serve-protected-hls-with-laravel) and the queued completion event carries it. The inventory contains the HLS master, its playlists, and the shared media, but not the MPD. Each master entry is a variant named after its representation, so `playbackForVariants(['low'])` keeps that video representation with every audio rendition. The MPD itself has no protected route, and `mergeHls()` does not accept DASH output.

### Publish continuous HLS

Use the `liveHls` method to publish a sliding HLS playlist while FFmpeg is running. Configure the destination, reconnect policy, and session timeout before calling `run`:

```php
$session = FFMpeg::liveHls($trustedCameraUrl, segmentDuration: 2, window: 6)
    ->toDisk('videos')
    ->retry(maxRetries: 3, delay: 1)
    ->timeout(3600)
    ->cancelWhen(fn () => $this->shouldStop())
    ->onPublish(function ($snapshot) {
        // The playlist and its referenced segments are readable now.
    });

$result = $session->run('live/unique-session-id');

// Realtime playback of a finite input uses the same lifecycle.
$result = $media->liveHls(segmentDuration: 2, window: 6)
    ->toDisk('videos')
    ->run('live/another-session-id');
```

`run()` blocks and publishes a single H.264/AAC MPEG-TS rendition while FFmpeg remains alive. The playlist slides over `window` segments, with one extra destination window retained for stale readers. The result contains the playlist path, current segment paths, status, attempt count, cumulative published count, and first/last sequence numbers.

`stop()` or `cancelWhen()` returns `cancelled`. An explicit session timeout returns `timed_out`. Both preserve the last partial playlist without `ENDLIST`. Clean EOF returns `completed` with `ENDLIST`. `retry()` has a finite reconnect budget. Set `reconnectOnEof: true` to reconnect clean EOF as well. Encoder restarts inside one session retain monotonic sequence numbers and discontinuities. Storage and exhausted-source failures throw sanitized `MediaException` with a bounded published inventory. Global process deadlines remain hard failure limits.

> [!WARNING]
> Use a fresh empty namespace for every session. Your application must supervise the worker and guarantee one writer per namespace. Storage must support atomic replacement of individual objects. Restarting a PHP worker cannot resume a prior session.

Continuous HLS does not support adaptive master playlists, fMP4, encryption, or low-latency HLS.

Live URLs accept trusted HTTP, HTTPS, or RTSP sources without user information. Restrict reachable hosts in your application, because URL validation does not prevent SSRF. `openRtsp()` remains the separate bounded-snapshot API. Local realtime ingestion and HTTP ingestion have native acceptance tests. Successful RTSP live ingestion still requires deployment testing.

### Serve protected HLS with Laravel

The `Hls` facade registers signed playback routes for your application. Playback inventories identify the files a route can serve, while your authorization callback determines who can access them.

#### Storing the playback inventory

Configure named private Storage disks for media and keys. After publishing, persist the root result's playback inventory on the lesson for the full presentation:

```php
// In App\Models\Lesson. Add a JSON column named hls_playback in your migration.
protected function casts(): array
{
    return ['hls_playback' => 'array'];
}
```

```php
// After the HLS export above completes:
$lesson->hls_playback = $result->playback()
    ->toArray();
$lesson->save();
```

#### Registering playback routes

Register the definition and explicit model binding in your service provider's `boot()` method:

```php
use App\Models\Lesson;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
use ProtoneMedia\LaravelFFMpeg\Support\Hls;

// Inside AppServiceProvider::boot():
Route::model('lesson', Lesson::class);
Hls::define('lesson',
    resolve: fn (Lesson $lesson) => HlsPresentation::fromArray($lesson->hls_playback),
    authorize: fn (Lesson $lesson) => Gate::authorize('view', $lesson),
);
```

The `authorize` callback is mandatory. Define your lesson's `view` policy before serving playback. Laravel does not infer implicit model bindings from these callbacks, so register `Route::model()` explicitly for each model parameter.

Register the controller route in `routes/web.php`:

```php
use ProtoneMedia\LaravelFFMpeg\Support\Hls;

Hls::routes('/lessons/{lesson}/hls', name: 'lessons.hls', playback: 'lesson');
```

#### Generating playback URLs

Issue an entry URL from an authorized controller:

```php
Gate::authorize('view', $lesson);
$url = Hls::url('lessons.hls', ['lesson' => $lesson], expires: now()
    ->addMinutes(10));
```

`Hls::routes()` defaults to `['web', 'auth']`. The route action is a controller, so `php artisan route:cache` works. Definition closures live in the service provider, not in cached route actions. Keep any application configuration closures out of cached route actions too.

Every child playlist, segment, init file, and key gets a signed URL with the entry URL's exact deadline. Requests do not renew expiry. Child URLs preserve validated signed query context, such as grants or editions, with route parameters taking precedence. Every request validates its signature and runs the definition's authorization callback again. The controller serves only inventory-owned files through opaque resource IDs, never arbitrary disk paths. Responses use `private, no-store` and `nosniff`. Media supports single byte ranges and `HEAD`; keys return exactly 16 raw bytes, not hex or Base64.

For single-variant playback, `$result->variant('360p')->playback()` also retains the private-key references it needs internally. `variant('360p')->files()` still excludes shared key files. Prefer the root result when persisting the full presentation.

> [!WARNING]
> Keep the inventory server-side. Never restore it from request data or serialize it into a public lesson response. Keep published media and keys private and immutable. Publish replacement exports to new paths and replace the stored inventory instead of changing files under an active inventory.

Playback requires named disks, not anonymous filesystem instances.

If Sanctum is installed, replace middleware with `middleware: ['api', 'auth:sanctum']`. For deliberate bearer-URL playback, use `middleware: ['web']` only when URL issuance stays behind an authorized endpoint. In that mode, URLs are bearer credentials, not proof of a signed-in user. There is no automatic global authentication. The definition's mandatory `authorize` callback must enforce your intended access policy on every request, including requests without a session.

CORS and trusted proxy configuration remain your application's responsibility. Signed playback and AES-128 encryption are not DRM.

### Customize raw HLS responses

Use the lower-level playlist API when you need application-owned URL rewriting instead of the protected playback controller:

```php
// Inside an authorized controller. Resolve the path from this lesson's owned inventory.
return FFMpeg::disk('streams')
    ->playlist($path)
    ->playlistUrlsUsing(fn (?string $target, string $source, string $uri) => $target === null ? $uri : route('lesson.playlist', ['lesson' => $lesson->id, 'path' => $target]))
    ->mediaUrlsUsing(fn (?string $target, string $source, string $uri) => $target === null ? $uri : Storage::disk('streams')
        ->temporaryUrl($target, now()
            ->addMinutes(10)))
    ->keyUrlsUsing(function (?string $target, string $source, string $uri) use ($lesson) {
        $key = $lesson->keys()
            ->where('playlist_uri', $uri)
            ->firstOrFail();

        return route('lesson.key', ['lesson' => $lesson->id, 'key' => $key->id]);
    });
```

Resolvers receive the canonical disk target, containing playlist key, and original URI. HTTP(S) and root-relative references have a `null` disk target. Responses implement Laravel `Responsable` with the HLS content type and `private, no-store`. The application owns authorization, scoped signatures, key lookup, CORS, and cache policy. Never expose a generic disk/key download route.

```php
$playlist = FFMpeg::disk('streams')
    ->playlist('lessons/42/master.m3u8');
$text = $playlist->get(); // Selected playlist only.
$all = $playlist->all();  // Local playlists keyed by their original disk paths.
```

Rendering never fetches external URLs or reads media or keys. Repeated calls generate fresh signed URLs. Traversal and input/output sizes are bounded.

## Running exports

Synchronous exports return generated-file inventories. Queued exports return Laravel dispatch objects and deliver their inventories through completion events.

### Progress, results, and failures

To receive progress updates for an export, pass a callback to the `onProgress` method. When the export completes, the result provides access to its generated files and redacted process summaries:

```php
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;

$export = $media->export()
    ->onProgress(function (Progress $progress) {
        if ($progress->isEncoding()) {
            // percentage, speed, and remainingSeconds can be null.
            // pass and totalPasses identify video encoding passes.
        }
        // Throw ExportCancelled to stop and reap the encoder before cleanup.
    });

try {
    $result = $export->video('libx264')
        ->audio('aac')
        ->save('lesson.mp4');
    $result->file()
        ->path;
    $result->file('lesson.mp4', disk: 'uploads');
    $result->files();
    $result->processes(); // Bounded process summaries.
    $stream = $result->file()
        ->readStream(); // The caller closes this stream.
} catch (MediaException $exception) {
    // phase, category, diagnostics, published, and destination.
}

$analysis = $media->analyze('volumedetect', audio: 0);
$analysis->successful();
$analysis->errorOutput();
$analysis->throw(); // Opt in to throwing on a nonzero analysis exit.
```

`save()` throws on encoding, validation, or publication failure. Analysis-only requests retain inspectable exit status. Lifecycle events are `ExportStarting`, `ExportProgress`, `ExportCompleted`, `ExportFailed`, and `ExportCancelled`. Completion follows publication and workspace cleanup. Progress callback and synchronous progress-listener exceptions propagate unchanged.

#### Listening for export events

To observe exports without configuring each builder, register a listener for the `ExportProgress` event:

```php
use Illuminate\Support\Facades\Event;
use ProtoneMedia\LaravelFFMpeg\Events\ExportProgress;

Event::listen(ExportProgress::class, function (ExportProgress $event) {
    $operationId = $event->operationId;
    $outputs = $event->outputs; // Destination disk names (or null) and paths, not input URLs.
    $progress = $event->progress; // The same structured Progress value as onProgress().
    // Broadcast an application event or update application-owned progress state.
});
```

Progress events cover staging, probing, encoding, validation, and publication, including updates with unknown percentages. They work for ordinary exports, HLS, and queued conversion parts. Each operation has its own ID; use `QueuedExportCompleted` for whole-workflow completion. Worker cancellation is checked before callbacks and progress listeners. Synchronous listeners can cancel by throwing `Exceptions\ExportCancelled`. Asynchronous listeners observe progress but cannot block or cancel encoding.

Set `FFMPEG_ENABLE_EVENTS=false` or `laravel-ffmpeg.enable_events` to `false` to suppress all package export events, including `QueuedExportCompleted`. Per-export callbacks, cancellation checks, publication, and Laravel queue chains still run. Keep events enabled if your application relies on listeners to retain completion inventories.

#### Handling partial publication

Failures report successfully published siblings; they do not delete them. Existing objects may already have been replaced. Temporary workspaces and pass statistics are operation-owned and cleaned on success, failure, or cancellation. No global cleanup call is needed.

#### Deleting generated files

After checking authorization and references from other presentations, you can delete the files an export owns using its generated-file inventory:

```php
// Only after application authorization and checks for other users of these files:
foreach ($result->files() as $file) {
    if ($file->owned && ! $file->delete()) {
        throw new \RuntimeException('Cannot delete '.$file->path);
    }
}
```

Use the root result's inventory, not a variant view, for complete HLS deletion. `GeneratedFile::delete(): bool` deletes exactly that destination through its captured filesystem, not a directory or prefix. A borrowed file with `owned=false` returns `false` without touching Storage. Filesystem exceptions propagate. The application owns authorization and reference tracking; an owned file can still be referenced by another presentation. Deletion is not transactional and does not revoke cached client copies.

Retain the trusted generated-file inventory when publishing HLS. Reopening a master playlist alone does not establish ownership of its segments or keys. Merged presentations can borrow shared artifacts that must survive deletion of the merged master.

For older playlists without a retained result, `deleteAllFiles()` discovers the opened master, nested playlists, segments, initialization files, and keys on that same disk. Its required callback must approve every unique canonical disk path before any deletion begins:

```php
// Authorize the presentation first and prevent concurrent playlist changes.
// Load $exclusivePaths from application-owned records, not request parameters.
$approvedPaths = array_fill_keys($exclusivePaths, true);
FFMpeg::disk('streams')
    ->playlist('lesson/master.m3u8')
    ->deleteAllFiles(fn (string $path): bool => isset($approvedPaths[$path]));
```

Resolvers are ignored during deletion. External or root-relative references and invalid playlists fail before deletion. A refused path aborts the whole approval phase, protecting shared files. This operation cannot discover keys stored on another disk. Adapter failures throw and can leave partial deletion; authorization, concurrent-access control, reference tracking, and retry policy remain application responsibilities. Prefer the retained root inventory when it is available.

#### Limit an operation's execution time

Use the `timeout` method to limit the total execution time of an operation. Use `idleTimeout` to limit how long an individual child process can run without producing output:

```php
$result = $media->export()
    ->timeout(900)
    ->idleTimeout(30)
    ->video('libx264', bitrate: '2500k')
    ->twoPass()
    ->save('lesson.mp4');
```

`timeout()` sets one cumulative budget in seconds for this execution, including probing, analysis, both encoding passes, validation, and publication. Each new execution starts a fresh budget. `idleTimeout()` limits how long an individual child may produce no output. Both settings must be positive and finite. Passing `null` removes the operation override. These methods do not change shared Laravel configuration.

The configured `timeout` and `idle_timeout` remain per-process maximums. The first applicable limit wins. A timeout stops and reaps a running child before workspace cleanup. Storage transfers and application callbacks are synchronous; budgets are checked at their boundaries, not by interrupting an adapter or callback midway. Configure network timeouts on the relevant adapters. Files already published remain in the exception's `published` inventory.

### Queue conversions

Use `queued()` after configuring an export. It captures the builder's current settings without staging inputs or starting FFmpeg. `save()` returns Laravel's `PendingDispatch`, not an `ExportResult`.

```php
$pending = FFMpeg::disk('uploads')
    ->open('lesson.mp4')
    ->export()
    ->toDisk('media')
    ->video('libx264', preset: 'fast')
    ->audio('aac')
    ->queued(connection: 'redis', queue: 'conversions', delay: 10)
    ->configureJob(function ($job) {
        $job->tries = 3;
        $job->backoff = [30, 120];
        $job->timeout = 1800;
    });

$exportId = $pending->id;
$pending->save('lessons/encoded.mp4')
    ->afterCommit();
```

Inputs, resources, and destinations must use named disks that every worker can access. Store uploads first. `File`, `UploadedFile`, filesystem objects, and open streams are rejected before dispatch. Worker callbacks must be static closures with scalar or array captures. Resolve services inside the callback rather than capturing a request or service instance. Jobs implement `ShouldBeEncrypted`, so workers need the application's encryption key.

#### Timeouts and retries

The default job timeout is the operation's `timeout()`, or the configured FFmpeg timeout, plus 30 seconds. Without either limit it is zero, which leaves the worker limit to Laravel. Set a larger job timeout for operations with several process invocations when no cumulative operation budget is set.

> [!WARNING]
> Set the queue connection's `retry_after` or visibility timeout above the job timeout. Retries restart a conversion, not a media checkpoint. Laravel queues provide at-least-once delivery, not exactly-once publication.

The published `queue` configuration can set `connection`, `queue`, `tries`, `backoff`, and `timeout` defaults. Explicit `queued(connection:, queue:)` values take precedence over those defaults. `configureJob()` runs last, including for parallel HLS parts and the finalizer. Operation deadlines are captured in the queued plan and start in the worker, not while the job waits.

A hard worker kill cannot run PHP cleanup and can leave a private workspace behind. Use an application-managed retention policy for stale workspaces, without deleting workspaces used by active jobs. Retry recovery does not reclaim an earlier attempt's workspace. For concurrent workers using SQLite batch metadata, configure WAL and a busy timeout, and set `transaction_mode` to `'IMMEDIATE'` to avoid deferred read-to-write transaction upgrades. Test the batch database configuration used by your deployment.

#### Job chains and batches

Use `job()` instead of `save()` to build a job without dispatching it. Ordinary export jobs work with Laravel chains and batches:

```php
use Illuminate\Support\Facades\Bus;

$job = FFMpeg::disk('uploads')
    ->open('lesson.mp4')
    ->frame(2)
    ->toDisk('media')
    ->queued()
    ->job('lessons/preview.jpg');

Bus::chain([$job, new \App\Jobs\NotifyLessonReady($lessonId)])
    ->onConnection('redis')
    ->onQueue('conversions')
    ->dispatch();
```

`configureJob()` also accepts Laravel's `through()` middleware and `afterCommit()`. Global Laravel queue-failure listeners handle exhausted attempts. Batch cancellation skips work that has not started and stops running encoders at the next progress check. Cancellation cannot roll back files already published.

#### Run independent HLS conversions in parallel

To encode independent HLS variants on separate workers, pass `parallel: true` to the `queued` method:

```php
FFMpeg::disk('uploads')
    ->open('lesson.mp4')
    ->hls(segmentDuration: 4)
    ->toDisk('media')
    ->video(preset: 'fast')
    ->audio('aac')
    ->variant('small', fn ($v) => $v->resize(width: 640)
        ->video(bitrate: '800k'))
    ->variant('large', fn ($v) => $v->resize(width: 1280)
        ->video(bitrate: '2400k'))
    ->queued(connection: 'redis', queue: 'conversions', parallel: true)
    ->save('lessons/master.m3u8');
```

Parallel HLS requires Laravel's batch repository and several workers for concurrent execution. Independent variant groups become separate jobs. Variants that share alternate audio or subtitle groups stay together, so shared renditions encode once. If all variants belong to one group, one ordinary queued export runs instead.

Each group publishes under `laravel-ffmpeg/queued/{exportId}/parts/` on the destination disk. These files remain live media, not temporary files. Custom artifact paths are placed beneath that group prefix. A separate finalizer verifies the completed inventories and publishes a versioned master such as `lessons/master.<version>.m3u8`; the unversioned path stays untouched. Use the completion event's playback inventory rather than guessing the master filename. A failed or cancelled conversion prevents final publication and downstream chain jobs. Completed parts remain available for application-managed retention and cleanup.

Parallel exports reject custom playlist callbacks, complex graphs, and unreferenced alternate groups. Use one queued export for those operations. Compose a parallel workflow with `Bus::chain()`, not inside another `Bus::batch()`. `configureJob()` applies to the coordinator, conversions, and finalizer. Add downstream jobs through `save()->chain()` or `Bus::chain()`, not inside that callback. Keep batch records until finalization finishes.

#### Retain queue results

Queued exports deliver their generated-file and playback inventories through the `QueuedExportCompleted` event. Register a listener to persist these inventories in your application:

```php
use Illuminate\Support\Facades\Event;
use ProtoneMedia\LaravelFFMpeg\Events\QueuedExportCompleted;

Event::listen(QueuedExportCompleted::class, function ($event) {
    // Persist by exportId in your application's own job or listener.
    // files contains disk, path, size, role, and owned for each artifact.
    // playback contains a serializable protected-HLS inventory, or null.
    \App\Models\Conversion::where('export_id', $event->exportId)
        ->update([
            'files' => $event->files,
            'playback' => $event->playback,
        ]);
});
```

Completion follows publication. Listeners must tolerate duplicate delivery. Parallel composition marks its source artifacts as borrowed, just like `mergeHls()`. Retain the part inventories for ownership-aware cleanup. No queue database tables or application result models are created by this package.

See [application integration recipes](docs/integrations.md) for versioned profiles, durable inventories, stale-result protection, application tests, and Plank media associations. Media-library dependencies and database schemas remain application-owned.

## Deployment and development

### Configuration and security

Configuration is published as `config/laravel-ffmpeg.php`. Total and idle deadlines are distinct and use seconds. Output validation is not a hostile-media sandbox. Untrusted inputs require operating-system isolation and resource limits. Credential redaction is opt-in, and direct signed URLs always remain visible in process arguments.

#### Credential redaction

Credential redaction is disabled by default. To enable it, set `FFMPEG_REDACT_CREDENTIALS=true` or the `redact_credentials` configuration option:

```php
'redact_credentials' => env('FFMPEG_REDACT_CREDENTIALS', false),
```

When redaction is enabled, the package replaces the following strings in `MediaException` diagnostics, process summaries and commands, `analyze()` output, and `probe()` and `probeQuery()` results:

| String | Replacement |
| --- | --- |
| The whole `openUrl()` URL | `[url]` |
| The whole RTSP URL | `[rtsp]` |
| The whole query string | `[query]` |
| A query value whose name contains `token`, `sig`, `key`, `auth`, `pass`, `secret`, `credential`, or `policy` | `[redacted]` |
| Any other query value of 16 or more characters | `[redacted]` |
| The RTSP `user:password` pair, and the password alone | `[redacted]` |

Query values are matched in raw and URL-decoded form. Shorter query values with other names, such as `dl=1`, and RTSP usernames are kept, because the same text also occurs inside numbers and metadata. Replacement is a plain string match that tries the longest string first. It never changes the arguments passed to FFmpeg.

Input placeholders such as `[input]` and `[input:0]` replace staged paths and direct URL arguments whether or not redaction is enabled.

Use `inspect()` for pure argument inspection, `globalOptions()`, `outputOptions()`, and `inputOptions:` for supported scoped options. Reserved filenames, binaries, progress transport, and statistics paths cannot be overridden. There is no driver forwarding or arbitrary before-save command mutation.

### Testing and development

To prepare a development environment and run the test suite, use the repository's setup script and PHPUnit:

```bash
.agents/setup
FFMPEG_9_FFPROBE=/opt/ffmpeg-9.0.2/bin/ffprobe vendor/bin/phpunit --order-by random \
    --display-deprecations --display-phpunit-deprecations \
    --fail-on-deprecation --fail-on-phpunit-deprecation
```

Setup installs the toolchain and dependencies once; resume checks them without reinstalling. CI uses Ubuntu 26.04, PHP 8.4/8.5, Laravel 12/13, and lowest/stable dependencies. Real-media acceptance requires the matching tested binary pair.

## Community

### Sponsor us

[<img src="https://inertiaui.com/visit-card.jpg" alt="Inertia Table" />](https://inertiaui.com/inertia-table?utm_source=github&utm_campaign=laravel-ffmpeg)

Support maintenance through [GitHub Sponsors](https://github.com/sponsors/pascalbaljet), and see [Inertia Table](https://inertiaui.com/inertia-table?utm_source=github&utm_campaign=laravel-ffmpeg).

### Contributing and security

See [CONTRIBUTING](CONTRIBUTING.md) and [CHANGELOG](CHANGELOG.md). Report security issues to code@protone.media, not the public issue tracker.

### Credits

- [Pascal Baljet](https://github.com/pascalbaljet)
- [All contributors](../../contributors)

### License

The [MIT license](LICENSE.md).

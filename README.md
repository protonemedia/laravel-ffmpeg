# Laravel FFmpeg

[![Latest Version on Packagist](https://img.shields.io/packagist/v/pbmedia/laravel-ffmpeg.svg?style=flat-square)](https://packagist.org/packages/pbmedia/laravel-ffmpeg)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Tests](https://github.com/protonemedia/laravel-ffmpeg/actions/workflows/run-tests.yml/badge.svg?branch=9.x&event=push)](https://github.com/protonemedia/laravel-ffmpeg/actions/workflows/run-tests.yml?query=branch%3A9.x)
[![Total Downloads](https://img.shields.io/packagist/dt/pbmedia/laravel-ffmpeg.svg?style=flat-square)](https://packagist.org/packages/pbmedia/laravel-ffmpeg)

Laravel FFmpeg provides a fluent API for encoding video, processing audio, and publishing adaptive streams. It is built on Laravel's Process, Storage, Http, and Queue features, so it works with your existing disks and queue connections:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$result = FFMpeg::open('lessons/original.mp4')
    ->export()
    ->resize(width: 1280)
    ->video('libx264', crf: 23)
    ->audio('aac', bitrate: '128k')
    ->save('lessons/720p.mp4');
```

You may run an export immediately or [dispatch it to a queue](#queue-conversions), and each completed export lists its generated files, including playlists, segments, and keys.

> [!IMPORTANT]
> Version 9 is in development; these examples follow the `9.x` branch, not a published release. It replaces PHP-FFMpeg and removes its format and filter objects, so consult the [upgrade guide](UPGRADE.md) when upgrading.

- [Getting Started](#getting-started)
    - [Installation](#installation)
- [Working With Media](#working-with-media)
    - [Encoding and Copying](#encode-and-copy)
    - [Opening and Probing Media](#inputs-and-probing)
    - [Selecting Tracks and Metadata](#tracks-and-metadata)
- [Transforming Media](#transforming-media)
    - [Geometry and Filters](#geometry-and-filters)
    - [Frames, Timelapses, and Storyboards](#frames-timelapse-and-storyboards)
    - [Changing Playback Speed and Making GIFs](#change-playback-speed-and-make-gifs)
    - [Mixing Audio and Adding Background Music](#mix-audio-and-add-background-music)
    - [Waveform Peaks Data](#waveform-peaks-data)
    - [Two-Pass Loudness Normalization](#two-pass-loudness-normalization)
    - [Adding a Watermark](#add-a-watermark)
    - [Joining Clips](#join-clips)
    - [Composing Multiple Inputs and Outputs](#compose-multiple-inputs-and-outputs)
- [Streaming](#streaming)
    - [Adaptive HLS and Encryption](#adaptive-hls-and-encryption)
    - [fMP4 and Alternate HLS Groups](#fmp4-and-alternate-hls-groups)
    - [DASH and Shared HLS Segments](#dash-and-shared-hls-segments)
    - [Combining Completed HLS Presentations](#combine-completed-hls-presentations)
    - [Publishing Continuous HLS](#publish-continuous-hls)
    - [Serving Protected HLS](#serve-protected-hls-with-laravel)
    - [Customizing Raw HLS Responses](#customize-raw-hls-responses)
- [Running Exports](#running-exports)
    - [Progress, Results, and Failures](#progress-results-and-failures)
    - [Queueing Conversions](#queue-conversions)
- [Deployment and Development](#deployment-and-development)
    - [Configuration and Security](#configuration-and-security)
    - [Testing and Development](#testing-and-development)
- [Community](#community)
    - [Sponsor Us](#sponsor-us)
    - [Contributing and Security](#contributing-and-security)
    - [Credits](#credits)
    - [License](#license)

<a name="getting-started"></a>
## Getting Started

<a name="installation"></a>
### Installation

Laravel FFmpeg requires PHP 8.4 or later, Laravel 13.20 or later, and matching FFmpeg and FFprobe 9.0.x binaries; setup and CI use 9.0.2. You may install the development branch using Composer:

```bash
composer require pbmedia/laravel-ffmpeg:9.x-dev
```

Laravel discovers the service provider and facade automatically. To edit watermark images, you should also install [Intervention Image](https://image.intervention.io), which powers [Laravel's image manipulation](https://laravel.com/docs/13.x/images):

```bash
composer require intervention/image:^4.2
```

<a name="publishing-the-configuration"></a>
#### Publishing the Configuration

To customize binary paths, timeouts, or queue defaults, you may publish the `config/laravel-ffmpeg.php` file using the `vendor:publish` Artisan command:

```bash
php artisan vendor:publish --provider="ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider" --tag=config
```

Binaries are validated only when used, so configuration caching never launches FFmpeg. Set the executable paths and an existing, writable temporary directory in your environment:

```dotenv
FFMPEG_BINARIES=/opt/ffmpeg/bin/ffmpeg
FFPROBE_BINARIES=/opt/ffmpeg/bin/ffprobe
FFMPEG_TEMPORARY_FILES_ROOT=/var/tmp/media
```

Your build must include the encoders, filters, and protocols your application uses; the repository's `.agents/install-ffmpeg` script builds the tested pair.

<a name="find-and-verify-the-executables"></a>
#### Finding and Verifying the Executables

By default, `ffmpeg` and `ffprobe` are resolved through the process's `PATH`, which may differ between your shell, web server, and queue workers:

```bash
command -v ffmpeg
command -v ffprobe
```

Set `FFMPEG_BINARIES` and `FFPROBE_BINARIES`, or the `ffmpeg.binaries` and `ffprobe.binaries` options, to full executable paths. Both must report the same 9.0.x release, so test them as your web server or queue worker user:

```bash
sudo -u www-data /opt/ffmpeg-9.0.2/bin/ffmpeg -version
sudo -u www-data /opt/ffmpeg-9.0.2/bin/ffprobe -version
```

If this succeeds but the application fails, check permissions and write access to the temporary root and Storage destinations. After changing paths, rebuild the configuration cache and restart queue workers.

Failures throw a `MediaException` whose `phase`, `category`, and bounded `diagnostics` explain the cause: a launch failure means the executable could not start, a version failure means an unsupported or mismatched pair, and an encoding failure means FFmpeg rejected the command or media. Categories name conditions, such as `metadata` for an unknown duration and `mapping` for a missing track.

> [!WARNING]
> Raw commands and unredacted process exceptions may contain credentials, so never publish them in logs or error responses.

<a name="inspect-capabilities"></a>
#### Inspecting Capabilities

The `ffmpeg:doctor` Artisan command checks matching versions, compiled-in support, and a temporary-workspace write, and exits nonzero when a repeatable `--encoder`, `--muxer`, `--filter`, `--protocol`, `--bsf`, or `--hwaccel` requirement is missing:

```bash
php artisan ffmpeg:doctor --encoder=libx264 --muxer=dash --filter=scale
php artisan ffmpeg:doctor --json
```

Within your application, the `capabilities` method captures a fresh report on each call:

```php
$capabilities = FFMpeg::capabilities();

$capabilities->has('encoders', 'libx264');
$capabilities->require(['encoders' => ['libx264', 'aac'], 'muxers' => ['dash']]);
$capabilities->toArray();
```

> [!NOTE]
> Compiled-in hardware support does not prove device access, driver compatibility, or encoder usability.

<a name="working-with-media"></a>
## Working With Media

<a name="encode-and-copy"></a>
### Encoding and Copying

To encode a video, open the source, call the `export` method, and configure the codecs. Each call to `export` creates an independent configuration:

```php
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

<a name="copying-streams"></a>
#### Copying Streams

To change only the container, pass `copy` to the `video` and `audio` methods. Copying requires a compatible container and cannot be combined with filters or accurate clipping:

```php
$media->export()
    ->video('copy')
    ->audio('copy')
    ->save('lesson.mkv');
```

<a name="configuring-video-quality"></a>
#### Configuring Video Quality

The `crf` argument sets constant quality, while the `bitrate` argument accepts positive integer bits per second, such as `2500000`, or a unit string, such as `'2500k'`, but not floats or unitless strings. The two may not be combined.

<a name="two-pass-encoding-and-clipping"></a>
#### Two-Pass Encoding and Clipping

When targeting a bitrate, you may call the `twoPass` method, which keeps private statistics for each output. The `clip` method cuts accurately by default, transcoding the affected streams, while `seek: 'keyframe'` allows stream copying but may start early:

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

Timeline changes omit chapters unless you map them explicitly.

<a name="inputs-and-probing"></a>
### Opening and Probing Media

The `open` method accepts a path on the default disk or an `UploadedFile` or `File` instance. You may select another disk using the `disk` method and open HTTP sources using the `openUrl` method:

```php
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;

$media = FFMpeg::open('lesson.mp4'); // Current filesystems.default.

$media = FFMpeg::disk(Storage::disk('s3'))
    ->open('lesson.mp4');

$media = FFMpeg::open($request->file('video')); // In a controller.

$media = FFMpeg::open(new File('/absolute/path/lesson.mp4'));

$public = FFMpeg::openUrl('https://cdn.example.com/lesson.mp4');

$private = FFMpeg::openUrl(
    'https://media.example.com/private/lesson.mp4',
    headers: ['Authorization' => 'Bearer '.config('services.media.token')],
    download: true,
);
```

Paths are never URLs or absolute local paths, and opened media keeps its disk if your configuration changes. Nothing is read until an export runs: local files are read in place, while other disks stream into a private workspace. Scoped and read-only input disks keep their boundaries, and publishing to a read-only destination fails.

> [!WARNING]
> HTTP inputs with credentials require `download: true`, which streams to a private file with deadlines, no redirects, and HTTPS verification. Otherwise the URL appears in FFmpeg's arguments, so never put reusable credentials in it. URL inputs publish to `filesystems.default` unless you call `toDisk`.

<a name="inspecting-media"></a>
#### Inspecting Media

The `probe` method returns the source's duration, streams, chapters, and other metadata:

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

<a name="caching-metadata"></a>
#### Caching Metadata

To cache a probe result, pass a cache repository, a versioned key, and a lifetime in seconds to the `probeCached` method:

```php
use Illuminate\Support\Facades\Cache;

$metadata = $media->probeCached(
    Cache::store('redis'),
    versionedKey: 'tenant:42:lesson:7:object-version:abc123',
    ttl: 600,
);
```

Change the `versionedKey` whenever the source bytes or object version change; ordinary exports still probe their staged inputs.

> [!WARNING]
> A cache hit neither checks Storage nor reauthorizes the source. Your application owns freshness, tenant isolation, access, and eviction, and should protect cached metadata like the source itself.

<a name="hardware-acceleration"></a>
#### Hardware Acceleration

To decode with hardware, pass the `inputOptions` argument when opening the source and select a matching encoder:

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

The `-hwaccel` option accepts `cuda`, `vaapi`, `qsv`, `vulkan`, `videotoolbox`, `d3d11va`, or `dxva2`, but not `auto`. Filters that consume CPU frames may need an explicit hardware download and format conversion.

> [!NOTE]
> Hardware is never selected automatically and has no CPU fallback. This example needs a CUDA-enabled build, an NVIDIA device, and a compatible decoder and encoder; hardware execution is not validated in the test environment.

<a name="capturing-camera-snapshots"></a>
#### Capturing Camera Snapshots

The `openRtsp` method returns an `RtspCamera` for bounded snapshots, and its `frame` method captures one frame into a private PNG that is then processed and published like any other image:

```php
FFMpeg::openRtsp(config('services.camera.url'), transport: 'tcp', timeout: 10)
    ->frame()
    ->resize(width: 640)
    ->toDisk('images')
    ->save('camera.jpg');
```

The transport may be `tcp` or `udp`, with a positive timeout of at most 300 seconds.

> [!WARNING]
> Camera URLs should be trusted configuration. Restrict reachable hosts and protect camera credentials, which may appear in process arguments.

<a name="query-frames-and-packets"></a>
#### Querying Frames and Packets

For frame or packet data, use the `probeQuery` method, which returns a `ProbeResult` rather than full-asset `Metadata`:

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

A query reads the first 10 seconds by default; seeking is approximate, and packet limits are not frame limits. Intervals are capped at 300 seconds, packets at 100,000, output at 16 MiB (default 4 MiB), and the cumulative timeout at 120 seconds (default 30), within global process limits and the input's staging, transport, and redaction rules.

<a name="tracks-and-metadata"></a>
### Selecting Tracks and Metadata

By default, an export selects the first motion-video and audio stream. The `mapVideo`, `mapAudio`, and `map` methods replace that selection; subtitles and cover art must be mapped explicitly with container-compatible codecs. Stream tags and dispositions address output order:

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

Each probed `Stream` offers the `language`, `isForced`, `isDefault`, and `isCoverArt` methods, plus raw fields through `get`.

<a name="attaching-cover-art"></a>
#### Attaching Cover Art

The `coverArt` method attaches a PNG or JPEG to an MP4, M4A, or MP3 while copying its tracks. It refuses sources with existing art and cannot be combined with encoding, filters, clipping, custom maps, or disposition overrides:

```php
$cover = FFMpeg::disk('assets')
    ->open('album.png');

$media->export()
    ->coverArt($cover)
    ->toDisk('videos')
    ->save('with-cover.mp4');
```

<a name="configuring-individual-video-tracks"></a>
#### Configuring Individual Video Tracks

The `videoStream` method configures one output video track, counted from zero after mapping. Each track may be configured once and overrides the `video` method's settings:

```php
$media->export()
    ->map('0:a:0')
    ->map('0:v:0')
    ->map('0:v:0')
    ->audio('copy')
    ->videoStream(0, fn ($track) => $track
        ->codec('libx264')
        ->crf(20)
        ->preset('medium'))
    ->videoStream(1, fn ($track) => $track
        ->codec('mpeg4')
        ->bitrate('800k'))
    ->save('two-video-tracks.mp4');
```

CRF and bitrate may not be combined, even through inherited settings, and per-track settings do not support two-pass encoding.

<a name="extracting-webvtt-subtitles"></a>
#### Extracting WebVTT Subtitles

To extract a text subtitle track as WebVTT, map it explicitly and select the `webvtt` codec. Speech recognition and bitmap subtitle conversion are not supported:

```php
$media->export()
    ->map('0:s:0')
    ->outputOptions(['-c:s', 'webvtt'])
    ->toDisk('captions')
    ->save('english.vtt');
```

<a name="configure-encoders-and-individual-audio-tracks"></a>
#### Configuring Encoders and Individual Audio Tracks

The `audioStream` method works the same way; here, the source's second audio track becomes the first output track:

```php
$media->export()
    ->map('0:v:0')
    ->map('0:a:1')
    ->map('0:a:0')
    ->video('libx264', crf: 22)
    ->audio('aac', bitrate: '128k')
    ->videoOptions(['-x264-params', 'keyint=48:scenecut=0'])
    ->color('bt709', 'bt709', 'bt709')
    ->audioStream(0, fn ($audio) => $audio
        ->sampleRate(48000)
        ->channels(2))
    ->audioStream(1, fn ($audio) => $audio
        ->bitrate('96k')
        ->filter('volume=0.5'))
    ->save('selected.mp4');
```

A missing output track fails before encoding, and only the callbacks' scalar settings are kept, not the closures. Audio tracks may set the codec, bitrate, sample rate, channels, sample format, channel layout, compression level, and filters, which run after shared filters.

The `videoOptions` and `audioOptions` methods accept curated pairs, such as numeric x264 and x265 parameters, selected VPx, AV1, and NVENC settings, and color signaling; unknown parameters and file-producing options are rejected. The `color` method only sets metadata, so use a color-conversion filter to change pixel values. The GIF, loudness, audio-mix, concat, and DASH builders have no per-track methods, and DASH representations reject per-track overrides.

The `outputOptions` method also accepts single-file muxer settings and argument-free bitstream filters, such as `['-bsf:v', 'h264_mp4toannexb']`, but not sidecar paths. The `globalOptions` method accepts named hardware device declarations, where a derived device must reference an earlier one:

```php
$export = $media->export()
    ->globalOptions([
        '-init_hw_device', 'vaapi=base:/dev/dri/renderD128',
        '-init_hw_device', 'qsv=derived@base',
        '-filter_hw_device', 'derived',
    ]);
```

<a name="transforming-media"></a>
## Transforming Media

You configure transformations on the builder returned by the `export` method. Filters run in the order you register them, while operations that own their filter graph follow the rules in their own sections.

<a name="geometry-and-filters"></a>
### Geometry and Filters

You may crop, rotate, pad, or fit a video to your output canvas:

```php
$media->export()
    ->video('libx264')
    ->audio('copy')
    ->crop(1280, 720, x: 40, y: 20)
    ->rotate(90)
    ->pad(800, 1280, background: 'black')
    ->save('portrait.mp4');
```

The `contain` and `cover` methods work like their [Laravel image](https://laravel.com/docs/13.x/images) counterparts and produce square pixels:

```php
$media->export()
    ->contain(1280, 720, background: 'black')
    ->video('libx264')
    ->save('contained.mp4');

$media->export()
    ->cover(1280, 720) // Center crop.
    ->video('libx264')
    ->save('covered.mp4');
```

Passing one dimension to `resize` preserves the display aspect ratio, while two dimensions stretch the video. Crop and pad take positive even dimensions and center the video unless you pass offsets, and `rotate` accepts clockwise quarter turns or their negative equivalents, applied after the input's autorotation. FFmpeg validates geometry that depends on the source.

Raw probe dimensions are the encoded size, but sizing follows the autorotated display, so a portrait video stays portrait:

```php
$media->export()
    ->resize(height: 1920)
    ->video('libx264')
    ->audio('copy')
    ->save('portrait-height.mp4');

$media->export()
    ->contain(1080, 1920) // Exact portrait canvas.
    ->video('libx264')
    ->audio('copy')
    ->save('portrait-canvas.mp4');
```

The export builder offers many other filters:

```php
$media->export()
    ->video('libx264')
    ->audio('aac')
    ->deinterlace() // Before resizing; mode: 'field' gives a frame per field.
    ->frameRate('30000/1001')
    ->flipHorizontally()
    ->grayscale()
    ->volume(0.5) // Linear multiplier; zero mutes.
    ->delayAudio(0.25)
    ->fadeIn(1)
    ->fadeOut(1, start: 9)
    ->save('treated.mp4');
```

The `frameRate` method drops or duplicates frames without changing playback speed, and `delayAudio` pads every channel with silence. Fades affect video and audio unless you pass `video: false` or `audio: false`. A `flipVertically` method is also available, and filtered streams must be encoded, not copied.

For other trusted FFmpeg expressions, you may use the `videoFilter` and `audioFilter` methods:

```php
$media->export()
    ->videoFilter('hqdn3d')
    ->audioFilter('highpass=f=200')
    ->video('libx264')
    ->audio('aac')
    ->save('filtered.mp4');
```

The `drawText` and `burnSubtitles` methods stage their files privately instead of interpolating paths into filter strings, so text is drawn literally:

```php
$font = FFMpeg::disk('assets')
    ->open('Brand.ttf');

$captions = FFMpeg::disk('assets')
    ->open('captions.srt');

$media->export()
    ->drawText('100%: café [literal]', $font, size: 24, x: 12, y: 18)
    ->burnSubtitles($captions)
    ->video('libx264')
    ->audio('copy')
    ->save('captioned.mp4');
```

> [!WARNING]
> Filters are trusted application code, not a sandbox for untrusted media.

<a name="frames-timelapse-and-storyboards"></a>
### Frames, Timelapses, and Storyboards

The `frame` method extracts a single image, which you may save or retrieve as bytes:

```php
$media->frame(at: 12.5)
    ->resize(width: 640)
    ->toDisk('images')
    ->save('poster.jpg');

$bytes = $media->frame(at: 12.5)
    ->get();
```

The `frames` method samples at an interval or as an evenly spaced count. Patterns need one integer placeholder:

```php
$media->frames(every: 2)
    ->resize(width: 320)
    ->outputOptions(['-q:v', '3']) // JPEG quality.
    ->save('frames/frame-%05d.jpg');

$media->frames(count: 10)
    ->save('frames/frame-%05d.png');
```

The `openSequence` method turns numbered images into a timelapse:

```php
FFMpeg::disk('images')
    ->openSequence('frame-%04d.png', count: 90, framerate: '30000/1001', start: 1)
    ->export()
    ->video('libx264')
    ->save('timelapse.mp4');
```

The `storyboard` method builds sprite sheets, and `vtt` writes WebVTT cues whose references are relative to the cue file:

```php
$media->storyboard(every: 5, columns: 5, rows: 3, margin: 2, padding: 1)
    ->resize(width: 160)
    ->vtt('cues/lesson.vtt')
    ->toDisk('images')
    ->save('sheets/lesson-%03d.jpg');
```

The `waveform` method renders a PNG, distinct from [waveform peaks data](#waveform-peaks-data). Its `colors` argument takes a name such as `'blue'` or an RGB or RGBA hex value prefixed with `#` or `0x`, or a list of them; a single string is never split into several colors:

```php
$media->waveform(width: 800, height: 120, colors: 'blue')
    ->clip(2, duration: 10)
    ->save('waveform.png');
```

<a name="creating-videos-from-images"></a>
#### Creating Videos From Images

The `still` method turns a PNG or JPEG into a video, such as a title card:

```php
FFMpeg::disk('images')
    ->open('title.png')
    ->still(duration: 5, frameRate: 25)
    ->resize(width: 1280)
    ->toDisk('videos')
    ->save('title.mp4');
```

It encodes H.264 with `yuv420p` by default and offers the export builder's video codec, geometry, filter, metadata, and publication methods, but no audio, clipping, or extra-input methods, since it creates no audio track. The image may come from any disk or a downloaded URL and needs even dimensions of at least two pixels and no input options. The frame rate is an integer from 1 to 240, and frame rounding can affect the duration.

<a name="change-playback-speed-and-make-gifs"></a>
### Changing Playback Speed and Making GIFs

The `speed` method changes playback speed without changing the audio pitch:

```php
$media->speed(2)
    ->save('double-speed.mp4');

$media->speed(0.5)
    ->save('half-speed.mp4');
```

The factor must be finite and positive. Audio must start at a nonnegative time and, when the source has video, the container and video must start at zero. Existing audio delays scale with the speed, while an audio-only source that starts later loses its leading offset. Stream copy, clipping, input options, custom graphs, watermarks, and multiple outputs are not supported, so encode a clip first to retime part of a source.

The `gif` method creates a looping preview from the first video stream, without audio:

```php
$media->gif(width: 480, frameRate: 12, loop: true)
    ->clip(4, duration: 3)
    ->toDisk('images')
    ->save('preview.gif');
```

Pass `loop: false` to play once, or `loops: 3` to repeat three times after the first play. Clipping runs before palette generation, and the builder has no map, graph, extra input or output, audio, encoder, or raw output methods.

> [!NOTE]
> Instead of buffering in memory, the `gif` method writes a private lossless intermediate file, which adds disk use and processing passes, so keep previews short and small.

<a name="mix-audio-and-add-background-music"></a>
### Mixing Audio and Adding Background Music

The `backgroundMusic` method mixes a quieter, looping track under a video's audio. The `at` method delays the music, and `loop: false` plays it once:

```php
$video = FFMpeg::open($request->file('video'));

$music = FFMpeg::disk('music')
    ->open('background.mp3');

// Start the music at 2.5 seconds, then repeat it at 20% volume until the video ends.
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
```

The `mixAudio` method sets both gains and plays the added track once unless you call `loop`:

```php
// Set both gains, divide the mixed signal by two, then fade the final audio.
$video->mixAudio($music, volume: 0.4, originalVolume: 0.8, normalize: true)
    ->fadeIn(1, video: false)
    ->fadeOut(2, start: 18, video: false)
    ->video('copy')
    ->audio('aac')
    ->save('mixed.mp4');
```

A mix combines the first audio stream of each input into stereo at 48000 Hz, so audio must be encoded, while video may be copied unless you filter it. The `volume`, `audioFilter`, and fade methods apply after mixing. A mix has one output and owns its graph and maps, so custom maps, graphs, watermarks, clips, and two-pass encoding are not supported.

<a name="timing-and-duration"></a>
#### Timing and Duration

The primary input sets the output length: a shorter added track ends in silence unless it loops, and a longer one is trimmed. A silent video receives only the added music, and audio-only inputs work too. The `at` offset delays only the added track from its first decoded sample, rounds to the nearest 48 kHz sample, and replaces any earlier offset. Loops do not repeat the initial silence, and an offset beyond the primary adds nothing.

Both sources need finite positive durations, and the added one must contain audio. A primary with video needs zero container and video origins and a known nonnegative audio origin. To loop URL audio, open it with `FFMpeg::openUrl($url, download: true)`.

<a name="mixing-levels"></a>
#### Mixing Levels

Gains are nonnegative amplitude multipliers. By default, the signals are added and can clip, while `normalize: true` halves both branches when original audio is present.

> [!NOTE]
> The `normalize` argument is not loudness normalization; use [`normalizeLoudness`](#two-pass-loudness-normalization) for that.

<a name="waveform-peaks-data"></a>
### Waveform Peaks Data

The `waveformPeaks` method computes minimum and maximum sample data for your own renderer. The `get` method returns it without publishing a file:

```php
$peaks = $media->waveformPeaks(samplesPerBucket: 256)
    ->audioTrack(1) // Zero-based audio track, not a stream index.
    ->clip(10, duration: 30)
    ->mono() // Mix channels with equal weights.
    ->buckets(1000) // At most 1,000 buckets.
    ->get();

$peaks->sampleRate;
$peaks->duration();
$peaks->channels; // Channel-major signed 16-bit [min, max] pairs.
$peaks->bucketFrames;
$peaks->toArray();
```

The `save` method publishes version 1 JSON and returns the same peaks, so you need no second run:

```php
$result = $media->waveformPeaks()
    ->buckets(2000)
    ->toDisk('waveforms')
    ->save('lesson.json');

$result->peaks;
```

Results are capped at 100,000 channel-buckets, and the private PCM file is deleted after the operation.

<a name="two-pass-loudness-normalization"></a>
### Two-Pass Loudness Normalization

The `normalizeLoudness` method measures one audio track, then normalizes it to your target in a single audio-only output:

```php
$result = $media->normalizeLoudness(integrated: -18, truePeak: -2, range: 7,
    linear: true, sampleRate: 48000, audio: 1)
    ->clip(3, duration: 4)
    ->audioFilter('pan=mono|c0=c0,volume=0.5')
    ->audio('pcm_s24le')
    ->toDisk('audio')
    ->save('normalized.wav');

$result->measurement; // Non-finite statistics, such as for silence, are null.
$result->normalization;
$result->requestedMode;
$result->actualMode(); // FFmpeg can fall back to dynamic normalization.
```

Both passes read the same private lossless decode. Use `audioFilter` for channel processing; graphs, extra inputs or outputs, stream copy, and video settings are rejected.

<a name="add-a-watermark"></a>
### Adding a Watermark

The `watermark` method overlays an image, configured by a closure that receives a `Watermark` instance:

```php
use ProtoneMedia\LaravelFFMpeg\Watermark;

$logo = FFMpeg::disk('assets')
    ->open('logo.png');

$media->export()
    ->resize(width: 1280)
    ->watermark($logo, fn (Watermark $watermark) => $watermark
        ->right(20)
        ->bottom(15)
        ->size(width: 160)
        ->opacity(0.8))
    ->video('libx264')
    ->audio('copy')
    ->toDisk('videos')
    ->save('branded.mp4');
```

Without a closure, the mark appears at its original size in the bottom-right corner. The `center` method centers any axis no edge method pins, and pinning opposite edges throws an exception. Call `watermark` again to add another mark. Watermarks accept PNG, JPEG, and GIF images from any disk, work with multiple outputs and HLS variants, and require video encoding; with custom maps, graphs, or extra inputs, use a [custom filter graph](#custom-filter-graphs).

> [!NOTE]
> Marks are drawn after all output video filters, wherever you call `watermark`.

<a name="editing-the-watermark-image"></a>
#### Editing the Watermark Image

The `Watermark` instance also accepts [Laravel's image](https://laravel.com/docs/13.x/images) edits: `grayscale`, `blur`, `sharpen`, `rotate`, `crop` (with required `x` and `y`), `flipHorizontally`, and `flipVertically`. Edits require `intervention/image` and run in call order on a private copy, so the original never changes:

```php
$logo = FFMpeg::disk('assets')
    ->open('logo.png');

$media->export()
    ->watermark($logo, fn (Watermark $watermark) => $watermark
        ->right(25)
        ->bottom(25)
        ->size(width: 100)
        ->grayscale()
        ->blur(10))
    ->video('libx264')
    ->save('branded.mp4');
```

Edits use the image's pixels, while placement, `size`, and `opacity` use output pixels.

<a name="join-clips"></a>
### Joining Clips

The `concatTranscoded` method encodes clips with different formats to a common one, while `concat` joins compatible streams by copying:

```php
$first = FFMpeg::disk('clips')
    ->open('first.mp4');

$second = FFMpeg::disk('archive')
    ->open('second.mp4');

$first->concatTranscoded($second)
    ->conformVideo(1280, 720, frameRate: 30, fit: 'contain') // Or 'cover' or 'stretch'.
    ->conformAudio(sampleRate: 48000, channels: 2)
    ->video('libx264')
    ->audio('aac')
    ->toDisk('videos')
    ->save('joined.mp4');

$first->concat($second)
    ->export()
    ->video('copy')
    ->audio('copy')
    ->save('joined.mkv');
```

The `concatTranscoded` method requires `conformVideo` and either `conformAudio` or `withoutAudio`, uses each source's first video and audio streams, and rejects stream copy. Sources need zero container and video origins, and conforming audio requires audio in every source. The `concat` method never falls back to transcoding, and URL inputs need `download: true`. Neither keeps chapters, and both publish to the first input's disk unless you call `toDisk`.

<a name="compose-multiple-inputs-and-outputs"></a>
### Composing Multiple Inputs and Outputs

Each `output` closure configures one output. The `lut3d` method applies a privately staged LUT to each output separately:

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

To grade once and then branch, use `resource` with `split` in a [custom filter graph](#custom-filter-graphs).

<a name="custom-filter-graphs"></a>
#### Custom Filter Graphs

The `addInput` and `filterComplex` methods let you write the graph yourself; `addInput` returns the input's index:

```php
use ProtoneMedia\LaravelFFMpeg\ExportOutput;

$export = $media->export();

$image = FFMpeg::disk('assets')
    ->open('logo.png');

$logo = $export->addInput($image);

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

Each output needs explicit maps, a metadata and chapter policy, and a destination, and each label may be consumed once, so branch with `split` or `asplit`. Declare files with `resource('look', $media)` and use the placeholder as the whole value, as in `lut3d=file={{look}}`.

<a name="streaming"></a>
## Streaming

The `hls` and `dash` methods publish finite presentations after encoding and validation, while the `liveHls` method publishes a sliding playlist as the encoder runs.

<a name="adaptive-hls-and-encryption"></a>
### Adaptive HLS and Encryption

An adaptive presentation contains the variants a player may choose between. To encrypt it, pass the `encrypt` method a private key disk and a closure that returns each key's authorized URL:

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

<a name="playlist-types"></a>
#### Playlist Types

Playlists are `vod` by default; you may pass `event` to the `playlistType` method for `EXT-X-PLAYLIST-TYPE:EVENT`, or `live` to omit the tag. Every type keeps all segments and ends with `EXT-X-ENDLIST`, so none plays during encoding (see [continuous HLS](#publish-continuous-hls)). Parallel queued composition and `mergeHls` require `vod`.

<a name="hevc-and-hardware-encoders"></a>
#### HEVC and Hardware Encoders

For HEVC, pass `libx265` to the `video` method and add the `hvc1` tag so FFmpeg advertises the codec in the master:

```php
$result = $media->hls()
    ->video('libx265', crf: 28, preset: 'fast')
    ->outputOptions(['-tag:v', 'hvc1'])
    ->variant('720p', fn (HlsVariant $variant) => $variant->resize(height: 720))
    ->toDisk('streams')
    ->save('lessons/42/master.m3u8');
```

Both MPEG-TS and fMP4 work, but check player support. Hardware encoders such as `hevc_nvenc` use the same method; nothing selects hardware or falls back to software automatically, so your binary, drivers, device, and filter graph must support the path.

<a name="watermarks-and-variant-selection"></a>
#### Watermarks and Variant Selection

HLS exports accept static PNG and JPEG or animated GIF watermarks, without input options:

```php
use ProtoneMedia\LaravelFFMpeg\Watermark;

$animated = FFMpeg::disk('assets')
    ->open('badge.gif');

$result = $media->hls()
    ->watermark($animated, fn (Watermark $watermark) => $watermark
        ->right(16)
        ->bottom(16)
        ->size(width: 96)
        ->loop())
    ->variant('720p', fn (HlsVariant $variant) => $variant
        ->resize(height: 720)
        ->video('libx264', bitrate: '2500k'))
    ->toDisk('streams')
    ->save('animated/master.m3u8');
```

A GIF plays once and holds its last frame unless you call `loop`, whatever its embedded loop count. Video filters, including each variant's resize, always precede the overlay.

The `playbackForVariants` method persists playback for only the variants a viewer may access:

```php
// $lesson is the model that stores playback (see "Storing the Playback Inventory").
$lesson->hls_playback = $result->playbackForVariants(['720p'])
    ->toArray();
```

It keeps the selected variants' audio, subtitle, initialization, and key dependencies, and protected playback renders a filtered master without touching published playlists.

<a name="encryption-and-publication"></a>
#### Encryption and Publication

Segments are MPEG-TS by default. Paths are disk-relative, and playlist references are relative to their playlist. Keys go to the private disk passed to `encrypt` and publish before media. A custom `key` must be 16 raw bytes and cannot rotate; otherwise each save generates fresh keys, shared across variants per rotation group. Every segment gets a random IV, and OpenSSL is required. After validation, segments, playlists, and the master publish in that order, but not transactionally.

> [!WARNING]
> AES-128 is not DRM. Your application must authorize each key request before serving its 16 raw bytes.

<a name="customizing-playlists"></a>
#### Customizing Playlists

The `playlistUsing` closure receives each playlist's text, path, and variant name, and returns the text to write, so you may add comments and supported non-resource tags:

```php
$media->hls()
    ->variant('720p', fn (HlsVariant $variant) => $variant->resize(height: 720))
    ->playlistUsing(fn (string $text, string $path, ?string $variant) => str_replace("#EXTM3U\n", "#EXTM3U\n# Lesson playlist\n", $text))
    ->save('master.m3u8');
```

It cannot remove or change generated timing, references, stream attributes, or encryption directives.

<a name="fmp4-and-alternate-hls-groups"></a>
### fMP4 and Alternate HLS Groups

The `fmp4` method packages HLS as fragmented MP4, and the `audioGroup` and `subtitleGroup` methods let variants share alternate renditions:

```php
$result = $media->hls(segmentDuration: 6)
    ->fmp4()
    ->video('libx264', bitrate: '2500k')
    ->twoPass()
    ->variant('360p', fn (HlsVariant $variant) => $variant
        ->resize(height: 360)
        ->video(bitrate: '800k')
        ->audioGroup('sound')
        ->subtitleGroup('captions'))
    ->variant('720p', fn (HlsVariant $variant) => $variant
        ->resize(height: 720)
        ->audioGroup('sound')
        ->subtitleGroup('captions'),
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

Each rendition needs one explicit map of its type and its variants' clip. Audio renditions are encoded once and replace muxed audio in their group's variants. Subtitle renditions are one WebVTT segment, so they need a known duration, no complex timing graph, and aligned variant clocks. fMP4 writes one init file per audio and video playlist plus `.m4s` segments, both published before playlists. Encryption is rejected with fMP4 or alternate subtitles. [Dynamic responses](#customize-raw-hls-responses) rewrite init references through `mediaUrlsUsing` and traverse alternate playlists through `playlistUrlsUsing`.

<a name="dash-and-shared-hls-segments"></a>
### DASH and Shared HLS Segments

The `dash` method builds a presentation from adaptation sets and their representations, and the `sharedHls` method adds HLS playlists that reuse the same segments:

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
$result->adaptationSets();

$result->representation('low')
    ->files();
```

DASH supports finite, clear H.264/AAC: each representation maps one stream into a declared set, all share one accurate clip, and the builder owns codecs, muxers, and GOP alignment. Live DASH, two-pass, encryption, DRM, and protected playback descriptors are unsupported. After validating the bounded XML and owned inventory, segments, playlists, and the MPD publish in that order, not atomically. Results use `dash-init`, `dash-segment`, and `dash-manifest` roles, and queueing, cancellation, and events work as usual.

With `sharedHls`, the `playback` method and the queued completion event provide an `HlsPresentation` for the [protected HLS routes](#serve-protected-hls-with-laravel). It covers the HLS master, playlists, and shared media, but not the MPD, which has no protected route. Master entries are named after representations, so `playbackForVariants(['low'])` keeps that representation with all audio. The `mergeHls` method does not accept DASH output.

<a name="combine-completed-hls-presentations"></a>
### Combining Completed HLS Presentations

The `mergeHls` method combines completed presentations of the same timeline into one master playlist without transcoding or copying media:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// $firstResult and $secondResult are HlsExportResult instances from earlier hls() exports,
// and $lesson is the model that stores playback.
$merged = FFMpeg::mergeHls(['main' => $firstResult, 'alternate' => $secondResult])
    ->toDisk('streams')
    ->save('combined/master.m3u8');

$publishedPath = $merged->playlist()->path; // Includes a generated version suffix.

$lesson->hls_playback = $merged->playback()
    ->toArray();
```

It accepts two or more completed v9 `HlsExportResult` instances, `HlsPresentation` instances, or stored inventory arrays; optional string keys prefix each presentation's variant names. Inputs must be VOD with separate destinations, matching packaging, clips, and durations, and media on one disk, which is also the default for `toDisk`. Keys may live elsewhere. Store the versioned `playlist()->path`, not the path you requested.

> [!WARNING]
> The merged result owns only the new master; the source inventories still own segments, playlists, and keys. Deleting an original breaks the combination, so keep source inventories and reference counts until nothing uses those files.

<a name="publish-continuous-hls"></a>
### Publishing Continuous HLS

The `liveHls` method publishes a sliding playlist while FFmpeg runs:

```php
// $trustedCameraUrl comes from your own configuration, never from user input.
$session = FFMpeg::liveHls($trustedCameraUrl, segmentDuration: 2, window: 6)
    ->toDisk('videos')
    ->retry(maxRetries: 3, delay: 1)
    ->timeout(3600)
    ->cancelWhen(fn () => $this->shouldStop()) // Your own stop condition.
    ->onPublish(function ($snapshot) {
        // The playlist and its referenced segments are readable now.
    });

$result = $session->run('live/unique-session-id');

// Realtime playback of a finite input uses the same lifecycle.
$result = $media->liveHls(segmentDuration: 2, window: 6)
    ->toDisk('videos')
    ->run('live/another-session-id');
```

The `run` method blocks while publishing one H.264/AAC MPEG-TS rendition over a sliding `window` of segments, keeping one extra window for stale readers.

A clean EOF returns `completed` with `ENDLIST`; `stop` or `cancelWhen` returns `cancelled` and the timeout `timed_out`, keeping the last playlist without `ENDLIST`. The `retry` budget is finite, and `reconnectOnEof: true` reconnects after clean EOF too. Restarts keep sequence numbers monotonic, with discontinuities. Storage and exhausted-source failures throw a sanitized `MediaException` with a bounded published inventory; global process deadlines remain hard limits.

Continuous HLS has no adaptive masters, fMP4, encryption, or low-latency mode. Sources must be HTTP, HTTPS, or RTSP URLs without user information; `openRtsp` stays the snapshot API. Local and HTTP ingestion are acceptance-tested, but verify RTSP live ingestion on your deployment.

> [!WARNING]
> Use a fresh, empty namespace per session, supervise the worker, and guarantee one writer per namespace on storage that replaces objects atomically. A restarted PHP worker cannot resume a session. URL validation does not prevent SSRF, so restrict reachable hosts.

<a name="serve-protected-hls-with-laravel"></a>
### Serving Protected HLS

The `Hls` facade registers signed playback routes: a stored inventory decides which files are served, and your authorization callback decides who sees them.

<a name="storing-the-playback-inventory"></a>
#### Storing the Playback Inventory

Playback requires named private disks for media and keys. Add a JSON `hls_playback` column to your lessons table and cast it to an array:

```php
use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected function casts(): array
    {
        return ['hls_playback' => 'array'];
    }
}
```

After an export, persist the root result's inventory:

```php
// $result is the HlsExportResult returned by save(), and $lesson is a Lesson model.
$lesson->hls_playback = $result->playback()
    ->toArray();

$lesson->save();
```

A single variant's `playback` also keeps the key references it needs, even though its `files` exclude shared keys.

<a name="registering-playback-routes"></a>
#### Registering Playback Routes

Next, register the definition and an explicit model binding in your `AppServiceProvider`'s `boot` method:

```php
use App\Models\Lesson;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
use ProtoneMedia\LaravelFFMpeg\Support\Hls;

Route::model('lesson', Lesson::class);

Hls::define('lesson',
    resolve: fn (Lesson $lesson) => HlsPresentation::fromArray($lesson->hls_playback),
    authorize: fn (Lesson $lesson) => Gate::authorize('view', $lesson),
);
```

The `authorize` callback and your `view` policy are mandatory. Laravel does not infer implicit bindings from these callbacks, so call `Route::model` for each model parameter. Then register the route in `routes/web.php`:

```php
use ProtoneMedia\LaravelFFMpeg\Support\Hls;

Hls::routes('/lessons/{lesson}/hls', name: 'lessons.hls', playback: 'lesson');
```

The route uses the `web` and `auth` middleware by default and a controller action, so `route:cache` works; keep your own closures out of route actions. With Sanctum, pass `middleware: ['api', 'auth:sanctum']`. Bearer-URL playback with `middleware: ['web']` is acceptable only if URLs are issued behind an authorized endpoint: the URLs become bearer credentials with no global authentication, so `authorize` must enforce your policy on every request, including sessionless ones.

<a name="generating-playback-urls"></a>
#### Generating Playback URLs

Issue an entry URL from an authorized controller; `expires` defaults to 30 minutes:

```php
// $lesson is resolved by route model binding.
Gate::authorize('view', $lesson);

$url = Hls::url('lessons.hls', ['lesson' => $lesson], expires: now()->addMinutes(10));
```

Child playlists, segments, init files, and keys get signed URLs with the entry URL's exact deadline, never renewed, keeping validated signed query context such as grants, with route parameters taking precedence. Every request revalidates its signature and reruns `authorize`. The controller serves only inventory-owned files by opaque ID, never by disk path, with `private, no-store` and `nosniff`. Media supports single byte ranges and `HEAD`; keys return 16 raw bytes, not hex or Base64. CORS and trusted proxies are yours to configure.

> [!WARNING]
> Keep the inventory server-side, never in request data or public responses. Media and keys must stay private and immutable, so publish replacements to new paths and swap the stored inventory. Neither signed playback nor AES-128 is DRM.

<a name="customize-raw-hls-responses"></a>
### Customizing Raw HLS Responses

For application-owned URL rewriting, use the lower-level playlist API; resolvers receive the disk target, the containing playlist's key, and the original URI:

```php
use Illuminate\Support\Facades\Storage;

// Inside an authorized controller: $lesson comes from route model binding,
// $path from its stored inventory, and keys() is your own relationship.
return FFMpeg::disk('streams')
    ->playlist($path)
    ->playlistUrlsUsing(fn (?string $target, string $source, string $uri) => $target === null
        ? $uri
        : route('lesson.playlist', ['lesson' => $lesson->id, 'path' => $target]))
    ->mediaUrlsUsing(fn (?string $target, string $source, string $uri) => $target === null
        ? $uri
        : Storage::disk('streams')
            ->temporaryUrl($target, now()->addMinutes(10)))
    ->keyUrlsUsing(function (?string $target, string $source, string $uri) use ($lesson) {
        $key = $lesson->keys()
            ->where('playlist_uri', $uri)
            ->firstOrFail();

        return route('lesson.key', ['lesson' => $lesson->id, 'key' => $key->id]);
    });
```

HTTP(S) and root-relative references have a `null` target. The playlist is `Responsable`, with the HLS content type and `private, no-store`. The `get` and `all` methods return the rendered text:

```php
$playlist = FFMpeg::disk('streams')
    ->playlist('lessons/42/master.m3u8');

$text = $playlist->get(); // Selected playlist only.
$all = $playlist->all();  // Local playlists keyed by their original disk paths.
```

Rendering never fetches external URLs or reads media or keys, each call signs fresh URLs, and traversal and sizes are bounded.

> [!WARNING]
> Your application owns authorization, scoped signatures, key lookup, CORS, and cache policy here. Never expose a generic disk or key download route.

<a name="running-exports"></a>
## Running Exports

The `save` method runs an export synchronously and returns an inventory of the generated files, while [queued conversions](#queue-conversions) deliver that inventory through a completion event.

<a name="progress-results-and-failures"></a>
### Progress, Results, and Failures

To receive progress updates, you may pass a callback to the `onProgress` method. Throwing an `ExportCancelled` exception from the callback stops and reaps the encoder before the workspace is cleaned up:

```php
use ProtoneMedia\LaravelFFMpeg\Progress;

$export = $media->export()
    ->onProgress(function (Progress $progress) {
        if ($progress->isEncoding()) {
            $progress->percentage; // Nullable, like speed and remainingSeconds.
            $progress->pass; // Out of $progress->totalPasses.
        }
    });
```

The result exposes the generated files and redacted process summaries. When encoding, validation, or publication fails, the `save` method throws a `MediaException` instead:

```php
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

try {
    $result = $export->video('libx264')
        ->audio('aac')
        ->save('lesson.mp4');

    $file = $result->file();
    $file->path;

    $result->file('lesson.mp4', disk: 'uploads');
    $result->files();
    $result->processes();

    $stream = $file->readStream(); // You are responsible for closing this stream.
} catch (MediaException $exception) {
    // phase, category, diagnostics, published, and destination describe the failure.
}
```

Analysis requests do not throw on a nonzero exit unless you call the `throw` method:

```php
$analysis = $media->analyze('volumedetect', audio: 0);

$analysis->successful();
$analysis->errorOutput();
$analysis->throw();
```

Every export also dispatches the `ExportStarting`, `ExportProgress`, `ExportFailed`, `ExportCancelled`, and `ExportCompleted` events, the last after publication and cleanup. Exceptions from progress callbacks and synchronous listeners propagate unchanged.

<a name="listening-for-export-events"></a>
#### Listening for Export Events

To observe every export without configuring each builder, you may listen for the `ExportProgress` event:

```php
use Illuminate\Support\Facades\Event;
use ProtoneMedia\LaravelFFMpeg\Events\ExportProgress;

Event::listen(ExportProgress::class, function (ExportProgress $event) {
    $operationId = $event->operationId;
    $outputs = $event->outputs; // Destination disk names (or null) and paths, never input URLs.
    $progress = $event->progress;
});
```

Progress events cover every phase of ordinary, HLS, and queued exports, so some have no percentage. Each operation has its own ID, so use `QueuedExportCompleted` for whole-workflow completion. Worker cancellation is checked before listeners run, and synchronous listeners may cancel by throwing `ExportCancelled`, while asynchronous listeners cannot.

Setting `FFMPEG_ENABLE_EVENTS` to `false` suppresses all package events, including `QueuedExportCompleted`, while callbacks, cancellation, publication, and queue chains still run, so keep events enabled if listeners retain your completion inventories.

<a name="handling-partial-publication"></a>
#### Handling Partial Publication

A failed export reports, but never deletes, the siblings it already published, and existing objects may already be replaced. Workspaces and pass statistics are cleaned up on success, failure, or cancellation.

<a name="deleting-generated-files"></a>
#### Deleting Generated Files

After authorizing the deletion, you may delete the files an export owns through the root result's inventory, not a variant view:

```php
// $result is the result returned by the save method.
foreach ($result->files() as $file) {
    if ($file->owned && ! $file->delete()) {
        throw new \RuntimeException('Cannot delete '.$file->path);
    }
}
```

The `delete` method removes exactly that path, never a prefix, and returns `false` for borrowed files without touching storage. Filesystem exceptions propagate.

> [!WARNING]
> Your application owns authorization and reference tracking, since another presentation may still use an owned file. Deletion is not transactional and does not revoke cached copies. Retain HLS inventories, because reopening a playlist does not establish ownership of its segments or keys, and merged masters borrow shared files.

For older playlists without a retained inventory, the `deleteAllFiles` method discovers the master, nested playlists, segments, initialization files, and same-disk keys, and deletes nothing until your callback approves every path:

```php
// $exclusivePaths comes from application-owned records, never from the request.
$approvedPaths = array_fill_keys($exclusivePaths, true);

FFMpeg::disk('streams')
    ->playlist('lesson/master.m3u8')
    ->deleteAllFiles(fn (string $path): bool => isset($approvedPaths[$path]));
```

External or root-relative references abort before deletion. Keys on other disks are not found and adapter failures may leave a partial deletion, so prevent concurrent playlist changes and prefer a retained inventory.

<a name="limit-an-operations-execution-time"></a>
#### Limiting Execution Time

The `timeout` method sets a total budget in seconds for one execution, from probing to publication, while the `idleTimeout` method limits how long a child process may run without output:

```php
$result = $media->export()
    ->timeout(900)
    ->idleTimeout(30)
    ->video('libx264', bitrate: '2500k')
    ->twoPass()
    ->save('lesson.mp4');
```

Passing `null` removes an override. The configured per-process `timeout` and `idle_timeout` still apply, so the first limit reached wins. A timeout reaps the child before cleanup, and published files stay in the exception's `published` inventory. Budgets are checked between storage transfers and callbacks, not during them, so configure network timeouts on your adapters.

<a name="queue-conversions"></a>
### Queueing Conversions

To run an export on a queue worker, call the `queued` method after configuring it. The `save` method then returns Laravel's `PendingDispatch`, and the `configureJob` method lets you adjust the job:

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

$exportId = $pending->id; // Also carried by the completion event.

$pending->save('lessons/encoded.mp4')
    ->afterCommit();
```

Inputs and destinations must use named disks that every worker can read, so store uploads first; uploaded files and open streams are rejected before dispatch. Worker callbacks must be static closures that capture only scalars or arrays. Jobs implement `ShouldBeEncrypted`, so workers need your encryption key.

<a name="timeouts-and-retries"></a>
#### Timeouts and Retries

The job timeout defaults to the operation's `timeout`, or the configured FFmpeg timeout, plus 30 seconds, and is zero when neither is set. The `queue` configuration holds defaults that the `queued` arguments override, and `configureJob` runs last. Deadlines start in the worker, not while the job waits.

> [!WARNING]
> Set the connection's `retry_after` or visibility timeout above the job timeout. Retries restart the conversion, and delivery is at-least-once, not exactly-once.

A killed worker may leave a private workspace that retries do not reclaim, so prune stale workspaces without touching active jobs. For SQLite batch metadata with concurrent workers, enable WAL and a busy timeout and set `transaction_mode` to `'IMMEDIATE'`.

<a name="job-chains-and-batches"></a>
#### Job Chains and Batches

The `job` method builds the job without dispatching it, so you may use it in Laravel chains and batches:

```php
use Illuminate\Support\Facades\Bus;

$job = FFMpeg::disk('uploads')
    ->open('lesson.mp4')
    ->frame(2)
    ->toDisk('media')
    ->queued()
    ->job('lessons/preview.jpg');

// $lessonId identifies the lesson in your application.
Bus::chain([$job, new \App\Jobs\NotifyLessonReady($lessonId)])
    ->onConnection('redis')
    ->onQueue('conversions')
    ->dispatch();
```

Batch cancellation skips pending work and stops running encoders at the next progress check, but cannot roll back published files.

<a name="run-independent-hls-conversions-in-parallel"></a>
#### Running HLS Conversions in Parallel

To encode independent HLS variants on separate workers, pass `parallel: true` to the `queued` method:

```php
FFMpeg::disk('uploads')
    ->open('lesson.mp4')
    ->hls(segmentDuration: 4)
    ->toDisk('media')
    ->video(preset: 'fast')
    ->audio('aac')
    ->variant('small', fn ($v) => $v
        ->resize(width: 640)
        ->video(bitrate: '800k'))
    ->variant('large', fn ($v) => $v
        ->resize(width: 1280)
        ->video(bitrate: '2400k'))
    ->queued(connection: 'redis', queue: 'conversions', parallel: true)
    ->save('lessons/master.m3u8');
```

Each independent variant group runs as a job in a Laravel batch, which requires the batch repository, while variants that share audio or subtitle groups stay together. Parts are published as live media under `laravel-ffmpeg/queued/{exportId}/parts/`, then a finalizer publishes a versioned master such as `lessons/master.<version>.m3u8`, so read its path from the completion event. A failed part blocks the master and downstream jobs, while completed parts remain until you clean them up.

Parallel exports do not support custom playlist callbacks, complex graphs, or unreferenced alternate groups. Compose them with `Bus::chain()` rather than inside another batch, and keep batch records until finalization finishes.

<a name="retain-queue-results"></a>
#### Retaining Queue Results

After publication, queued exports deliver their file and playback inventories through the `QueuedExportCompleted` event, which you may persist in a listener:

```php
use Illuminate\Support\Facades\Event;
use ProtoneMedia\LaravelFFMpeg\Events\QueuedExportCompleted;

Event::listen(QueuedExportCompleted::class, function ($event) {
    \App\Models\Conversion::where('export_id', $event->exportId)
        ->update([
            'files' => $event->files, // Disk, path, size, role, and owned for each artifact.
            'playback' => $event->playback, // A serializable protected-HLS inventory, or null.
        ]);
});
```

Listeners must tolerate duplicate delivery. Parallel parts are borrowed by the final master, like `mergeHls` sources, so keep their inventories for cleanup. See the [application integration recipes](docs/integrations.md) for versioned profiles, durable inventories, and media-library integration.

<a name="deployment-and-development"></a>
## Deployment and Development

<a name="configuration-and-security"></a>
### Configuration and Security

The configuration is published as `config/laravel-ffmpeg.php`. The `inspect` method returns the arguments without running anything, and the `globalOptions` and `outputOptions` methods and `inputOptions` argument accept scoped options, but reserved filenames, binaries, and progress and statistics paths cannot be overridden.

> [!WARNING]
> Output validation is not a sandbox. Isolate untrusted inputs at the operating-system level with resource limits.

<a name="credential-redaction"></a>
#### Credential Redaction

Credential redaction is disabled by default. To enable it, set `FFMPEG_REDACT_CREDENTIALS` to `true`:

```php
return [
    // ...
    'redact_credentials' => env('FFMPEG_REDACT_CREDENTIALS', false),
];
```

The package then replaces these strings in `MediaException` diagnostics, process summaries, and `analyze`, `probe`, and `probeQuery` output:

| String | Replacement |
| --- | --- |
| The whole `openUrl()` URL | `[url]` |
| The whole RTSP URL | `[rtsp]` |
| The whole query string | `[query]` |
| A query value whose name contains `token`, `sig`, `key`, `auth`, `pass`, `secret`, `credential`, or `policy` | `[redacted]` |
| Any other query value of 16 or more characters | `[redacted]` |
| The RTSP `user:password` pair, and the password alone | `[redacted]` |

Query values match in raw and URL-decoded form, longest first. Shorter values with other names, such as `dl=1`, and RTSP usernames are kept. Placeholders such as `[input:0]` replace staged paths and direct URLs either way.

> [!WARNING]
> Redaction never changes FFmpeg's arguments, so direct signed URLs remain visible in process arguments.

<a name="testing-and-development"></a>
### Testing and Development

To set up a development environment and run the tests, use the setup script and PHPUnit:

```bash
.agents/setup
FFMPEG_9_FFPROBE=/opt/ffmpeg-9.0.2/bin/ffprobe vendor/bin/phpunit --order-by random \
    --display-deprecations --display-phpunit-deprecations \
    --fail-on-deprecation --fail-on-phpunit-deprecation
```

Real-media tests are skipped unless `FFMPEG_9_FFPROBE` points to FFprobe 9.0.x with a matching `ffmpeg` beside it, and font tests need `/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf`. CI runs PHP 8.4 and 8.5 on Ubuntu 26.04 with lowest and stable dependencies.

<a name="community"></a>
## Community

<a name="sponsor-us"></a>
### Sponsor Us

[<img src="https://inertiaui.com/visit-card.jpg" alt="Inertia Table" />](https://inertiaui.com/inertia-table?utm_source=github&utm_campaign=laravel-ffmpeg)

Support maintenance through [GitHub Sponsors](https://github.com/sponsors/pascalbaljet), and see [Inertia Table](https://inertiaui.com/inertia-table?utm_source=github&utm_campaign=laravel-ffmpeg).

<a name="contributing-and-security"></a>
### Contributing and Security

See [CONTRIBUTING](CONTRIBUTING.md) and [CHANGELOG](CHANGELOG.md). Report security issues to code@protone.media, not the public issue tracker.

<a name="credits"></a>
### Credits

- [Pascal Baljet](https://github.com/pascalbaljet)
- [All contributors](../../contributors)

<a name="license"></a>
### License

The [MIT license](LICENSE.md).

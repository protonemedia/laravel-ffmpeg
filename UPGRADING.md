# Upgrade to version 9

Version 9 replaces PHP-FFMpeg with Laravel Process, Storage, and Http. The old builders, format objects, driver forwarding, and filter factories are removed. This is a breaking upgrade, not a compatibility layer.

This guide describes the `9.x` development branch, not a published release.

## Update the runtime

Use PHP 8.4 or later, Laravel 13.20 or later, and matching FFmpeg and FFprobe 9.0.x binaries. The tested patch is 9.0.2. Install the codecs, filters, fonts, and HTTPS support that your application needs.

Republish the configuration and review your application's values. Keep `ffmpeg.binaries`, `ffprobe.binaries`, `ffmpeg.threads`, `timeout`, and `temporary_files_root`. `idle_timeout` is separate from the total deadline. The legacy log channel and encrypted-HLS temporary root no longer apply. Set `FFMPEG_BINARIES` and `FFPROBE_BINARIES` to the matching pair. Set `FFMPEG_REDACT_CREDENTIALS=true` to redact signed URLs and credentials from diagnostics and process summaries.

## Replace the entrypoint and format objects

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// Before:
FFMpeg::fromDisk('uploads')
    ->open('lesson.mov')
    ->export()
    ->toDisk('videos')
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->save('lesson.mp4');

// After:
$media = FFMpeg::disk('uploads')
    ->open('lesson.mov');
$result = $media->export()
    ->video('libx264', crf: 23)
    ->audio('aac', bitrate: '128k')
    ->toDisk('videos')
    ->save('lesson.mp4');
```

Use `FFMpeg::open($key)` for disk-relative keys on the current `filesystems.default`. Keep `disk('uploads')` when selecting a Storage disk, or use `disk($filesystem)` for a supplied filesystem. Pass a Laravel `File` or `UploadedFile` directly to `FFMpeg::open()` to read that local file instead of a Storage key.

```php
$media = FFMpeg::open($request->file('video'));
$public = FFMpeg::openUrl('https://cdn.example.com/lesson.mp4');
$private = FFMpeg::openUrl($url, headers: ['Authorization' => 'Bearer '.$token], download: true);
```

`FFMpeg::openUrl()` uses the same HTTP rules as the disk selector. Headers require `download: true`. Downloads use Laravel Http with TLS verification and reject redirects. Direct URLs enter media process arguments, so do not include reusable credentials. See the [HTTP input reference](REWRITE.md#http-inputs) for transport limits and redaction rules.

```php
$metadata = $media->probeCached(
    \Illuminate\Support\Facades\Cache::store('redis'),
    versionedKey: 'tenant:42:asset:7:object-version:abc123',
    ttl: 600,
);

$camera = FFMpeg::openRtsp($cameraUrl, transport: 'tcp', timeout: 10);
$camera->frame()
    ->toDisk('images')
    ->save('snapshot.jpg');
```

Use `probeCached()` only with an application-owned source-version key and cache repository. Change the key when the object changes. A cache hit does not read or authorize the source. Your application owns freshness, tenant isolation, eviction, and metadata security. `probe()` and exports still probe normally.

`openRtsp()` returns `RtspCamera`, not a `Media` descriptor for live exports. Its `frame()` captures a private PNG, then uses the ordinary frame pipeline. Transport is `tcp` or `udp`, and timeout is positive and at most 300 seconds. Actual TCP and UDP fixtures pass, but arbitrary camera compatibility is not claimed. Camera URLs must be trusted configuration. Restrict network access and protect credentials visible in process arguments.

For hardware decoding, pass validated input pairs such as `inputOptions: ['-hwaccel', 'cuda', '-hwaccel_device', '0', '-hwaccel_output_format', 'cuda']` to `open()`. These flags are input-scoped and are not passed to FFprobe. They do not select an output encoder, generate transfer filters, or provide a CPU fallback. Hardware execution has not been validated here. Test your deployed build and GPU separately. See the [hardware input contract](REWRITE.md#cached-probing-and-hardware-inputs).

Builders do not forward methods to PHP-FFMpeg. Use explicit codecs, scoped `inputOptions:`, `globalOptions()`, `outputOptions()`, and filters. Bitrates accept positive integers in bits per second, such as `2500000`, or unit strings such as `'2500k'`. Floats and unitless strings are invalid. `inspect()` returns the argument plan without reading inputs or launching a process. `save()` returns a result, not the builder.

## Replace filters and clipping

```php
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

Ordinary filters run in registration order. All output video filters run before generated watermark overlays, even filters called after `watermark()`. One-dimension resize preserves display aspect ratio; two explicit dimensions stretch. Use `contain()` or `cover()` for an exact canvas, as in Laravel's image manipulation. Stream copy rejects filters and accurate clipping. Keyframe cuts can begin before the requested time. Timeline changes omit chapters unless explicitly mapped.

For portrait sources, use `resize(height: 1920)` to preserve the autorotated display aspect ratio, or `contain(1080, 1920)` for an exact canvas. Probe width and height remain encoded dimensions, before display rotation.

Use `frameRate()`, `flipHorizontally()`, `flipVertically()`, `grayscale()`, and `deinterlace()` instead of filter-factory callbacks. Deinterlace before resizing. `volume()` takes a linear multiplier; `delayAudio()` inserts silence on all channels. `fadeIn()` and `fadeOut()` affect video and audio by default. Fade-out requires an explicit start in seconds. Disable either stream type with `video: false` or `audio: false`.

Use `drawText()` and `burnSubtitles()` for staged fonts, literal text, and subtitle files. Watermark images are edited inside the `watermark()` callback. `videoFilter()` and `audioFilter()` remain available for trusted expressions.

Use `lut3d($look)` with a `Media` descriptor for a privately staged LUT. In each `output()` callback, call `lut3d($look)->resize(...)` to configure that output independently. The helper does not share LUT processing or generate a `split`. Keep raw `resource()` and `filterComplex()` graphs when you need to apply a LUT once before splitting into outputs. See the [composition example](README.md#compose-multiple-inputs-and-outputs).

## Replace watermark and concat builders

```php
use ProtoneMedia\LaravelFFMpeg\Watermark;

$logo = FFMpeg::disk('assets')
    ->open('logo.png');
$media->export()
    ->watermark($logo, fn (Watermark $watermark) => $watermark
        ->right(20)
        ->bottom(15)
        ->size(width: 160))
    ->resize(width: 1280)
    ->video('libx264')
    ->audio('copy')
    ->save('branded.mp4');

$second = FFMpeg::disk('uploads')
    ->open('second.mp4');
$media->concatTranscoded($second)
    ->conformVideo(1280, 720, frameRate: 30, fit: 'contain')
    ->conformAudio(sampleRate: 48000, channels: 2)
    ->video('libx264')
    ->audio('aac')
    ->save('joined.mp4');

$media->concat($second)
    ->export()
    ->video('copy')
    ->audio('copy')
    ->save('joined.mkv');
```

`watermark()` adds inputs and maps automatically for PNG, JPEG, or GIF images. Its optional callback receives a `Watermark` instance and runs immediately. The mark defaults to the bottom-right corner, at its original size and fully opaque. `left()`, `right()`, `top()`, and `bottom()` take a margin from that edge, and `center()` centers every axis no edge method pins. Marks run after all output video filters, regardless of call order. Repeated marks, multiple ordinary outputs, and HLS variants are supported. Custom maps, graphs, additional media inputs, and video copy require another composition policy. Use `addInput()` and `filterComplex()` for custom graphs.

Version 8 forwarded unknown `WatermarkFactory` methods, such as `width()`, `blur()`, or `greyscale()`, to Spatie Image. Version 9 edits the image with Laravel's [image manipulation](https://laravel.com/docs/13.x/images) instead, so install `intervention/image:^4.2`. The `Watermark` instance offers Laravel's edit methods with the same names and arguments: `grayscale()`, `blur()`, `sharpen()`, `rotate()`, `crop()`, `flipHorizontally()`, and `flipVertically()`. Replace Spatie's `width()` and `height()` with `size()`, which FFmpeg applies in output pixels after the edits. Open the image like any other media instead of calling `open()` inside the callback:

```php
// Before:
$media->addWatermark(function (WatermarkFactory $watermark) {
    $watermark->fromDisk('assets')
        ->open('logo.png')
        ->right(25)
        ->bottom(25)
        ->width(100)
        ->blur(10)
        ->greyscale();
});

// After:
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

`concat()` joins compatible streams without silently transcoding. `concatTranscoded()` returns an export builder with one output. Choose `conformVideo()` and either `conformAudio()` or `withoutAudio()` before saving. Transcoded concat selects each input's first motion-video and audio streams, preserves audio delays, and requires finite sources with zero container/video origins. It rejects root filters, custom graphs, and stream copy. Sources without audio require `withoutAudio()` or a custom graph; the helper does not create missing audio tracks. Source chapters are not combined.

## Replace audio mixing graphs

```php
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

`backgroundMusic()` loops by default. Use `loop: false` for one playback, or `mixAudio()` for one-shot mixing with explicit gains. Call `loop()` on the returned builder to repeat the added track. The primary container's duration sets output length. Short nonlooping audio ends in silence, and longer audio is trimmed. Silent video and audio-only primaries are supported.

Use `at(float $seconds)` to start only the added audio later. It accepts finite nonnegative offsets, rounded to the nearest 48 kHz sample. Later calls replace the offset; `at(0)` restores the default. Original timing and gains do not change. A loop repeats the added samples after the initial delay without repeating that delay. Offsets at or beyond the primary duration add no audio and do not extend output.

Mixing selects the first audio track from each input and the primary's first motion-video track when present. Output audio is stereo at 48000 Hz and must be encoded. Video copy is allowed without video filters. Audio filters and `volume()` run after mixing. Added audio starts at its first decoded sample after any `at()` delay; original audio retains its offset relative to video.

The default `normalize: false` adds the weighted signals and can clip. With original audio present, `normalize: true` divides both padded branches equally by two, without changing gain when a track ends. This is not loudness normalization.

Both inputs require finite positive durations, and the added input requires audio. Video primaries require known zero container and video origins and a known nonnegative original-audio origin. Looping URL audio requires `openUrl($url, download: true)`. The builder supports one output and rejects maps, custom graphs, watermarks, clips, and two-pass encoding. Save a clip first if needed. See the [audio mixing reference](REWRITE.md#audio-mixing) for the complete contract.

## Replace speed and GIF graphs

```php
$media->speed(2)
    ->video('libx264')
    ->audio('aac')
    ->save('faster.mp4');
$media->gif(width: 480, frameRate: 12, loop: true)
    ->clip(4, duration: 3)
    ->save('preview.gif');
```

`speed()` retimes video and preserves audio pitch. It requires a finite positive factor, zero container/video origins, and nonnegative audio origins. It rejects clipping, input options, custom graphs, watermark inputs, multiple outputs, and stream copy. To retime a clip, save that clip first.

`gif()` owns the palette graph, maps, and encoder and produces no audio. Accurate clipping happens before palette generation. A private lossless intermediate avoids buffering the entire clip in memory, at the cost of disk space and additional processing passes. Keep clips short and dimensions small. Custom maps, graphs, additional inputs or outputs, audio settings, and encoder overrides are rejected.

## Select streams and metadata explicitly

```php
$media->export()
    ->mapVideo()
    ->mapAudio(language: 'eng')
    ->video('copy')
    ->audio('copy')
    ->withoutMetadata(chapters: true)
    ->streamMetadata('a:0', 'language', 'eng')
    ->disposition('a:0', ['default'])
    ->save('english.mkv');

$metadata = $media->probe();
$metadata->video()
    ?->width();
$metadata->audioStreams();
$metadata->chapters();
$metadata->toArray();
```

Without maps, ordinary exports select the first motion-video and audio streams. Existing subtitles and cover art require explicit maps and container-compatible codecs. The `coverArt()` attachment helper below owns its maps instead. General multiple-input outputs require explicit maps and metadata/chapter policy. Stream tags and dispositions address output order, not input indexes.

```php
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

Use `coverArt()` instead of a custom attached-picture graph for MP4, M4A, or MP3. It attaches one standalone PNG or JPEG and copies all motion-video and audio tracks. It refuses existing cover art. It rejects encoding, filters, clipping, custom maps, graphs, and disposition overrides.

`videoStream($track, $configure)` uses zero-based output video order after mapping, not input indexes. Its callback configures `codec()`, `bitrate()`, `crf()`, and `preset()` through `VideoStreamSettings`. Unspecified settings inherit `video()` defaults. Configure each track once; inherited bitrate and CRF conflicts are errors. Per-track overrides do not support two-pass encoding or create filter chains. The WebVTT recipe extracts a text subtitle track, not bitmap subtitles or speech.

## Replace image and analysis operations

```php
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

`get()` returns raw JPEG bytes, not Base64. Storyboard geometry applies to each tile before assembly. `waveform()` writes an image; `waveformPeaks()` returns versioned amplitude data. Analysis-only nonzero exits remain inspectable until `throw()` is called.

```php
FFMpeg::disk('images')
    ->open('title.png')
    ->still(duration: 5, frameRate: 25)
    ->toDisk('videos')
    ->save('title.mp4');
```

Use `still()` to hold a PNG or JPEG for a finite positive duration. Input dimensions must be even and at least two pixels, and input options are unsupported. Frame rate is an integer from 1 to 240, defaulting to 25. The single-output builder defaults to H.264 with `yuv420p` and creates no audio. Local, staged Storage, and downloaded images are supported. Output duration can round to frame boundaries.

Waveform `colors` accepts a string or a nonempty list of color strings. For example, `waveform(colors: 'blue')` selects one color. A scalar string is not a delimited list. RGB and RGBA hexadecimal colors require a `#` or `0x` prefix.

## Replace HLS builders and responses

```php
use ProtoneMedia\LaravelFFMpeg\HlsVariant;

$result = $media->hls(segmentDuration: 6)
    ->variant('360p', fn (HlsVariant $variant) => $variant
        ->resize(height: 360)
        ->video('libx264', bitrate: '800k'))
    ->encrypt('secrets', fn ($path) => route('lesson.key', ['key' => basename($path)]), rotateEvery: 3)
    ->toDisk('streams')
    ->save('lesson/master.m3u8');

$result->playlist();
$result->variant('360p')
    ->files();

return FFMpeg::disk('streams')
    ->playlist('lesson/master.m3u8')
    ->mediaUrlsUsing(fn ($target, $source, $uri) => $target === null ? $uri : \Illuminate\Support\Facades\Storage::disk('streams')
        ->temporaryUrl($target, now()
            ->addMinutes(10)));
```

Variants configure encoding only. MPEG-TS remains the default. Private key storage is explicit; key routes return 16 raw bytes after authorization. Keys rotate deterministically by segment group, with a random IV per segment. Playlist hooks are additive and cannot replace generated references or encryption lines. URL resolvers receive the canonical target, containing playlist, and original URI, not just a basename.

Replace legacy `setPlaylistType()` with `playlistType()`, passing `vod`, `event`, or `live`. The default is VOD. EVENT writes its type tag; live omits the type tag rather than passing an unsupported FFmpeg value. All modes still finish encoding and validation before publication and retain `ENDLIST` and every segment. This is not continuous live publication. Parallel queued composition remains VOD-only; use an ordinary queued export for other playlist types.

To clean up an old same-disk presentation, call `playlist($path)->deleteAllFiles($approve)`. The required approval callback receives every unique canonical disk path, including the opened playlist, and must return exactly `true` before any deletion starts. Verify exclusive ownership and prevent concurrent playlist changes in your application. URL resolvers are ignored; external references and malformed playlists fail before deletion. Adapter failures can leave partial deletion. Prefer the trusted root result inventory when available, especially with private keys on another disk or borrowed merged artifacts.

```php
$badge = FFMpeg::disk('assets')
    ->open('badge.gif');
$media->hls()
    ->watermark($badge, fn (Watermark $watermark) => $watermark
        ->size(width: 96)
        ->loop())
    ->variant('720p', fn ($variant) => $variant->resize(height: 720)
        ->video('libx264', bitrate: '2500k'))
    ->toDisk('streams')
    ->save('animated/master.m3u8');

$lesson->hls_playback = $result->playbackForVariants(['360p'])
    ->toArray();
```

An animated GIF plays once and then holds its final frame. Call `loop()` on the watermark to repeat it for the whole video, whatever loop count the GIF embeds. Watermark images reject input options such as `-ignore_loop`. All ordinary filters, including variant resizing, precede overlays. `playbackForVariants()` requires nonempty unique known variant names and retains their required rendition, initialization, and key dependencies. Protected responses filter master declarations without changing Storage files. Authorize the selected inventory before issuing URLs; public disk URLs bypass this selection.

To combine completed presentations for the same timeline, see [`PendingHlsMerge`](REWRITE.md#compose-completed-hls-presentations). It writes only a fresh version-suffixed master, not new media. Inputs need trusted v9 inventories, disjoint destinations, compatible VOD clocks and packaging, and a common media disk. Its result owns only the new master. Borrowed media stays owned by the source inventories, so retain application reference counts before deletion.

For protected Laravel playback, replace handwritten playlist and key routes with `Support\Hls`. Persist `$result->playback()->toArray()` from the root export result in the lesson's JSON column with an `array` cast for the full presentation. A variant's `playback()` also retains its needed private-key references internally, while its `files()` still excludes shared key files. Restore the inventory with `Http\HlsPresentation::fromArray($lesson->hls_playback)` in the definition's resolver.

Register `Hls::define()` in a service provider with a mandatory authorization callback such as `fn (Lesson $lesson) => Gate::authorize('view', $lesson)`. Register `Route::model('lesson', Lesson::class)` explicitly. Laravel does not infer model bindings from definition callbacks. Register `Hls::routes()` in the routes file, then issue `Hls::url(..., expires: now()->addMinutes(10))` from an authorized endpoint. Follow the [complete protected playback setup](README.md#serve-protected-hls-with-laravel).

Routes default to `['web', 'auth']` and support route caching. Definition and application configuration closures are not cached route actions. If Sanctum is installed, replace middleware with `['api', 'auth:sanctum']`. Deliberate bearer-URL playback can use `['web']` only with authorized URL issuance. There is no automatic global authentication in signed-only mode. URLs are bearer credentials, and the definition's authorization callback remains mandatory on every request.

All child URLs keep the entry URL's exact deadline without renewal. They preserve validated signed query context, such as grants or editions, with route parameters taking precedence. Every request revalidates the signature and authorization. Only inventory-owned resources are served, using opaque IDs and `private, no-store` headers. Media supports single byte ranges and `HEAD`; key responses contain 16 raw bytes. Keep inventories server-side and use named disks with private, immutable published media and keys. Publish replacements to new paths.

Keep the raw playlist API above for custom URL policies. Do not expose a generic disk download route. Your application still owns CORS and trusted proxy configuration. Signed playback and AES-128 encryption are not DRM.

## Update progress and failure handling

```php
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

try {
    $result = $media->export()
        ->video('libx264')
        ->onProgress(function (Progress $progress) {
            // percentage, speed, and remainingSeconds can be null.
            // pass and totalPasses describe encoding passes.
        })
        ->save('lesson.mp4');
    $file = $result->file();
} catch (MediaException $exception) {
    // phase, category, bounded diagnostics, and successfully published siblings.
}
```

Completion follows validation, publication, and cleanup. Throw `ExportCancelled` from a callback to stop the operation. Other callback exceptions propagate unchanged. There is no global `cleanupTemporaryFiles()` call and no arbitrary before-save command mutation.

Global `Events\ExportProgress` listeners receive the operation ID, destination outputs, and the same structured `Progress` value as `onProgress()`. No per-export callback is required. Synchronous progress listeners can cancel or fail an operation; asynchronous listeners cannot block encoding. Set `FFMPEG_ENABLE_EVENTS=false` to suppress package events, including `QueuedExportCompleted`, while retaining callbacks and native queue chains.

All artifacts validate before the first Storage write, but publication is not transactional. A later transfer failure can leave earlier outputs or replaced objects. Failures report successful writes without deleting them. Streams returned by `GeneratedFile::readStream()` belong to the caller and must be closed.

```php
// After authorization and checking that no other presentation needs these files:
foreach ($result->files() as $file) {
    if ($file->owned && ! $file->delete()) {
        throw new \RuntimeException('Cannot delete '.$file->path);
    }
}
```

Use the root HLS result's inventory for deletion, not a variant's partial view. `GeneratedFile::delete(): bool` deletes the exact stored destination through its captured filesystem. Borrowed files with `owned=false` return `false` without a filesystem call. Adapter failures return `false` or throw according to filesystem behavior. The application owns authorization, reference tracking, deletion order, and retries. There is no directory-wide or transactional delete.

## Type builders and results by their class

Each export builder is its own class with no parent class. For example, `export()` returns `PendingExport`, `hls()` returns `PendingHlsExport`, and `gif()` returns `PendingGif`. A parameter typed `PendingExport` accepts only `export()` builders, so type a profile for a specialized builder on that builder's class.

Builders define only the methods that change their output. An unsupported call, such as `twoPass()` on `dash()` or `audio()` on `frame()`, is an undefined method and throws `BadMethodCallException`, because builders are `Macroable`. Register a macro to add your own methods. Do not catch `InvalidArgumentException` to detect unsupported methods. `InvalidArgumentException` still reports invalid arguments to supported methods.

Results do not extend each other either. `HlsExportResult`, `DashExportResult`, `LoudnessResult`, and `WaveformPeaksResult` are not `ExportResult` instances. Every export result implements `ProtoneMedia\LaravelFFMpeg\Result`, which provides `files()`, `file()`, and `processes()`. Type code that accepts any export result on `Result`:

```php
use ProtoneMedia\LaravelFFMpeg\Result;

function inventory(Result $result): array
{
    return $result->files()
        ->map
        ->toArray()
        ->all();
}
```

## Queue native exports

Configure the native export before calling `queued()`:

```php
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

Queued `save()` returns Laravel's `PendingDispatch`, not an export result. Listen for `QueuedExportCompleted` to retain file metadata and protected-HLS inventories. Use `job()` to build jobs for native chains or ordinary batches without dispatching them immediately.

Store uploads on durable named disks before dispatch. Workers need matching disk configuration, FFmpeg binaries, and the application's encryption key. Filesystem instances and request-local `File` inputs are not portable queue inputs. Worker callbacks require static closures with scalar or array captures. Set queue visibility above the job timeout. Retries restart conversions rather than resuming media checkpoints.

Hard worker kills cannot run PHP cleanup. Configure safe retention for stale private workspaces as well as published artifacts. A recovered retry does not clean an interrupted attempt's workspace.

For independent HLS groups, `queued(parallel: true)` uses a Laravel batch and a final publication job. Shared rendition groups remain together. The versioned final master and borrowed-source ownership follow `mergeHls()`. Persist the event's actual playback inventory rather than constructing a filename. Keep part artifacts and batch records until finalization finishes. Use a chain rather than nesting a parallel workflow inside another batch. See [queue examples](README.md#queue-conversions) for retry, result, and retention details.

See the [README](README.md) for executable API examples and the [reference](REWRITE.md) for detailed limits.

# 9.x implementation status and acceptance contract

This file tracks the Laravel-native rewrite. The [design proposal](https://ampcode.com/artifacts/9-x-rewrite-feature-contract-and-laravel-native-api-640fbf41-d6df-45af-b75a-249a8018ba15) defines the target API and priorities.

This document describes the `9.x` development branch. It does not claim a published release or hardware validation.

## Current implementation

`FFMpeg::disk($disk)->open($key)` returns a media descriptor with native `probe()` and `export()` operations through Laravel Process. A disk can be a name or an `Illuminate\Contracts\Filesystem\Filesystem` instance. `open()` also accepts `Illuminate\Http\File` and `UploadedFile`. Creating a descriptor or inspecting an export performs no transfers or process launches.

`FFMpeg::open($key)` selects the current `filesystems.default` for string keys. `FFMpeg::open($file)` reads the supplied `Illuminate\Http\File` or `UploadedFile` instead of resolving a Storage key. `FFMpeg::disk($disk)->open($key)` selects a named or supplied filesystem. Calling `disk()` without an argument or with `null` selects `filesystems.default` at that call. Existing selectors and media descriptors keep their selected disk when configuration changes. See [UPGRADING.md](UPGRADING.md).

Local disks and file objects use their local paths. Other filesystem instances stream into a private workspace with transfer-size checks. Known remote manifests are rejected because staging a playlist alone loses its dependencies. This detection is not a sandbox for hostile media.

Probing requires FFprobe 9.0.x. It reads format, streams, and chapters together. `Metadata` and `Stream` retain raw fields through `get()` and `toArray()`. Duration is nullable, frame rates retain their rational source values, and the default video accessor excludes attached pictures. Width and height are encoded dimensions, not rotation-adjusted display dimensions.

The process boundary clears inherited application environment variables, limits JSON capture to 16 MiB, and retains only the last 64 KiB of stderr. Internal callbacks drain both streams without Symfony retaining a second copy. `laravel-ffmpeg.timeout` sets the total deadline. Optional `laravel-ffmpeg.idle_timeout` sets the silence deadline. Both use seconds. Failures expose a `MediaException` with `phase`, `category`, and bounded diagnostics, without a raw credential-bearing exception chain. Workspaces are removed after the child stops, including on failure.

Exports support codecs, positive integer bitrates in bits per second or explicit-unit strings, CRF and preset settings, ordered stream maps, audio and video filter chains, metadata and chapter controls, and accurate or keyframe clipping. For both `video()` and `audio()`, `2500000` and `'2500k'` are valid bitrates. Floats, zero, negative values, and unitless strings are invalid. An explicit map replaces default selection. Selector-specific FFprobe queries validate maps against the staged asset without interpreting FFmpeg's selector grammar in PHP. Empty explicit selection fails rather than allowing FFmpeg to select tracks automatically.

`addInput(Media)` returns a stable numeric input index. Repeated descriptors share staging and probe results within an execution, but retain separate `-i` entries. `filterComplex()` compiles one graph argument. `output($path, $callback)` configures independent destinations and encoding settings. Every output in a multi-input or graph operation requires explicit maps. Multiple inputs also require explicit metadata and chapter sources, or explicit omission. Output callbacks run immediately. Settings freeze when execution starts.

All outputs, including local destinations, are generated in the workspace. Every expected file passes validation before any publication begins. Composition outputs are probed for stream counts and for the types of directly mapped streams. Graph-pad types remain FFmpeg's responsibility. This catches extra unlabeled graph outputs without parsing filter pad counts in PHP. Publication preserves Storage write options but is not atomic. A failed upload can leave a partial object or an already-published sibling. Failures identify the destination and all objects whose writes returned success. Already-published objects are not deleted automatically.

`save()` returns generated-file records and bounded process summaries. Structured progress separates staging, probing, encoding, validation, and publication. Percentage, speed, and remaining time can be unknown. A callback can cancel by throwing `Exceptions\ExportCancelled`; other callback exceptions propagate unchanged as failures. Both stop and reap the child before workspace cleanup. Lifecycle events work without a progress callback, and completion follows publication and cleanup.

`Events\ExportProgress` carries `operationId`, the structured `Progress`, and destination `outputs`. It fires for phase changes and parsed encoder updates, independently of a per-export callback. Queue cancellation is checked first, followed by the per-export callback and then synchronous progress listeners. Listener exceptions follow the same cleanup and failure path as callback exceptions. `laravel-ffmpeg.enable_events` defaults to true through `FFMPEG_ENABLE_EVENTS`. Disabling it suppresses all package export events, including `QueuedExportCompleted`, without disabling callbacks, queue chains, or cancellation checks.

The old driver, format/filter objects, and PHP-FFMpeg dependency tree are removed. The package has one execution backend. The README and upgrade guide document the replacement API.

### HTTP inputs

```php
$public = FFMpeg::openUrl('https://media.example/video.mp4');
$private = FFMpeg::openUrl(
    'https://media.example/private.mp4',
    headers: ['Authorization' => 'Bearer '.$token],
    download: true,
);
$private->export()
    ->video('copy')
    ->audio('copy')
    ->toDisk('videos')
    ->save('private.mp4');
```

`FFMpeg::openUrl()` delegates to `InputSelector::openUrl()` and accepts the same headers, download flag, and input options. Both accept HTTP and HTTPS URLs. Direct input is the default for public or signed URLs. Choosing direct input permits the URL in FFmpeg and FFprobe process arguments, visible to operating-system process inspection. Package inspection, process summaries, diagnostics, and probe filenames show the direct URL argument as an input placeholder. With `laravel-ffmpeg.redact_credentials` enabled, the URL, its query string, and secret-looking query values are also replaced; see the README's credential redaction section. A direct read has a 15-second network read timeout, plus the configured process deadline.

Per-input headers require `download: true`. URL user information, fragments, invalid header names, and multiline header values are rejected. Downloads use Laravel Http with a 10-second connection deadline, a 60-second request deadline, TLS verification, and a private file sink. Redirects and transparent decompression are disabled. Only a complete, nonempty HTTP 200 response is accepted. A declared `Content-Length` must match the downloaded file. Headers and downloaded URLs never enter media process arguments. Laravel Http middleware and events still receive the original request, so application HTTP instrumentation must redact credentials.

URL inputs default to `filesystems.default` for publication, regardless of the selector's disk. Repeated inputs share one download within an execution. Separate headers produce distinct sources. URL resources such as fonts download through Http even when declared without `download: true`. Known manifest extensions and downloaded manifest contents are rejected as standalone media. This check is not a sandbox for hostile inputs or developer-supplied filters.

Protocol capabilities are checked only when a direct URL executes. Local inputs and Http downloads do not require network protocols in FFmpeg. HTTPS direct input verifies the certificate and excludes plaintext HTTP from its protocol whitelist. The native build includes GnuTLS. Other protocols and live-input policies remain outside this HTTP implementation.

### Cached probing and hardware inputs

```php
$metadata = $media->probeCached(
    \Illuminate\Support\Facades\Cache::store('redis'),
    versionedKey: 'tenant:42:asset:7:version:abc123',
    ttl: 600,
);

$media = FFMpeg::disk('uploads')
    ->open('source.mp4', inputOptions: [
        '-hwaccel', 'cuda', '-hwaccel_device', '0', '-hwaccel_output_format', 'cuda',
    ]);
```

`Media::probeCached(\Illuminate\Contracts\Cache\Repository $cache, string $versionedKey, \DateTimeInterface|\DateInterval|int $ttl = 3600): Metadata` caches the ordinary probe's raw metadata array. The key must be nonempty. An integer TTL must be positive. The internal namespace includes descriptor identity, the caller's versioned key, and the configured FFprobe binary. A hit restores `Metadata` with the current descriptor's stream identity. Missing or structurally invalid entries trigger a probe and cache write. Ordinary `probe()` and exports do not use this cache.

The application must change the key when source bytes or object versions change. No source existence, version, authorization, or freshness check runs on a hit. Applications own tenant isolation, TTL policy, invalidation, cache security, and trusted cache writers. Metadata can contain sensitive tags. The helper uses a supplied Laravel repository, not a global cache setting or a distributed lock. Concurrent misses can probe more than once.

Input option pairs accept `-hwaccel`, `-hwaccel_device`, and `-hwaccel_output_format`. They precede the matching FFmpeg `-i` and are excluded from FFprobe arguments. `-hwaccel` accepts `cuda`, `vaapi`, `qsv`, `vulkan`, `videotoolbox`, `d3d11va`, or `dxva2`, not `auto`. Device values accept letters, digits, underscores, slashes, dots, colons, and hyphens, but cannot start with a hyphen. Output formats use the compiler's restricted word syntax, which likewise rejects a leading hyphen, so no option value can look like an FFmpeg option. Misplaced, duplicate, or invalid options fail before process launch.

These checks validate arguments, not hardware availability. No GPU encode or decode has been validated here. Applications must validate their actual FFmpeg build, device permissions, decoder, encoder, and filter transfers. No automatic CPU fallback or hardware upload/download graph is generated.

### Bounded RTSP snapshots

```php
$camera = FFMpeg::openRtsp($cameraUrl, transport: 'udp', timeout: 10);
$camera->frame()
    ->resize(width: 640)
    ->toDisk('images')
    ->save('snapshot.jpg');
$bytes = $camera->frame()
    ->get(); // A new capture, returned as JPEG bytes.
```

`FFMpeg::openRtsp(string $url, string $transport = 'tcp', float $timeout = 15): RtspCamera` accepts an `rtsp` URL with a host, without whitespace, control bytes, or a fragment. Transport is `tcp` or `udp`. Timeout is finite, positive, and at most 300 seconds. `RtspCamera::frame(): PendingFrame` is the supported operation, not `export()` or live playback.

Execution captures the first video frame to a private PNG with a process deadline and a transport-specific protocol whitelist. Ordinary frame extraction then reads that PNG. Inspection does not connect to the camera and does not include credentials. Capture failures publish no snapshot; workspace cleanup follows child termination. `NativeInputHelpersTest` includes actual TCP and UDP RTP fixtures plus denied and stalled cameras. This is not broad camera interoperability validation.

Camera URLs are trusted application configuration. The application owns endpoint allowlists, network access, authentication, and operating-system isolation. With `laravel-ffmpeg.redact_credentials` enabled, the camera URL, its `user:password` pair and password, and secret-looking query values are redacted from diagnostics. The capture URL always remains visible in process arguments. These snapshots do not add RTSP to `openUrl()` or support live exports, reconnection, or continuous recording.

### Native acceptance toolchain

`.agents/setup` installs matching FFmpeg and FFprobe 9.0.2 binaries at `/opt/ffmpeg-9.0.2/bin`. The shared `.agents/install-ffmpeg` script verifies the source archive's SHA-256 before building with libx264, FreeType, HarfBuzz, libass, zlib for PNG support, and GnuTLS for HTTPS. An existing matching pair with PNG encoding and HTTPS protocols is reused. Resume checks for the binaries without installing anything.

```bash
FFMPEG_9_FFPROBE=/opt/ffmpeg-9.0.2/bin/ffprobe vendor/bin/phpunit --order-by random
```

CI uses Ubuntu 26.04 and the same installer throughout the PHP 8.4/8.5 and Laravel 12/13 matrix. The compiled pair is cached by Ubuntu version, architecture, and installer contents. A cold installation compiles from source; warm setup and cache hits reuse the binaries. No distribution FFmpeg or legacy backend is required.

### Single-frame extraction

```php
$media = FFMpeg::disk('videos')
    ->open('converted.mp4');
$result = $media->frame(at: '00:00:12.500')
    ->resize(width: 320)
    ->toDisk('thumbnails')
    ->save('covers/preview.png');
$bytes = $media->frame(at: 12.5)
    ->get(); // Raw JPEG bytes, not Base64.
$first = $media->frame()
    ->get();         // Timestamp zero, with fresh settings.
```

`frame()` selects the first non-attached-picture video stream and decodes the first frame at or after the timestamp. Timestamps accept nonnegative numeric seconds or the same strict timecodes as `clip()`. There is no artificial sampling window, so sparse variable-frame-rate media can return a frame several seconds later. A timestamp beyond the final frame throws a `MediaException` instead of publishing an empty file. `clip()` throws an `InvalidArgumentException`; choose the moment with `at` instead.

`save()` accepts `.jpg`, `.jpeg`, or `.png` destinations and returns an `ExportResult` with an image-role file. Generated images must have positive dimensions and the requested image format before publication. Destination defaults, Storage write options, filters, callbacks, cancellation, and workspace cleanup use the export pipeline. A `%` in a destination remains literal; single-frame output is not an image sequence.

`get()` returns JPEG bytes after validation and workspace cleanup. It performs no destination Storage write or publication notification. Lifecycle events contain empty destination and file lists for this in-memory result. Single-image extraction does not infer a percentage from the video's full duration.

### Image sequences

```php
$media->frames(every: 2)
    ->resize(width: 320)
    ->save('frames/%05d.jpg');
$result = $media->frames(count: 10)
    ->save('previews/%03d.png');
$result->files(); // Ordered files with concrete numbered paths and the image role.
```

`frames()` requires exactly one of `every` or `count`. Intervals are finite seconds, at least one microsecond. Counts are positive integers. Both resample decoded video at a constant rate through FFmpeg's `fps` filter with nearest timestamp rounding. This can duplicate source frames. It is not a sequence of independent `frame(at:)` seeks.

Count-based extraction requires a known, positive duration and produces exactly the requested number of images. The sampling rate is the count divided by the source duration, or the intersecting clip duration when clipped. Short sources can repeat frames, with final-frame padding if needed. Missing or nonpositive duration fails during probing rather than inventing a sampling interval.

Destinations require one `%d` or `%01d` through `%09d` placeholder in the filename, with no other percent signs. Numbering starts at one. Encoding uses a private workspace pattern, never the caller's destination as an FFmpeg path. All numbered files must be contiguous and valid JPEG or PNG images before any Storage write starts. Publication is not atomic. An upload failure reports concrete published paths and the failed destination, without deleting successful siblings.

### Storyboards and WebVTT cues

```php
$result = $media->storyboard(every: 2, columns: 5, rows: 4, margin: 3, padding: 2)
    ->resize(width: 160)
    ->clip(10, duration: 45)
    ->vtt('metadata/preview.vtt')
    ->toDisk('previews')
    ->save('images/sheet-%03d.jpg');
```

`storyboard()` samples decoded video at the declared interval and tiles thumbnails in row-major order. Intervals are positive whole milliseconds expressed in seconds. The default grid is five columns by five rows. Margin and padding are nonnegative pixel counts. `resize()` and `fit()` configure each thumbnail before tiling. JPEG quality remains an output option, such as `outputOptions(['-q:v', '2'])`.

The operation requires one input with a known, positive duration. Complex graphs and keyframe seeks are rejected. Accurate clips bound thumbnails before tiling, and cue times start at zero within the clip. Sampling uses the `fps` filter with nearest timestamp rounding and can repeat source frames. The final sheet contains black unused cells. Those cells have no cues.

`save()` requires a numbered JPEG or PNG destination, with the same placeholder and numbering rules as `frames()`. `vtt()` optionally writes WebVTT cues on the output disk. Without a resolver, image URLs are percent-encoded paths relative to the VTT file's directory. A custom resolver receives each concrete sheet key and its one-based index:

```php
$media->storyboard(every: 2)
    ->vtt('preview.vtt', fn (string $key, int $index) => Storage::disk('previews')
        ->url($key))
    ->toDisk('previews')
    ->save('images/sheet-%03d.png');
```

Resolved URLs must be nonempty strings without whitespace, control characters, or fragments. Query strings are retained before the generated `#xywh=x,y,width,height` fragment. Coordinates use the actual sheet dimensions, margin, and padding. Each cue ends at the next sample or the clip's end, rounded up to a millisecond.

Every sheet and the cue file are validated before publication. The result lists image-role files first and the optional `vtt`-role file last. Publication is not atomic. If the cue upload fails, the exception identifies the sheets already published and the failed cue destination. Source bytes and successful uploads are retained, and private workspace files are removed.

### Waveform images

```php
$media->waveform(width: 640, height: 120, colors: ['#0000ff'])
    ->toDisk('previews')
    ->save('audio/waveform.png');
$media->waveform(width: 640, height: 120, colors: ['red'])
    ->audioFilter('volume=0.5')
    ->clip(10, duration: 5)
    ->resize(width: 320)
    ->save('audio/excerpt.jpg');
```

`waveform()` renders the first audio stream through FFmpeg's `showwavespic` filter. Defaults are 640 × 120 pixels with blue channels on one canvas. Dimensions are positive integers and may be odd. The `colors` parameter accepts `string|array`. A string is one color, not a delimited list. An array must be a nonempty list of color strings. Colors are FFmpeg names, six-digit RGB strings, or eight-digit RGBA strings. Hexadecimal colors require a `#` or `0x` prefix. Filter syntax is not accepted as a color.

Audio filters run before accurate audio trimming and waveform rendering. Geometry and other video filters run after rendering, inside the same owned graph. Keyframe clips are rejected. Each call creates fresh settings. `save()` publishes a validated JPEG or PNG with the image role, using the same Storage options, lifecycle events, cancellation, and workspace cleanup as other images. Percentage remains unknown. A waveform image is not waveform-peaks data or a live audio visualizer.

### Audio analysis

```php
$analysis = $media->analyze(); // Runs volumedetect on the first audio track.
$analysis->successful();
$analysis->failed();
$analysis->exitCode;
$analysis->errorOutput(); // FFmpeg writes volume measurements to stderr.
$analysis->output();
$analysis->throw(); // Optional: throws MediaException for a nonzero exit.

$analysis = $media->analyze('volume=0.25,volumedetect', audio: 1);
```

`analyze()` executes immediately and returns a `ProcessResult`. The default filter is `volumedetect`. `audio` is a zero-based audio-track index, not an absolute stream index. Only that track is mapped. Custom filters are developer-trusted audio filter expressions, with the same URL and NUL restrictions as export filters. Negative track indexes and empty filters fail before IO.

Analysis uses FFmpeg's null muxer. It creates no media artifact, performs no destination Storage writes, and dispatches no export lifecycle events. Local files, staged Storage inputs, and URL transport use the shared input boundary. Source bytes are retained, and private workspace files are removed before return.

Nonzero FFmpeg exits remain inspectable, including missing tracks, invalid filters, and invalid media. `throw()` returns the same successful result or throws a `MediaException` with phase `analysis` and category `exit`. Staging, capability, launch, and timeout failures still throw before a result is available. Timeouts stop and reap the child before cleanup.

The result exposes `exitCode`, `output`, `diagnostics`, `elapsedSeconds`, `command`, and `phase` properties. `output()` returns stdout, and `errorOutput()` returns the diagnostic tail. `summary()` returns status, timing, the redacted command, and diagnostics. Captured stdout is limited to 16 MiB, and diagnostics retain the final 64 KiB. Analysis replaces the input path or direct URL argument with `[input]` in both streams and the returned command. With `laravel-ffmpeg.redact_credentials` enabled, it also redacts signed URLs and secret-looking query values. Direct URL inputs still expose their URL to operating-system process inspection. Structured volume parsing and loudness normalization are not part of this operation.

### Adaptive HLS

```php
use ProtoneMedia\LaravelFFMpeg\HlsVariant;

$result = $media->hls(segmentDuration: 6)
    ->video('libx264')
    ->audio('aac', bitrate: '96k')
    ->variant('360p', fn (HlsVariant $variant) => $variant->resize(height: 360)
        ->video(bitrate: '800k'))
    ->variant('720p', fn (HlsVariant $variant) => $variant->resize(height: 720)
        ->video(bitrate: '2800k'))
    ->toDisk('streams', ['visibility' => 'private'])
    ->save('lesson/master.m3u8');

$result->playlist();                   // The master GeneratedFile.
$result->variant('720p')
    ->playlist();  // lesson/720p/index.m3u8.
$result->variant('720p')
    ->files();     // This variant's segments and playlist.
```

`hls()` returns a fresh `PendingHlsExport` for MPEG-TS VOD. Its default codecs are `libx264` and `aac`. At least one named variant is required. Names contain ASCII letters, digits, underscores, or hyphens and start with a letter or digit. Names must be unique. Variant callbacks run immediately and configure encoding, maps, and filters, without `save()` or `toDisk()` methods.

`playlistType(string $type)` accepts `vod`, `event`, or `live`, case-insensitively. VOD is the default. EVENT emits `EXT-X-PLAYLIST-TYPE:EVENT`; live omits the type option and tag because FFmpeg has no `live` playlist-type value. All three preserve the complete segment inventory and finish with `EXT-X-ENDLIST`, including alternate subtitles. Publication still follows full encoding and validation. This setting does not enable continuous inputs, sliding windows, or playback during encoding. Single queued exports retain the type; parallel queued composition and `mergeHls()` remain VOD-only.

Shared defaults resolve at compilation, including settings declared after a variant. Variants override individual codec settings, metadata tags, maps, and matching output-option flags. Variant filters append after shared filters. Explicitly repeatable stream IDs remain ordered. Rate-control conflicts still fail rather than silently dropping a bitrate or CRF. Shared Storage options apply to media and playlists, not encryption keys. Variants freeze when execution starts.

All variants run in one FFmpeg invocation against the same staged inputs. Explicit graphs can share processing through `split` or `asplit`. Different variants still encode separately. When a variant maps a graph-produced stream, its geometry and other video filters belong inside that graph, not in a separate `resize()` or `videoFilter()` chain. Multi-input operations retain the explicit map, metadata, and chapter-source requirements of ordinary composition. Accurate clips and watermark graphs use the shared execution pipeline. No performance improvement has been measured.

Without explicit maps, each variant selects the first motion-video and audio streams when present. Cover artwork does not become video in an audio-only export. Silent video, audio-only media, and `video('copy')->audio('copy')` are supported. Explicit maps replace defaults. For example, `map('0:V:0')->map('0:a')` retains every audio stream with the first motion-video stream.

Segment duration ranges from 0.001 to 86400 seconds. Encoded video requests closed GOPs and keyframes at segment-duration intervals. Actual boundaries depend on available frame timestamps and the encoder. Copy mode retains source keyframes and cannot promise exact segment lengths. Neither mode advertises independent segments. Finalization sets a positive integer target duration that covers the longest segment. Master bandwidth includes MPEG-TS overhead: `BANDWIDTH` conservatively uses the highest individual segment bitrate, and `AVERAGE-BANDWIDTH` uses total bytes divided by total duration. FFmpeg-supplied codec and resolution attributes are retained when available.

The default layout places each variant's `index.m3u8` and `segment-00000000.ts` files in a subdirectory beside the master. Playlist references are relative to their containing playlist. Encoder filenames stay private. Finalization requires complete playlists matching the selected type, contiguous referenced segments, nonempty files, and no unreferenced matching segments. External, traversal, duplicate, and missing references, or unexpected encryption directives from FFmpeg, fail before any Storage write. Playlist reads are limited to 16 MiB each. Validation also probes each variant's first segment for the expected stream count and directly mapped stream types, catching extra unlabeled graph outputs. It does not decode every encoded frame or probe every segment.

The result lists all segments first, then variant playlists, then the master. Their roles are `hls-segment`, `hls-playlist`, and `hls-master`. Publication follows that media order, with encryption keys uploaded first when enabled. A failed upload identifies the concrete published siblings and failed destination without deleting successful uploads. Saving replaces the requested definitions, does not append variants or remove old files, and is not transactional. Existing segments may already be replaced when a later upload fails.

Variant playlists and segments can use separate destination directories:

```php
$result = $media->hls(segmentDuration: 6)
    ->variant('360p', fn (HlsVariant $variant) => $variant->resize(height: 360),
        playlist: 'playlists/360p/index.m3u8', segments: 'segments/360p/part-%03d.ts')
    ->variant('720p', fn (HlsVariant $variant) => $variant->resize(height: 720),
        playlist: 'playlists/720p/index.m3u8', segments: 'segments/720p/part-%d.ts')
    ->toDisk('streams')
    ->save('catalog/master.m3u8');
```

`playlist:` and `segments:` are optional disk-relative keys, not paths relative to the master. Playlists require an `.m3u8` extension. A segment pattern requires the packaging's extension and exactly one `%d` or `%01d` through `%09d` placeholder in the filename, with no other percent signs. MPEG-TS uses `.ts`, fMP4 uses `.m4s`, and subtitle renditions use `.vtt`. Numbering starts at zero. Without `segments:`, segments use the variant playlist's directory and the default `segment-%08d` basename. A playlist at the disk root is supported.

Each playlist references files relative to its own directory, with path components percent-encoded for URLs. Normalized destination keys remain unchanged in Storage and result records. `variant($name)->files()` selects the files owned by that variant, even when variants share a segment directory or use the same playlist basename. Concrete destination collisions, including equivalent numbering patterns, fail before any publication. Custom destinations never enter FFmpeg arguments.

HLS supports `twoPass()` with bitrate-configured video variants. The first pass writes private encoder statistics through null muxers, without HLS segments or playlists. The final pass packages and validates the artifacts before publication.

### fMP4 and alternate groups

```php
$result = $media->hls()
    ->fmp4()
    ->variant('720p', fn (HlsVariant $variant) => $variant
        ->resize(height: 720)
        ->audioGroup('sound')
        ->subtitlesGroup('captions'))
    ->audioRendition('sound', 'English', fn (HlsVariant $rendition) => $rendition
        ->map('0:a:0')
        ->audio('aac', bitrate: '128k'), language: 'en', default: true)
    ->subtitleRendition('captions', 'English', fn (HlsVariant $rendition) => $rendition
        ->map('0:s:0'), language: 'en')
    ->toDisk('streams')
    ->save('lesson/master.m3u8');
```

`fmp4()` changes segment extensions to `.m4s` and owns a validated `hls-init` file per audio/video playlist. Optional `variant(init:)` selects its disk-relative `.mp4` key. AES-128 encryption is rejected for fMP4 in either configuration order. Init files publish before playlists and remain in the variant inventory. Private combined probe files are never published.

`audioRendition()` and `subtitleRendition()` declare reusable groups, referenced through `HlsVariant::audioGroup()` and `subtitlesGroup()`. Renditions require exactly one explicit stream map of the declared type. Group names are simple identifiers. Display names and defaults are unique within each type/group; `default: true` requires autoselection. Optional language, channels, and forced-subtitle attributes appear in `EXT-X-MEDIA`. Variants with alternate audio cannot also map muxed audio. Result lookups use `audioRendition($group, $name)` and `subtitleRendition($group, $name)`.

Audio encodes once per rendition, not once per referencing variant. Subtitles use a full-presentation WebVTT segment with a timestamp map tied to aligned referencing variant clocks. They require a known presentation duration without a complex timing graph. Referencing variants and renditions require matching clips. Encryption with alternate subtitles is rejected rather than publishing plaintext captions under an encrypted-media configuration. Custom `.vtt` patterns, playlist paths, hooks, and publication use the same owned-inventory rules. Dynamic responses recognize `EXT-X-MAP` as media and `EXT-X-MEDIA` as playlist references.

### Loudness and waveform-peaks data

```php
$normalized = $media->normalizeLoudness(integrated: -18, truePeak: -2, sampleRate: 48000, audio: 1)
    ->clip(3, duration: 4)
    ->audioFilter('pan=mono|c0=c0,volume=0.5')
    ->audio('pcm_s24le')
    ->save('normalized.wav');
$normalized->measurement;
$normalized->actualMode();

$peaks = $media->waveformPeaks(samplesPerBucket: 256)
    ->audioTrack(1)
    ->mono()
    ->buckets(1000)
    ->get();
$peaks->toArray();
$media->waveformPeaks()
    ->buckets(2000)
    ->save('peaks.json');
```

Loudness normalization produces one audio-only output. It prepares one private lossless sample snapshot after trim, channel preprocessing, and resampling. Both loudnorm passes read that snapshot. Results retain validated measurement/final statistics, requested and actual normalization modes, and output rate. Nonfinite statistics become null, never zero; silence uses dynamic fallback. Graphs, multiple inputs/outputs, copied audio, video settings, keyframe seeking, and raw rate/channel overrides are rejected.

Peaks decode the selected audio track to signed little-endian 16-bit PCM at that track's rate. Separate channels are the default; `mono()` mixes channels equally before reduction. Fixed-size buckets retain partial tails without zero padding. `buckets(n)` partitions frames into at most n nonempty buckets, with remainder frames in the earlier buckets. Version-1 JSON records rate, source channels, track, mode, frame count, durations, bucket lengths, and channel-major extrema. Empty selections have no buckets. Reads use at most 2,048 frames per chunk; results are capped at 100,000 channel-buckets. Private PCM disk usage remains proportional to duration. Both operations use ordinary staging, publication, cancellation, and workspace cleanup.

### Playlist hooks

```php
$result = $media->hls()
    ->variant('720p', fn (HlsVariant $variant) => $variant->resize(height: 720))
    ->playlistUsing(function (string $playlist, string $path, ?string $variant): string {
        $tag = $variant === null
            ? '#EXT-X-SESSION-DATA:DATA-ID="com.example.title",VALUE="Lesson"'
            : '#EXT-X-START:TIME-OFFSET=2.0,PRECISE=YES';

        return str_replace("#EXTM3U\n", "#EXTM3U\n# Playlist: ".$path."\n".$tag."\n", $playlist);
    })
    ->toDisk('streams')
    ->save('lesson/master.m3u8');
```

`playlistUsing()` receives finalized text, the disk-relative destination key, and the variant name or generated rendition identifier. The name is `null` for the master. Callbacks run once per playlist during each save, before publication. Inspection does not call them. The last registration replaces the previous callback. Configuration cannot change while an export runs.

The hook is additive. It can insert comments and `EXT-X-START`, `EXT-X-SESSION-DATA`, `EXT-X-DATERANGE`, and `EXT-X-PROGRAM-DATE-TIME` tags without `URI=` attributes. The application supplies valid attributes and placement for each playlist type. All generated lines must remain unchanged and in order. Changing references, timing, stream information, VOD completion, or encryption through this hook is rejected.

Callbacks return UTF-8 text of at most 16 MiB, starting with `#EXTM3U` and a newline. CRLF becomes LF. Other control characters are rejected. Invalid output throws `MediaException` with phase `validation` and category `artifact`. Application exceptions propagate unchanged. Throwing `ExportCancelled` cancels the operation. Failures and cancellation remove the private workspace without publishing any files.

### AES-128 encryption and key rotation

```php
$result = $media->hls(segmentDuration: 6)
    ->variant('720p', fn (HlsVariant $variant) => $variant->resize(height: 720))
    ->encrypt(
        keysDisk: 'secrets',
        keyUrl: fn (string $path) => route('lesson.key', ['key' => basename($path)]),
        directory: 'lessons/42/keys',
    )
    ->toDisk('streams')
    ->save('lessons/42/master.m3u8');

$result->playlist();                             // The master playlist, not a key.
$result->file();                                 // Still the first media segment.
$keys = $result->files()
    ->where('role', 'hls-key'); // Owned private key copies.
```

Configure `secrets` as a non-public disk. Your application serves each key URL with authorization and the key's 16 raw bytes, not hex or Base64. AES-128 is access control, not DRM. PHP's OpenSSL extension is required.

```php
// Supply an existing 16-byte key. The export owns a new private copy.
$key = Storage::disk('secrets')
    ->get('lessons/42/source.key');

$media->hls()
    ->variant('720p', fn () => null)
    ->encrypt('secrets', fn ($path) => route('lesson.key', ['key' => basename($path)]), key: $key)
    ->toDisk('streams')
    ->save('lesson/master.m3u8');
```

```php
// Generate a new key for segments 0–2, 3–5, and so on, shared across variants.
$result = $media->hls(segmentDuration: 6)
    ->variant('360p', fn (HlsVariant $variant) => $variant->resize(height: 360))
    ->variant('720p', fn (HlsVariant $variant) => $variant->resize(height: 720))
    ->encrypt(
        keysDisk: 'secrets',
        keyUrl: function (string $keyPath, string $playlistPath, string $variant, int $rotation): string {
            return route('lesson.key', ['key' => basename($keyPath)]);
        },
        rotateEvery: 3,
    )
    ->toDisk('streams')
    ->save('lesson/master.m3u8');
```

Without `key:`, each save generates fresh keys and filenames. `rotateEvery:` is a positive segment count, indexed from zero independently within each variant. The same rotation group shares a key across variants. It cannot combine with `key:`. Every segment has its own random 128-bit IV, recorded explicitly in `EXT-X-KEY`. Local post-processing uses AES-128-CBC with PKCS#7 padding, not log-triggered FFmpeg rekeying. Ciphertext sizes determine master bandwidth. First-segment stream validation uses the retained private plaintext.

`keyUrl` runs once per used rotation group per variant during finalization, never inspection. It receives the private disk-relative key, the public playlist key, variant name, and zero-based rotation index. It returns a UTF-8 HTTP(S) URL or relative URI, resolved against that variant playlist. Quotes, backslashes, whitespace, control characters, fragments, and repeated URLs for different keys within a playlist are rejected. The application owns URL expiry, authorization, and CORS.

Keys publish with private visibility before any media, regardless of media Storage options. Segments publish before playlists, with the master last. Results retain media-first order and append `hls-key` files; variant results exclude shared keys. Key bytes and URLs never enter FFmpeg commands or process summaries. Finalized playlists retain the 16 MiB limit. Hooks cannot change generated key directives. Validation and callback failures publish nothing and clean the workspace. Later transfer failures report successful uploads, including keys, without deleting them. Publication is not transactional.

### Protected Laravel playback

`HlsExportResult::playback()` returns `Http\HlsPresentation`. Call it on the root export result so the inventory includes the master, variants, renditions, media, init files, and shared keys. `toArray()` returns a persistable inventory with `files`, `playlist`, and `keys`. `HlsPresentation::fromArray()` restores trusted server-side data. Named disks and canonical disk-relative paths are required. Anonymous filesystem instances are not supported for playback. Never accept an inventory from a request or expose it through a public model response.

`variant($name)->playback()` retains the private-key references needed for that variant internally, although `variant($name)->files()` excludes shared key files. Root-result persistence remains the recommended choice for a full presentation.

```php
$selected = $result->playbackForVariants(['360p', '720p']);
$lesson->hls_playback = $selected->toArray();
```

`HlsExportResult::playbackForVariants(array $names): HlsPresentation` delegates to `HlsPresentation::playbackForVariants(array $names): self`. Names must be a nonempty list of unique known variants. Empty or duplicate selections throw `InvalidArgumentException`; unknown names throw `Illuminate\Support\ItemNotFoundException`. Selection retains the master and the chosen variants' required renditions, media, initialization files, and keys. Rendering removes excluded master declarations without writing playlists. It does not revoke a separate full inventory or access through public Storage URLs. Authorize the selection before issuing a signed playback URL.

The `Support\Hls` facade provides these methods:

- `define(string $name, callable $resolve, callable $authorize)` registers a presentation definition. The resolver returns `HlsPresentation`. Authorization is mandatory and must return `true` or an allowed Laravel authorization response, such as `Gate::authorize('view', $lesson)`.
- `routes(string $uri, string $name, string $playback, array $middleware = ['web', 'auth'])` registers a GET/HEAD controller route with an optional opaque `hlsResource` parameter.
- `url(string $route, array $parameters = [], ?DateTimeInterface $expires = null)` signs the entry URL. The default expiry is 30 minutes.

Register definitions in a service provider, and register routes in the application's routes file. Register `Route::model('lesson', Lesson::class)` explicitly. Laravel does not infer model bindings from definition callbacks. Definition and application configuration closures are not cached route actions. The controller route supports Laravel route caching. The [protected playback guide](README.md#serve-protected-hls-with-laravel) includes persistence, policy, binding, route, and URL examples.

Every request verifies a signed URL with an expiry and calls authorization before resolving the presentation. All rewritten child URLs inherit the entry URL's exact expiry, without renewal. Child URLs preserve validated signed query context, including grants or editions. Route parameters take precedence over query parameters. Lookup permits only inventory-owned files with the expected role. Key URI mappings recorded during encryption link playlist references to private key files. Resource IDs are opaque SHA-256 identifiers, not caller-supplied disk paths.

Responses set `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`. Media and init responses support `HEAD` and a single byte range, including suffix ranges. Invalid or unsatisfiable ranges return 416. Key responses contain exactly 16 raw bytes, never hex or Base64. Keys support `HEAD` without a body. Missing files and unknown resources return 404. `HlsPresentation::file()` and `reference()` throw `Exceptions\HlsResourceNotFoundException`, an `Illuminate\Support\ItemNotFoundException`, for resources the inventory does not own. During a request it renders as the application's 404 response and is not reported, so custom controllers need no catch.

With Sanctum installed, middleware can be replaced with `['api', 'auth:sanctum']`. Deliberate bearer-URL playback can use `['web']`, but URL issuance must remain behind an authorized endpoint. Signed-only mode does not enable global authentication. URLs are bearer credentials, and the definition's mandatory authorization callback still runs on every request. A session-dependent policy can deny a bearer URL without a session.

Keep published media and keys private and immutable. Replace an export by publishing to new paths and updating its inventory. CORS, trusted proxies, and secure URL issuance remain application responsibilities. This controller and AES-128 encryption do not provide DRM.

### Compose completed HLS presentations

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$merged = FFMpeg::mergeHls(['main' => $firstResult, 'alternate' => $secondResult])
    ->toDisk('streams')
    ->save('combined/master.m3u8');
$publishedPath = $merged->playlist()
    ->path; // Includes a generated version suffix.
$inventory = $merged->playback()
    ->toArray();
```

`FFMpeg::mergeHls(array $presentations): PendingHlsMerge` accepts at least two trusted completed v9 `HlsExportResult` objects, `HlsPresentation` objects, or persisted inventory arrays. Optional associative aliases prefix variant names and must be simple names. `toDisk(string $disk): self` chooses the shared media disk. Without it, the first master determines the disk. `save(string $path): HlsExportResult` requires a canonical `.m3u8` key and publishes a fresh version-suffixed master. Use the returned `playlist()->path`, not the requested path, for the published object.

Composition combines adaptive choices for the same timeline. It does not concatenate clips, transcode, copy segments, or rewrite existing playlists. Inputs require disjoint destinations, variant provenance, completed VOD playlists, and compatible packaging, clips, starts, and durations. Non-key artifacts must share the destination disk; private keys can remain on other disks. Missing artifacts, incompatible clocks, discontinuities, byte ranges, and unsupported master directives fail before publication. References outside an input's inventory and invalid playlists throw `InvalidArgumentException`; storage read failures throw `MediaException`. These checks use retained provenance and playlist structure, not a new full decode or a proof of bitstream compatibility.

Only the new master is `owned=true` in the returned generated-file inventory. Existing segments, playlists, initialization files, and keys are borrowed with `owned=false`. Keep their source inventories and application reference counts. Deleting an original presentation can break the composed presentation. Persist `playback()` or `playbackForVariants()` for composed playback; do not use `variant()` as a replacement for those inventory operations.

### Dynamic playlist responses

```php
use Illuminate\Support\Facades\URL;

// Inside an authorized controller. Resolve $path from this lesson's owned files.
return FFMpeg::disk('streams')
    ->playlist($path)
    ->playlistUrlsUsing(fn (?string $target, string $source, string $uri) => URL::temporarySignedRoute('lesson.playlist', now()
        ->addMinutes(10), [
            'lesson' => $lesson->id, 'path' => $target,
        ]))
    ->mediaUrlsUsing(fn (?string $target, string $source, string $uri) => Storage::disk('streams')
        ->temporaryUrl($target, now()
            ->addMinutes(10)))
    ->keyUrlsUsing(function (?string $target, string $source, string $uri) use ($lesson) {
        // Exported key URLs may be absolute. Look up the original URI in your owned key records.
        $key = $lesson->keys()
            ->where('playlist_uri', $uri)
            ->firstOrFail();

        return URL::temporarySignedRoute('lesson.key', now()
            ->addMinutes(10), [
                'lesson' => $lesson->id, 'key' => $key->id,
            ]);
    });
```

`playlist()` accepts a disk-relative `.m3u8` key or a filesystem instance through `disk()`. It implements Laravel `Responsable` and sends `application/vnd.apple.mpegurl` with `Cache-Control: private, no-store`. Your application authorizes every playlist request, scopes paths and keys to the lesson, validates signatures, serves the 16 raw key bytes, and configures CORS. A signed URL does not replace ownership checks. The example assumes local playlist and segment references.

```php
$playlist = FFMpeg::disk('streams')
    ->playlist('lessons/42/master.m3u8')
    ->mediaUrlsUsing(fn (?string $target, string $source, string $uri) => $target === null ? $uri : Storage::disk('streams')
        ->temporaryUrl($target, now()
            ->addMinutes(10)));

$text = $playlist->get(); // Rewrite only this playlist. No writes.
$all = $playlist->all();  // Collection keyed by original disk-relative playlist keys.
$variant = $all->get('lessons/42/720p/index.m3u8');
```

Each resolver receives the canonical disk-relative target, the containing playlist's disk key, and the original URI including its query. Relative paths decode once and resolve against the containing playlist. HTTP(S) and root-relative URIs have a `null` target. They remain available to the resolver but never trigger network reads. Missing resolvers leave valid URIs unchanged. Repeated references reuse a URL within that render; later calls generate fresh URLs.

`all()` follows original local playlist references once, including cycles. It does not follow rewritten URLs, root-relative URLs, or external URLs, and never reads keys or media. Each call allows at most 1,024 local playlists and 16 MiB each of aggregate input and output. `get()` has the same byte limits. CRLF becomes LF. URI roles come from `EXT-X-STREAM-INF`, `EXTINF`, and `EXT-X-KEY`, not filename extensions. Key attributes and IVs remain unchanged.

Invalid UTF-8, control characters, malformed URIs, disk-root traversal, incomplete declarations, and unsupported resource tags throw `MediaException` with phase `response` and category `artifact`. Missing or unreadable playlists use category `filesystem`. Adapter messages and secret URIs are omitted. Application callback exceptions propagate unchanged. This helper supports MPEG-TS, fMP4 initialization references, and alternate audio/subtitle playlists. Low-latency resource tags are rejected.

### Progress helpers

```php
use ProtoneMedia\LaravelFFMpeg\Progress;

$media->export()
    ->onProgress(function (Progress $progress) {
        if ($progress->isEncoding()) {
            // elapsedSeconds, percentage, speed, and remainingSeconds may be null.
        }

        if ($progress->phase === Progress::PUBLICATION) {
            // Outputs are validated; publication is not complete yet.
        }
    });
```

`Progress` defines typed string constants `STAGING`, `PROBING`, `ANALYSIS`, `ENCODING`, `VALIDATION`, and `PUBLICATION`. Their values remain `staging`, `probing`, `analysis`, `encoding`, `validation`, and `publication`. GIF, loudness normalization, and looped audio mixes report their preparation passes as `analysis`, with an elapsed time and speed but no percentage. The `phase` property remains a string, so existing callback comparisons continue to work. `isEncoding()` is true for the encoding-start notification and encoder updates, including updates without a known percentage. Other progress phases return false. `Events\ExportProgress` wraps these values for global listeners; completion remains a separate `Events\ExportCompleted` event after publication and cleanup, not a progress phase.

### Generated-file lookup

```php
$result->file();                                 // First output, unchanged.
$result->file('nested/preview.mp4');              // Unique destination path.
$result->file('full.mp4', disk: 'videos');        // Path on a named disk.
$result->file(disk: 'previews');                  // Unique output on this disk.
$result->files();                                // All generated files.
```

A lookup with a path or disk requires exactly one match. It uses literal, case-sensitive destination paths and disk names. No match throws `Illuminate\Support\ItemNotFoundException`. Multiple matches throw `Illuminate\Support\MultipleItemsFoundException`, following Laravel Collection's `sole()` behavior. A path shared by two disks requires the disk name to disambiguate it. Filesystem instances have no disk name; `files()` remains available for selections that the path and disk name cannot distinguish. Lookups neither access Storage nor change output order.

Generated files retain their destination filesystem, including supplied filesystem instances. `temporaryUrl($expiration, $options)` delegates to that filesystem without a public-URL fallback. Unsupported drivers throw. URLs are created on demand and are not included in `toArray()`.

```php
$file = $result->file();
$url = $file->temporaryUrl(now()
    ->addMinutes(5));
$stream = $file->readStream(); // The caller closes the stream with fclose().
```

`readStream()` returns a readable resource or `null`, following the destination's configured Storage failure behavior. Streams are not cached or serialized.

```php
foreach ($result->files() as $file) {
    if ($file->owned && ! $file->delete()) {
        throw new \RuntimeException('Cannot delete '.$file->path);
    }
}
```

`GeneratedFile::delete(): bool` returns `false` immediately when `owned=false`, without a filesystem call. Otherwise it delegates the exact destination path to the captured filesystem's `delete()` and returns that result. Filesystem exceptions propagate. It does not expand patterns, delete directories, update metadata, or track references. Missing-file behavior follows the adapter. For complete HLS cleanup, use the root result's `files()` rather than a variant view, which omits shared keys. Authorization, reference checks, retry policy, and deletion ordering belong to the application. Deletion is not transactional.

For an existing same-disk presentation, `HlsPlaylist::deleteAllFiles(callable $approve): self` discovers the original playlist references, ignoring response resolvers. Discovery and approval of every unique canonical disk path finish before deletion. The callback receives the path and must return exactly `true`. A refused path, malformed playlist, or unsafe/external reference aborts without deleting files. The application supplies ownership and shared-reference checks and prevents concurrent changes. Missing-file behavior follows the filesystem; a false delete result or exception raises a sanitized filesystem error and can leave partial deletion. Cross-disk keys require the retained result inventory instead.

### Queue exports

`PendingExport::queued(?string $connection = null, ?string $queue = null, DateTimeInterface|DateInterval|int|null $delay = null, bool $parallel = false): PendingQueuedExport` captures a detached export plan. Every pending export subclass inherits this method. Plan capture performs no media I/O. Changes to the original builder after capture do not change the queued plan.

`PendingQueuedExport` exposes a random `id`, `configureJob(callable): self`, `job(?string $path = null): ExportJob|HlsBatchJob`, and `save(?string $path = null): Illuminate\Foundation\Bus\PendingDispatch`. `job()` builds without dispatch. `save()` uses Laravel's pending-dispatch lifecycle. The synchronous export's destination requirements still apply.

Plans retain native builder objects, repeated descriptor references, readonly fields, and separately serialized callbacks. Filesystem instances, local `File` inputs, uploads, streams, and runtime services are rejected. Callbacks require static closures with scalar or array captures. Named disk configuration and durable disk contents belong to the application and must be available on every worker. Jobs implement `ShouldBeEncrypted` and require the application's encryption key.

`ExportJob` uses Laravel's `Queueable`, `Batchable`, `InteractsWithQueue`, and `SerializesModels` traits. Public controls include `tries`, `backoff`, `timeout`, `maxExceptions`, and `failOnTimeout`. Defaults are one attempt, zero backoff, a job timeout 30 seconds longer than the configured FFmpeg process timeout, and failure on timeout. `configureJob()` runs before dispatch and supports native middleware and dispatch controls. Worker queue visibility must exceed the job timeout. Operations with several process invocations can require a larger job timeout.

Ordinary jobs support `Bus::chain()` and `Bus::batch()`. Cancellation is cooperative: cancelled jobs do not start, and running jobs check cancellation independently of user progress callbacks. Exceptions retain Laravel's retry and failure behavior. Retries restart work, and publication is not transactional. Exactly-once completion and checkpoint recovery are not provided.

With `parallel: true`, HLS requires an explicit master path. Variants connected by shared audio or subtitle groups remain in one conversion job. Disconnected groups become independent batched jobs. A single connected group falls back to an ordinary queued export. Custom playlist callbacks, complex graphs, and unreferenced alternate groups are rejected for parallel execution.

Parallel artifact destinations use `laravel-ffmpeg/queued/{id}/parts/{group}/` on the shared destination disk. Custom artifact paths are namespaced beneath that prefix. Each successful conversion writes a private inventory in `laravel-ffmpeg/queued/{id}/inventories/`, retaining its batch ID. Retried part jobs with a completed inventory skip re-encoding. Laravel batch failures or cancellation prevent finalizer dispatch. The finalizer also verifies that every inventory belongs to the same successful, uncancelled batch before composing the master. Batch records must remain available until finalization completes.

The finalizer uses `PendingHlsMerge`, including its clock checks, immutable versioned master path, and borrowed-source ownership. The unversioned requested path remains unchanged. Conversion parts remain live artifacts referenced by the master. Neither failure nor cancellation deletes published parts. Retention, reference tracking, and removal of abandoned artifacts belong to the application. A parallel workflow composes with Laravel chains but rejects nesting inside another batch, which would otherwise count the coordinator as complete before its conversions finish.

`QueuedExportCompleted` contains `exportId`, an array of generated-file metadata in `files`, and an optional serializable `playback` inventory. Part jobs do not emit this event. Single exports and successful finalizers emit it after publication. Event listeners and downstream jobs must tolerate repeated attempts. Result persistence and queue or batch migrations belong to the application.

### Probe accessors

```php
$metadata = $media->probe();
$english = $metadata->audioStreams()
    ->first(fn ($stream) => $stream->language() === 'eng');

$english?->codec();      // Nullable string, such as "aac".
$english?->channels();   // Nullable integer, such as 2.
$english?->sampleRate(); // Hz, such as 48000.
$english?->bitrate();    // Bits per second, such as 192000.
$english?->isDefault();  // Boolean disposition flag.

$metadata->hasVideo();
$metadata->hasAudio();
$metadata->subtitleStreams()
    ->first(fn ($stream) => $stream->isForced());
$metadata->coverArt()
    ->first();
$metadata->chapters()
    ->first();
$metadata->tag('title');
```

`Stream` exposes `index()`, `type()`, `codec()`, `language()`, `tag($key)`, `isDefault()`, `isForced()`, `isCoverArt()`, `channels()`, `sampleRate()`, and `bitrate()`. Missing scalar values return `null`. Channels, sample rate, and bitrate require positive integers and return `null` for unavailable, fractional, or overflowing values. Missing disposition flags return `false`. `get()` and `toArray()` retain the original values without conversion.

Stream and format `tag()` lookups use literal, case-sensitive keys, including keys containing dots. Missing tags return `null`. `subtitle()` returns the first subtitle stream or `null`. `coverArt()` returns a collection of attached-picture video streams, separate from `videoStreams()` and `video()`. An audio file with cover art does not satisfy `hasVideo()`. `chapters()` returns a collection of unchanged chapter arrays, or an empty collection when chapters are absent. All stream collections retain source identity for the mapping helpers below.

### Current export API

`PendingExport::coverArt(Media $image): self` attaches one standalone PNG or JPEG without input options. It copies every motion-video and audio track and marks the image as attached art. Output is limited to MP4, M4A, or MP3, with one primary input and one output. Existing attached art is rejected instead of replaced. Custom maps, graphs, watermarks, encoding settings, filters, clipping, raw output options, disposition overrides, and two-pass encoding are unsupported. This differs from `Metadata::coverArt()`, which reads existing attached-picture streams.

```php
$media->export()
    ->coverArt(FFMpeg::disk('assets')
        ->open('cover.jpg'))
    ->save('covered.mp4');

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

`videoStream(int $track, callable $configure): self` configures a zero-based output video track after mapping. It is available on output configuration objects, including `output()` callbacks. The callback receives `VideoStreamSettings` with fluent `codec(string)`, `bitrate(int|string)`, `crf(float)`, and `preset(string)` methods. Overrides inherit unspecified type-wide `video()` settings. Each index can be configured once and must identify an existing output video track at execution. Inherited bitrate and CRF conflicts fail, as do copy settings with filters or encoder options. Per-track overrides reject two-pass encoding. They do not create tracks or independent per-track filter chains.

The subtitle recipe uses existing explicit maps and output options, not a new extraction helper. The source needs a text subtitle stream decodable as WebVTT. Bitmap subtitles require an external OCR workflow. No automatic transcription or subtitle generation runs.

```php
$result = FFMpeg::disk('uploads')
    ->open('original.mov', inputOptions: ['-probesize', '2000000'])
    ->export()
    ->video('libx264', crf: 23, preset: 'medium')
    ->audio('aac', bitrate: '128k')
    ->clip(12.5, duration: 8)
    ->toDisk('videos', ['visibility' => 'private', 'ContentType' => 'video/mp4'])
    ->save('converted.mp4');
```

`toDisk($disk, $options)` merges write options with earlier calls instead of replacing them. The last explicitly supplied value for a key wins. `visibility()` follows the same rule, so `->visibility('public')->toDisk('videos')` preserves public visibility. Options remain independent between exports and output callbacks. In multi-output exports, destination settings still belong inside each `output()` callback, not on the export root.

`clip()` accepts numeric seconds or strict `HH:MM:SS` timecodes for start, duration, and end. Hours have at least two digits. Minutes and seconds range from `00` to `59`. An optional decimal fraction has one to six digits. Timecodes normalize to seconds before the existing range checks. Strings such as `'12.5'`, signed values, whitespace, suffixes, and other FFmpeg duration formats are rejected. Numeric `12.5` remains supported. Start must be nonnegative; exactly one of positive duration or end after start is required. Seek modes and output-callback behavior are unchanged.

Clip arguments use plain decimal seconds at microsecond precision, never scientific notation. This applies to accurate output seeks and keyframe input seeks.

```php
$media->export()
    ->clip('00:00:13.375', duration: '00:00:02.125');
$media->export()
    ->clip('01:02:59.750', end: '01:03:01.125');
$media->export()
    ->clip(13.375, duration: '00:00:02.125');
```

Input options, `globalOptions()`, and `outputOptions()` accept flat option/value pairs from a closed allowlist. Every allowed option has library-defined arity and value validation. Unknown options, aliases, switches with caller-supplied values, duplicate nonrepeatable options, filename controls, authentication headers, and encoder parameter blobs are rejected. Output `-f` permits only listed single-file muxers. These methods are not unrestricted argv passthrough.

Filters are developer-trusted FFmpeg expressions, not end-user text. They can read local resources or load plugins. Local and downloaded inputs use `-protocol_whitelist file`. Direct HTTP inputs use a transport-specific protocol whitelist. Neither policy isolates local file access or applies to files opened by filters. Untrusted media still requires process isolation and resource limits. There is no generic demuxer allowlist or hostile-media sandbox.

### Two-pass bitrate encoding

```php
$result = $media->export()
    ->video('libx264', bitrate: '2500k', preset: 'medium')
    ->audio('aac', bitrate: '128k')
    ->twoPass()
    ->save('converted.mp4');
```

An ordinary export runs one encoding pass. `twoPass()` explicitly runs two passes for every output in that export. Every output requires an explicit video encoder and target bitrate, without CRF or video stream copy. Images and audio-only selections are rejected. The encoder must support FFmpeg's `-pass` and `-passlogfile` mechanism. Real acceptance covers libx264. Hardware-internal multipass is separate.

Both passes use the same inputs, maps, graph, filters, clips, and encoding settings. The first pass uses null muxers and publishes nothing. Each output has a separate statistics prefix relative to its operation-private workspace. Missing or empty first-pass statistics fail before the final pass. Statistics remain private and are removed after success, failure, or cancellation. Concurrent or nested exports cannot overwrite one another's statistics.

Encoding progress reports `pass` as one or two and `totalPasses` as two, including encoding-start notifications. Percentage and remaining time describe the current pass, not the whole operation. Timing-changing graphs leave percentage unknown. The result contains both process summaries in order. Validation and publication follow the final pass, and completion follows cleanup. `inspect()` remains pure and shows the final-pass command with private relative statistics prefixes.

### Stream selection shortcuts

```php
$media = FFMpeg::disk('uploads')
    ->open('original.mov');

$media->export()
    ->mapVideo(0)
    ->mapAudio(0, language: 'eng', optional: true)
    ->video('copy')
    ->audio('copy')
    ->toDisk('videos')
    ->save('english.mkv');

$metadata = $media->probe();
$video = $metadata->videoStreams()
    ->first();
$english = $metadata->audioStreams()
    ->first(fn ($stream) => $stream->language() === 'eng');

$media->export()
    ->mapVideo($video)
    ->mapAudio($english)
    ->video('copy')
    ->audio('copy')
    ->toDisk('videos')
    ->save('selected.mkv');
```

The object example assumes both streams exist. `mapVideo()` and `mapAudio()` accept a numeric input index or a probed `Stream`. The default selects input 0's first stream of that type. `track:` selects a zero-based track within that type. `language:` selects all matching audio tracks and cannot accompany `track:`. `optional: true` omits missing matches, but an entirely empty selection still fails. Raw `map()` remains available for other selectors, negative maps, and graph labels. Optional maps compile with a separator before `?` so FFmpeg 9 does not treat it as part of a metadata value.

A probed stream maps its absolute `index()` and retains its source identity. Its source must be an export input. When the same source occurs more than once, `mapVideo($video, input: 1)` chooses its input position explicitly. A hint referring to different media fails. Object maps cannot accompany `track:` or `language:` and never probe again during inspection. A later `save()` stages and validates the current input as usual. These helpers also work in `output()` callbacks.

### Geometry shortcuts

For an autorotated portrait source, `resize(height: 1920)` preserves display aspect ratio. `fit(1080, 1920, mode: 'contain')` produces an exact portrait canvas with padding, while `mode: 'cover'` crops to that canvas. Two explicit resize dimensions stretch. Probe `width()` and `height()` remain encoded dimensions rather than rotation-adjusted display dimensions.

```php
$media->export()
    ->resize(width: 1280);          // Preserve display aspect ratio.
$media->export()
    ->resize(height: 720);          // Infer an even width.
$media->export()
    ->resize(1280, 720);            // Stretch to both dimensions.
$media->export()
    ->fit(1280, 720);               // Contain with centered black padding.
$media->export()
    ->fit(1280, 720, mode: 'cover'); // Fill with a centered crop.
```

These settings work inside `output()` callbacks too. They append to the video filter chain in call order and cannot accompany video stream copy. Graph-fed streams follow the same simple-versus-complex filter restrictions as `videoFilter()`.

Both helpers produce square pixels and allow upscaling. A one-dimension resize uses display aspect ratio, including the input's sample aspect ratio, and rounds the inferred dimension to a multiple of two. Two explicit resize dimensions stretch the image. `fit()` preserves display aspect ratio within rounding, requires positive even dimensions, and returns that exact canvas size. `contain` preserves the image with black bars. `cover` removes the edges outside a centered crop.

```php
$media->export()
    ->crop(1280, 720, x: 40, y: 20)
    ->rotate(90)
    ->pad(800, 1280, color: 'black');
```

`rotate()` accepts clockwise 90, 180, or 270 degrees and negative equivalents, after input autorotation. `crop()` and `pad()` require positive even dimensions and nonnegative optional offsets, centered by default. All three append filters in call order and retain the ordinary copy/filter restrictions. Real decoded pixels verify crop, rotation direction, padding, and order in `NativePreservationTest`.

### Video and audio adjustments

```php
$media->export()
    ->video('libx264')
    ->audio('aac')
    ->deinterlace(mode: 'frame', parity: 'auto', onlyInterlaced: true)
    ->resize(width: 1280)
    ->frameRate('30000/1001')
    ->flipHorizontal()
    ->flipVertical()
    ->grayscale()
    ->volume(0.5)
    ->delayAudio(0.25)
    ->fadeIn(1)
    ->fadeOut(1, start: 9)
    ->save('adjusted.mp4');
```

These helpers append ordinary filters in call order and work in `output()` callbacks. They do not support stream copy for the affected type. `videoFilter()` and `audioFilter()` remain available for trusted expressions. Graph-fed streams retain the simple-versus-complex filter restrictions described below.

`frameRate($rate)` accepts a positive numeric or rational rate and drops or duplicates frames without changing playback speed. `flipHorizontal()` and `flipVertical()` mirror the image. `grayscale()` removes color. `deinterlace()` uses `bwdif`; call it before geometry filters. Its default `frame` mode outputs one frame per input frame, while `field` outputs one per field. Parity accepts `auto`, `tff`, or `bff`. `onlyInterlaced: true` processes only frames marked interlaced; false processes all frames.

`volume($multiplier)` scales amplitude with a finite nonnegative multiplier. Zero mutes; values above one can clip. `delayAudio($seconds)` inserts actual silence on every channel, extending audio rather than shifting its timestamps. It accepts finite nonnegative seconds. Exports with audio delay leave progress percentage unknown because the selected audio can outlast the video.

`fadeIn($duration, $start = 0, $video = true, $audio = true)` and `fadeOut($duration, $start, $video = true, $audio = true)` fade video to or from black and audio to or from silence. Duration must be positive and start nonnegative, both in seconds. At least one stream type must be enabled. Fade-out timing is explicit; the helper does not probe for the end. Timing filters follow FFmpeg's timestamps in filter order, not an automatically rebased clip timeline.

### Audio mixing

```php
$primary = FFMpeg::open($request->file('video'));
$music = FFMpeg::disk('music')
    ->open('background.wav');

$primary->backgroundMusic($music, volume: 0.2, loop: true)
    ->at(2.5)
    ->video('copy')
    ->audio('aac')
    ->save('background.mp4');

$primary->mixAudio($music, volume: 0.5, originalVolume: 1, normalize: false)
    ->loopAudio(false)
    ->volume(0.8)
    ->fadeIn(1, video: false)
    ->video('copy')
    ->audio('aac')
    ->save('one-shot.mp4');

FFMpeg::open('voice.wav')
    ->mixAudio($music, volume: 0.25)
    ->audio('pcm_s16le')
    ->save('mixed.wav');
```

`Media::mixAudio(Media $audio, float $volume = 1, float $originalVolume = 1, bool $normalize = false)` returns a `PendingAudioMix` single-output builder. It mixes the primary input's first audio stream, when present, with the added input's first audio stream. `Media::backgroundMusic(Media $music, float $volume = 0.2, bool $loop = true)` is shorthand for `mixAudio($music, $volume)->loopAudio($loop)`. `PendingAudioMix::loopAudio(bool $loop = true)` controls repetition of the added track. Ordinary `mixAudio()` does not loop.

The primary container's finite positive duration determines output length, not the shortest stream or the added track. Both audio branches have silence padding. A short added track plays once then contributes silence, or repeats when looping is enabled. Longer added audio is trimmed at the primary duration. A primary silent video uses only added audio. Audio-only primaries, including WAV files, are supported.

`PendingAudioMix::at(float $seconds): self` sets a finite nonnegative start offset for only the added audio. The default is zero, and later calls replace the value. The delay runs after the added track's timestamp reset and gain, before mixing, at the nearest 48000 Hz sample. Original audio timestamps and gain remain unchanged. Loops start at the offset and repeat the added samples without repeating the initial delay. Offsets at or beyond the primary duration produce no added signal and do not extend output. Execution bounds the delay to the primary duration so very large finite offsets do not allocate an equally large delay buffer. Negative values, NaN, and infinities fail before IO.

Selection includes the first motion-video stream when present and one mixed audio stream. `withoutVideo()` omits video. The mixed audio is stereo at 48000 Hz. It requires encoding and rejects `audio('copy')`. Source video can use `video('copy')` unless video filters require encoding. Caller audio filters, including `volume()` and audio fades, run after mixing in declaration order. Video filters remain output filters.

The added track's timestamps reset to its first decoded sample. Original audio keeps its nonnegative delay relative to video, with silence inserted before the original samples. A primary containing video requires a known zero container origin and known zero origins on every video stream. Its first audio stream, if present, requires a known nonnegative origin. Audio-only primaries do not require reported timestamp origins. The added input requires a finite positive duration and an audio stream.

`volume` and `originalVolume` are finite nonnegative amplitude multipliers applied before mixing. With `normalize: false`, their signals add and can clip. With original audio present, `normalize: true` divides the two weighted branches equally by two. The divisor stays constant after either track ends because both branches are padded. It does not measure loudness or increase the remaining branch's gain at EOF. A primary without audio has only one branch and no division by two.

Looping requires a local or downloaded added input. URL music must use `FFMpeg::openUrl($url, download: true)` before looping, with the ordinary HTTP header and download rules. Nonlooping direct URLs remain supported for finite sources.

Looping first decodes the added track into a private lossless PCM file, then repeats those samples. This avoids repeating compressed encoder delay and padding. Preparation adds one FFmpeg invocation and disk use proportional to decoded music duration, sample rate, and channel count. The file is removed after success, failure, or cancellation. Result process summaries include preparation and encoding.

The helper owns its graph and maps and accepts only the primary input and one added input. It rejects custom maps, custom graphs, output callbacks, watermarks, clips, audio omission, and two-pass encoding. To mix a clip, save the clip first. Publication defaults to the primary media's output disk. Progress percentage uses the primary duration only when no caller audio filters are present.

### Playback speed

```php
$media->speed(2)
    ->video('libx264', crf: 23)
    ->audio('aac')
    ->save('faster.mp4');
$media->speed(0.5)
    ->withoutVideo()
    ->audio('aac')
    ->save('slower.m4a');
```

`Media::speed(float $factor)` returns a single-output builder. A finite positive factor above one speeds up playback; a factor below one slows playback. Video timestamps and audio delays are divided by the factor, while chained `atempo` filters preserve audio pitch. Existing simple filters run before the speed adjustment. The default selection remains the first motion-video and audio streams; explicit audio and video maps are supported.

Sources require a zero container origin, zero origins on every video track, and nonnegative origins on every audio track, including unselected tracks. Missing origins fail rather than trigger a guessed correction. Clipping, input options, additional inputs, custom graphs, watermarks, selections other than audio or video, and multiple outputs are rejected. Filtered streams require transcoding. Chapters are omitted by default because their timestamps no longer match. Progress uses known source duration divided by the factor only with unmodified default selection and at most one audio and one motion-video track. Extra tracks, cover art, custom maps, filters, or output timing options leave percentage unknown. Encoding cost still depends on the decoded source frames, not just the shorter output duration.

### Animated GIF previews

```php
$media->gif(width: 480, frameRate: 12, loop: 0)
    ->clip(4, duration: 3)
    ->toDisk('images')
    ->save('preview.gif');
```

`Media::gif(int $width = 480, int|float $frameRate = 12, int $loop = 0)` selects the first motion-video stream and omits audio. Width must be between 1 and 65535; frame rate must be finite and positive. The inferred height preserves display aspect ratio. Loop count accepts -1 through 65535; zero loops forever and -1 disables looping.

GIF stores frame delays in centiseconds, so requested frame rates can require rounded or alternating frame delays.

Accurate clipping and timestamp reset precede resizing, frame-rate conversion, and palette generation. The helper first writes those processed frames to a private lossless intermediate, then generates a palette, then encodes with `paletteuse`. These are three FFmpeg invocations, not two-pass bitrate encoding. Caller filters run once. The intermediate avoids buffering the entire clip in memory, but private disk use grows with duration, dimensions, and frame rate. Short, reduced-resolution previews reduce processing time and disk use. Cleanup removes the intermediate and palette after success, failure, or cancellation.

GIF owns its graph, maps, codec, muxer, and loop options. It supports one output and rejects additional inputs, custom graphs or maps, audio settings or filters, encoder overrides, raw output options, keyframe clips, and `twoPass()`. Ordinary string video filters can follow the initial resize and frame-rate filters; resource-backed filters are not supported by this builder.

### Text and subtitle shortcuts

```php
$font = FFMpeg::disk('assets')
    ->open('fonts/Brand.ttf');
$captions = FFMpeg::disk('assets')
    ->open('captions/English.srt');

$media->export()
    ->resize(width: 1280)
    ->drawText("100%: café 'quoted' %{literal}", font: $font, size: 32, x: 24, y: 24)
    ->burnSubtitles($captions)
    ->clip(4, duration: 2)
    ->video('libx264', crf: 23)
    ->toDisk('videos')
    ->save('captioned.mp4');
```

`drawText()` stages literal UTF-8 text in a private file with expansion and reload disabled. Font descriptors can use any supported disk. Size must be positive, coordinates are integers, and color accepts a name or `0xRRGGBB` with optional alpha bytes. `burnSubtitles()` uses the subtitle validation described below. Both helpers work in `output()` callbacks and share staged assets across outputs without named placeholders.

Ordinary filters run in call order, before all generated watermark overlays, even when called after `watermark()`. Burning subtitles before resizing scales the rendered text with the image. Burning them after resizing renders at the final resolution. Any subtitle helper rejects input/keyframe seeking, even when it belongs to another output. Settings freeze at execution, and resource failures identify the output index and helper. Font files must be readable regular files; FFmpeg validates the font contents when rendering.

### Concatenation

```php
$first = FFMpeg::disk('uploads')
    ->open('first.mp4');
$second = FFMpeg::disk('archive')
    ->open('second.mp4');

$first->concatTranscoded($second)
    ->normalizeVideo(1280, 720, frameRate: 30, fit: 'contain')
    ->normalizeAudio(sampleRate: 48000, channels: 2)
    ->video('libx264', crf: 23)
    ->audio('aac', bitrate: '128k')
    ->toDisk('videos')
    ->save('normalized.mp4');

$first->concatTranscoded($second)
    ->normalizeVideo(1280, 720, frameRate: 30, fit: 'cover')
    ->withoutAudio()
    ->video('libx264')
    ->toDisk('videos')
    ->save('silent.mp4');
```

`concatTranscoded()` accepts at least one following source and returns a single-output export builder, not a media descriptor. Publication defaults to the first input's output disk. `normalizeVideo()` is required and takes positive even dimensions, a positive numeric or rational frame rate, and `contain`, `cover`, or `stretch`. It produces square pixels. `contain` adds centered black padding; `cover` crops centrally; `stretch` uses the exact dimensions without preserving aspect ratio.

Choose either `normalizeAudio()` or `withoutAudio()`. Audio normalization defaults to 48000 Hz and two channels. `channels` accepts one or two; optional `channel` selects a zero-based source channel before conversion. For example, `normalizeAudio(16000, channels: 1, channel: 0)` keeps the first channel instead of mixing channels. Every source must have a first audio stream when normalization is enabled. Resampling inserts silence for its original nonnegative delay relative to video. The helper does not create audio tracks for sources without audio.

Each source must have a finite positive duration, a zero container origin, and a zero first motion-video origin. Missing origins and input options are rejected. Selection uses the first motion-video and first audio streams, not cover art. The helper owns the graph and output maps and omits global metadata and chapters. It rejects caller maps and source metadata or chapter overrides rather than silently ignoring them. Additional inputs, custom graphs, output callbacks, root video/audio filters, and stream copy are unsupported. Use ordinary `export()->filterComplex()` for other policies. Every clip is decoded and encoded; frame rounding and audio tails can affect the total duration, so percentage remains unknown.

For compatible inputs, copy concatenation avoids re-encoding:

```php
$joined = $first->concat($second);
$joined->export()
    ->video('copy')
    ->audio('copy')
    ->toDisk('videos')
    ->save('joined.mp4');
```

`concat()` returns a media descriptor and does no IO until probing or export. It requires at least one following input. Its exports default to the first input's output disk. Compatible finite files join through the concat demuxer. Local files and non-local Storage inputs can be mixed. URL inputs require `download: true`. Input-specific media options, nested concat inputs, manifest sources, unknown durations, and nonzero reported presentation origins are rejected. Nonzero origins require an explicit normalization graph rather than a guessed timestamp correction.

Stream order, codecs, profiles, time bases, geometry, pixel formats, sample rates, channel layouts, and other observable codec parameters must match. This comparison cannot prove bitstream compatibility. FFmpeg remains authoritative, and failures never trigger automatic transcoding. Codec choices remain explicit export settings. The ordinary first-video and first-audio selection defaults still apply unless maps replace them.

The operation writes its own relative-name manifest with declared durations. Safe generated aliases isolate FFconcat filename syntax from caller keys, including quotes, Unicode, and newlines in local filenames. Aliases use private symlinks where supported, otherwise private copies. Cleanup does not modify or delete source files. Original chapter timelines are not combined. Results and publication use the ordinary export pipeline.

Custom normalization policies use the composition API. This video-only graph stretches both inputs, sets square pixels and a common frame rate, resets video timestamps, and excludes audio:

```php
$export = $first->export();
$export->addInput($second);
$export->filterComplex(
    '[0:v]scale=128:96,setsar=1,fps=10,setpts=PTS-STARTPTS[first];'
    .'[1:v]scale=128:96,setsar=1,fps=10,setpts=PTS-STARTPTS[second];'
    .'[first][second]concat=n=2:v=1:a=0[joined]'
)
    ->map('[joined]')
    ->video('libx264', crf: 20)
    ->withoutMetadata(chapters: true)
    ->toDisk('videos')
    ->save('normalized.mp4');
```

Audio-inclusive custom graphs must declare their audio policy and preserve each segment's audio delay relative to video. Resetting each stream independently to its first sample can erase that delay. Unlike `concatTranscoded()`, raw graphs do not add silence or channel conversion for you. Graph timing leaves percentage unknown.

`NativeConcatTest` verifies a zero-origin policy with different dimensions, frame rates, sample rates, and channel layouts. It selects the first audio channel and resamples to 16000 Hz with `aresample=16000:async=1:first_pts=0`. The concat filter pads shorter intermediate audio segments. Decoded PCM confirms silence during each original audio delay and the expected signal afterward. Inputs with other origins require a common timeline correction, not independent first-sample resets.

### Image-sequence inputs and timelapse

```php
FFMpeg::disk('images')
    ->open('title.png')
    ->still(duration: 5, frameRate: 25)
    ->toDisk('videos')
    ->save('title.mp4');
```

`Media::still(float $duration, int $frameRate = 25): PendingStill` holds a standalone PNG or JPEG for a finite positive duration. Frame rate is an integer from 1 to 240. Input images must have even dimensions of at least two pixels, without media input options. Local, staged Storage, and downloaded images are supported. The builder defaults to `libx264` and `yuv420p`, generates no audio, and supports one root-configured output. Ordinary output geometry and publication settings remain available. `clip()` is rejected because `still()` owns duration. Frame rounding can affect the final duration. This is a repeated still image, not a timelapse or animation decoder.

```php
FFMpeg::disk('photos')
    ->openSequence('camera/frame-%04d.png', count: 9, framerate: 1)
    ->export()
    ->video('libx264', crf: 23)
    ->outputOptions(['-pix_fmt', 'yuv420p'])
    ->toDisk('videos')
    ->save('timelapse.mp4');
```

`openSequence()` returns a media descriptor without reading Storage or launching a process. The pattern is a disk-relative PNG or JPEG key with one `%d` or `%0Nd` placeholder in its filename, where N is from 1 to 99. Globs, directory placeholders, and other image formats are not supported. `count` is positive, and `start` defaults to 1 but can be zero. Every key in that contiguous range must exist. Files outside the range are not read.

`framerate` is a positive decimal rate or a rational string such as `'30000/1001'`. It controls input timing, not an output frame-rate conversion. Nine images at one frame per second produce a nine-second video. Output codecs, geometry, filters, clipping, and publication use the ordinary export API. The default output disk remains the sequence's input disk.

Each execution stages non-local images and prepares safe private numbered aliases. Local sources use symlinks where supported, otherwise private copies. All image headers must match the declared format and share dimensions. Missing files, mismatched dimensions, and invalid headers fail before encoding. Native exports use `-xerror` so corrupt image data fails encoding instead of silently publishing a shortened sequence. Cleanup removes only owned files and aliases, never the originals.

Sequences can be graph inputs but are not standalone files for copy concatenation or filter resources. Encode a sequence to a regular media file before passing it to `concat()`.

### Watermarks and image preparation

```php
$logo = FFMpeg::disk('assets')
    ->open('brand/logo.png');

FFMpeg::disk('videos')
    ->open('source.mkv')
    ->export()
    ->watermark($logo, position: 'bottom-right', x: 20, y: 15, width: 160, opacity: 0.8)
    ->resize(width: 1280)
    ->video('libx264', crf: 23)
    ->audio('copy')
    ->toDisk('videos')
    ->save('branded.mkv');
```

`PendingExport::watermark()` owns the image inputs, graph, and maps. It accepts standalone PNG, JPEG, or GIF images, including prepared images and staged Storage inputs. Only GIF can have `inputOptions: ['-ignore_loop', '0']` or `['-ignore_loop', '1']`; other image input options are rejected. Defaults are `position: 'bottom-right'`, `x: 0`, `y: 0`, no resize, and `opacity: 1`. Opacity ranges from zero to one. Anchors accept `top-left`, `top-right`, `bottom-left`, `bottom-right`, and `center`.

Offsets are nonnegative inward margins at corners; center offsets move right and down. A single positive width or height preserves the image aspect ratio; supplying both stretches it. Repeated calls layer marks in call order. Each output's video filters run before all marks, regardless of registration order, so offsets refer to the final video canvas. Static images remain visible through the end of the video.

The helper supports ordinary media exports, including multiple `output()` callbacks, and HLS variants. Marks apply to each output; configure codecs, geometry, destinations, and audio separately inside the callbacks. Video copy, custom maps, custom graphs, and additional media inputs are unsupported. Audio copy remains valid. Use a raw graph for other composition requirements:

```php
$badge = FFMpeg::disk('assets')
    ->open('badge.gif', inputOptions: ['-ignore_loop', '0']);
$media->hls()
    ->watermark($badge, width: 96)
    ->variant('720p', fn ($variant) => $variant->resize(height: 720)
        ->video('libx264', bitrate: '2500k'))
    ->toDisk('streams')
    ->save('branded/master.m3u8');
```

`-ignore_loop 0` honors the GIF's embedded loop instructions. It does not force infinite repetition when the image specifies a finite loop. `-ignore_loop 1` ignores the loop instructions. At EOF the overlay repeats its final frame. A looping overlay ends with the main video, and audio or other streams keep their own length. All ordinary video filters, including HLS variant geometry, run before overlays regardless of registration order.

```php
$export = FFMpeg::disk('videos')
    ->open('source.mkv')
    ->export();
$logo = $export->addInput(FFMpeg::disk('assets')
    ->open('brand/logo.png'));
$export->filterComplex(
    '['.$logo.':v]scale=160:-1,format=rgba[logo];'
    .'[0:v][logo]overlay=x=main_w-overlay_w-20:y=main_h-overlay_h-15:'
    .'eof_action=repeat:repeatlast=1:shortest=0[video]'
)
    ->map('[video]')
    ->map('0:a:0?')
    ->video('libx264', crf: 23)
    ->audio('copy')
    ->withoutMetadata(chapters: true)
    ->toDisk('videos')
    ->save('branded.mkv');
```

Local and non-local Storage images use the same API. A downloaded URL image uses `openUrl($url, headers: $headers, download: true)`. No filename is embedded in the filter graph.

`Media::prepareImage(callable $prepare)` optionally preprocesses an image before FFmpeg reads it. The callback receives the path of a private writable copy, not the original. It must write the prepared image to that same path. The package does not depend on an image library or forward unknown methods to one. For example, an application with Spatie Image installed can supply:

```php
$logo = FFMpeg::disk('assets')
    ->open('brand/logo.png')
    ->prepareImage(function (string $path) {
        \Spatie\Image\Image::load($path)
            ->width(320)
            ->save();
    });
// Pass $logo to watermark(), or addInput() for a custom overlay graph.
```

Preparation returns an immutable media descriptor. Construction and inspection do no IO and do not call the callback. The callback runs during staging once per media identity and staging purpose in each execution. Reusing the descriptor in a later execution creates a new private copy and reruns preparation. Callback results do not persist across executions. Input options and multi-file sources are rejected.

URL images always download through Laravel Http before preparation, even when the source descriptor selects direct input. Callbacks and FFmpeg receive local filenames, never URLs or headers. Input and prepared output must have readable raster image headers. Callback errors and invalid prepared images fail with phase `staging` and category `resource`, without publication or an exception chain that could expose private paths or credentials. FFmpeg still validates image decoding. The callback is trusted application code, not a sandbox.

Ordinary exports are verified with transparent local and staged PNGs, scaled bottom-right offsets, persistent overlays, unchanged decoded audio, private-copy preprocessing, source retention, and repeated executions. Native HLS acceptance also verifies a shared overlay graph with two scaled branches, accurate clipping, and decoded watermark pixels.

### Composition and resources

`lut3d(Media $look)` adds an ordered video filter backed by a privately staged LUT file. It works on the export builder and independently in each `output()` callback, including alongside `resize()`. LUT descriptors cannot contain media input options. Filenames do not enter filter expressions directly. FFmpeg validates the LUT contents.

Reusing a LUT descriptor does not share decoded filter work. Each output runs its own LUT and resize filters. The helper does not generate a shared LUT or `split`. For shared-before-split processing, use the raw graph below, which applies the LUT once before branching.

```php
$export = FFMpeg::disk('uploads')
    ->open('source.mov')
    ->export();
$audio = $export->addInput(FFMpeg::disk('recordings')
    ->open('narration.wav'));

$result = $export
    ->resource('look', FFMpeg::disk('assets')
        ->open('looks/grade.cube'))
    ->filterComplex('[0:v]lut3d=file={{look}},split[full][small];[small]scale=640:360[preview]')
    ->output('full.mp4', fn (ExportOutput $out) => $out
        ->map('[full]')
        ->map($audio.':a:0')
        ->video('libx264', crf: 21)
        ->audio('aac', bitrate: '128k')
        ->metadataFrom(0)
        ->chaptersFrom(null)
        ->toDisk('videos'))
    ->output('preview.mp4', fn (ExportOutput $out) => $out
        ->map('[preview]')
        ->video('libx264', crf: 28)
        ->withoutMetadata(chapters: true)
        ->toDisk('previews'))
    ->save();
```

The example uses `ProtoneMedia\LaravelFFMpeg\ExportOutput`. Explicit outputs default to `filesystems.default`, not the first input disk. Adding an input also changes the single-output convenience default to `filesystems.default` unless `toDisk()` was explicit. Root output settings cannot be mixed with `output()` callbacks. After `output()`, `save()` takes no path. Duplicate destinations and composition destinations matching an input's disk/key identity are rejected.

`withoutMetadata(chapters: true)` is shorthand for `withoutMetadata()->chaptersFrom(null)`. It explicitly omits both global metadata and chapters. The default `withoutMetadata()` and `chapters: false` leave the chapter source unchanged, including earlier selections. A later `chaptersFrom($input)` overrides the omission. The shorthand works on the single-output export and in output callbacks. Multiple inputs still require explicit metadata and chapter sources or omission for each output. Clip defaults remain unchanged.

Graph labels are simple identifiers. Each named output label must be consumed or mapped exactly once. Branching requires `split` or `asplit`. A quote-aware lexer ignores brackets inside literal values. FFmpeg remains responsible for filter syntax and pad types. Graph-produced streams do not automatically preserve input language tags or dispositions.

### Output stream tags and dispositions

```php
$media->export()
    ->filterComplex('[0:a]volume=0.5[commentary]')
    ->map('[commentary]')
    ->mapVideo(0) // Audio is output stream 0, video is stream 1.
    ->audio('aac', bitrate: '128k')
    ->video('copy')
    ->streamMetadata('a:0', 'language', 'eng')
    ->streamMetadata(0, 'title', 'English commentary')
    ->disposition('a:0', ['default', 'comment'])
    ->toDisk('videos')
    ->save('commentary.mkv');
```

These methods also work in `output()` callbacks and apply to that output only. Their selectors address **output order after mapping**, not input positions or graph labels. `0` selects the first output stream overall, `'a:0'` selects the first output audio stream, and `'a'` selects all output audio streams. Supported types are `v`, `a`, `s`, `d`, and `t`. Input selectors such as `'0:a:0'`, graph labels, and optional `?` suffixes are rejected.

`streamMetadata($stream, $key, $value)` sets an output-stream tag. An empty value removes that tag. Values may contain Unicode and punctuation, but not NUL bytes. Global `metadata()` remains separate. Explicit stream tags can restore language or title on graph-produced streams even when global metadata is omitted.

`disposition($stream, $flags)` replaces all disposition flags for matching output streams. It accepts a supported FFmpeg 9 flag name, a `+`-joined set such as `'default+forced'`, or a list such as `['default', 'forced']`. An empty list or `'0'` clears all flags. Relative `+flag` and `-flag` edits are not accepted by this helper. Raw `outputOptions()` remains available for those edits and is emitted after the named helpers.

Calls preserve declaration order, including overlapping selectors. Later matches override earlier values. These settings do not select streams, and retention depends on the output container. Configuration freezes when execution starts.

Type-scoped encoding settings and simple filters apply to every selected stream of that type, including graph outputs. A graph-filtered video can accompany copied input audio. Copying a graph-fed stream or applying both a simple filter and a complex graph to the same stream fails during FFmpeg output setup. These failures report `MediaException` with phase `encoding` and category `configuration`, without publication. Graph types are not guessed during pure inspection.

`resource($name, Media)` supports `drawtext`'s `fontfile` and `textfile`, and `lut3d`'s `file` option. Every placeholder must be a whole, unquoted named option value, such as `file={{look}}`. The compiler substitutes a generated relative filename, and the process runs inside the workspace. Original keys can contain punctuation or Unicode without entering the graph. Unknown, unused, quoted, or embedded placeholders fail before staging. A logo or other media resource uses `addInput()` and a graph input label, not `movie` or `amovie`.

```php
$media->export()
    ->resource('font', FFMpeg::disk('assets')
        ->open('fonts/Brand.ttf'))
    ->textResource('title', "100%: café 'quoted' [literal],; %{not_expanded}")
    ->videoFilter('drawtext=fontfile={{font}}:textfile={{title}}:expansion=none:fontcolor=white:fontsize=24')
    ->video('libx264', crf: 23)
    ->toDisk('videos')
    ->save('title.mp4');

$media->export()
    ->subtitleResource('captions', FFMpeg::disk('assets')
        ->open('captions/English.srt'))
    ->videoFilter('subtitles=filename={{captions}}')
    ->clip(4, duration: 2) // A cue at source time 5 appears at output time 1.
    ->video('libx264', crf: 23)
    ->toDisk('videos')
    ->save('captioned.mp4');
```

`textResource()` writes UTF-8 bytes unchanged. It requires `drawtext`'s `textfile` option with `expansion=none` in that same filter instance. Reloading is disabled. Text never enters the graph or command arguments. Resource-bearing drawtext and subtitles filters require named, nonduplicate options. A second filename, positional filename, or `subtitles`'s `f` alias fails before execution.

`subtitleResource()` accepts UTF-8 standalone SRT, ASS, and WebVTT files without media input options. FFprobe validates the final private filename with `-protocol_whitelist file -format_whitelist srt,ass,webvtt`, requiring one subtitle stream. FFmpeg also decodes that unchanged file under the whitelist before the subtitles filter reopens it. Content disguised as a subtitle, including HLS and concat manifests, fails with `MediaException`, phase `probing`, category `resource`, before encoding or publication. Missing `drawtext` or `subtitles` support for typed resources reports category `capability`. These checks do not isolate developer-trusted filters or libass font discovery.

Subtitle resources reject keyframe clips because input seeks reset the timestamps used for subtitle rendering. Accurate output clips preserve cue timing. Subtitle placeholders work only in `subtitles=filename={{name}}`, not generic file resources, `ass`, `movie`, or `amovie`.

Accurate clips stay output-scoped and do not seek sibling inputs. Keyframe clips remain limited to one input and one output without a complex graph. Graph duration can change arbitrarily, so graph progress reports time and speed without inventing a percentage. Composition validates all files before publication, but publication across disks is not atomic.

## Acceptance scenarios

Every row is required for 9.0. Existing tests are executable evidence of the old workflow, not proof that the new implementation preserves it. A row remains open until its Laravel-native acceptance scenarios pass. PHPUnit discovery can be inspected with `vendor/bin/phpunit --list-tests`.

| Contract | Required acceptance scenario | Existing evidence or new test | Native status |
| --- | --- | --- | --- |
| Disk inputs and probing | Probe a nested key, supplied filesystem, local file, and upload. Retain source bytes and disk context. | `NativeProbeTest` | Implemented |
| Private staging | Transfer a non-local input once per execution. Check permissions, truncation, failed reads, and cleanup. Reject unsupported manifest dependencies. | `NativeProbeTest`, `NativeExportTest`, `NativeCompositionTest` | Shared across inputs and probe selectors within an execution |
| Metadata | Preserve chapters, tags, rational rates, null duration, color fields, all streams, and separate cover art from motion video. | `NativeProbeTest` | Implemented for raw data and basic accessors |
| Process capture | Drain a noisy child, cap metadata output, remove application secrets, terminate on total and idle deadlines, and reap before cleanup. | `NativeProbeTest`, `NativeExportTest` | Shared process boundary; real callback cancellation verified |
| Network inputs | Read explicit public URLs. Stage authenticated finite downloads through Http. Keep per-input headers separate and optionally redact signed URLs. | `NativeUrlTest` | HTTP direct probe/export and authenticated downloads verified with real network fixtures; redirect, truncation, TLS rejection, capability, redaction, and cleanup checks covered |
| Scoped and read-only filesystems | Preserve prefixes and wrappers. Never write through a read-only adapter or escape a destination root. | `NativePreservationTest` | Real prefixed and read-only adapters verify input retention, scoped publication, rejected writes, and traversal |
| Ordinary encoding | Encode audio and video to different disks. Verify codecs, duration, channels, geometry, and write options. | `NativeExportTest`, `NativeCompositionTest`, `NativeLoudnessTest` | Real audio/video encodes and cross-disk publication verify stream properties and write options |
| Display geometry | Resize, contain, cover, crop, rotate, and pad while preserving declared order. | `NativeExportTest`, `NativePreservationTest` | Anamorphic resizing, square pixels, independent outputs, and decoded crop/rotate/pad pixels verified |
| Independent operations | Start two exports from the same media descriptor without inheriting settings or temporary files. | `NativeExportTest` | Single-output builders and repeated real exports verified |
| Stream selection | Use multilingual audio, forced subtitles, cover art, and chapters. Verify ordered, optional, and negative maps and resulting dispositions. | `NativeExportTest`, `NativePreservationTest` | Language, optional and negative maps, ordered dispositions, forced subtitles, artwork, and chapters verified |
| Metadata preservation | Copy global tags. Preserve chapters only for an unchanged timeline unless explicitly transformed. Test remove and override controls. | `NativeExportTest`, `NativePreservationTest` | Copy, override, explicit removal, and clip chapter omission verified |
| Quality and passes | Compare CRF and target-bitrate contracts. Reject conflicts. Prove explicit two-pass encoding with isolated pass logs. | `NativeExportTest`, `NativeHlsPackagingTest` | CRF conflicts, independent libx264 logs, nested exports, and two-pass HLS verified |
| Copy and clipping | Remux without changing codecs. Distinguish accurate transcoding from keyframe copy cuts. Preserve audio offsets and VFR. | `NativeExportTest`, `NativePreservationTest` | Remux, accurate duration, decoded keyframe identity, unequal VFR gaps, and delayed audio timestamps verified |
| Filters and resources | Apply ordered audio and video filters with punctuation, Unicode, fonts, and staged subtitles. Reject filters on copied streams. | `NativeExportTest`, `NativeCompositionTest` | Simple filters, LUTs, fonts, literal text, and SRT/ASS/WebVTT rendering verified, including clipped cue timing and manifest rejection |
| Multiple inputs and outputs | Compose mixed-disk inputs and map separate audio and video to different output disks. Verify stream identity and common-filter branching. | `NativeCompositionTest` | Mixed-disk overlay, LUT, branching, independent clips, copied audio, validation, and partial publication verified |
| Concatenation | Copy compatible inputs and report mismatches. Normalize heterogeneous inputs only under an explicit geometry, timing, and audio policy. | `NativeConcatTest` | Compatible video/audio copy, mixed disks, decoded order, layout rejection, source retention, and audio-delay packet timestamps verified; explicit video-only and audio-inclusive normalization graphs verified, including delayed audio across different sample rates and channel layouts |
| Frames | Seek to a selected timestamp. Return image bytes and save an image with verified dimensions and content. Fail when no frame exists. | `NativeExportTest` | Single images and interval/count output sequences verified with real media; image-quality controls remain available through output options |
| Image sequences and timelapse | Read a numbered image sequence at a declared input rate and verify output timing. | `NativeSequenceTest` | Local PNG and staged JPEG inputs verified with decoded order, explicit count, decimal and rational rates, non-default starts, exact output duration, source retention, and cleanup; missing, mismatched, disguised, and corrupt images reject publication |
| Storyboards and VTT | Verify tile order, dimensions, quality, margin, padding, and cue coordinates. Use different VTT and image directories. | `NativeStoryboardTest` | Real PNG and JPEG sheets verify decoded tile order, asymmetric grids, spacing, partial sheets, quality, clipped timing, relative and custom URLs, cue coordinates, validation before publication, partial upload failure, source retention, and cleanup |
| Waveform images | Generate and inspect an actual waveform image from audio. | `NativeExportTest` | Real amplitude, muted audio, clipped timing, colors, odd image dimensions, and post-render resize verified |
| Watermarks | Apply local and staged remote images, offsets, scaling, and optional image preprocessing to ordinary and HLS output. | `NativeCompositionTest`, `NativeUrlTest`, `NativeHlsTest` | Transparency, scaled placement, repeated images, copied audio, private preprocessing, URL staging, and clipped HLS watermark pixels verified |
| Analysis | Inspect volume-analysis output and nonzero status without requiring a media artifact. | `VolumeDetectTest` | Real two-track PCM verifies mean and peak volume, track selection, filters, silence, local and staged inputs, inspectable nonzero exits, opt-in exceptions, redaction, timeout reaping, source retention, and cleanup without publication |
| Progress and events | Parse fragmented protocol records. Handle unknown duration and multiple passes. Stop on callback failure. Complete only after validation and publication. | `NativeExportTest`, `NativeLoudnessTest` | Single-pass lifecycle and real child reaping verified; encoding passes, analysis, failure cleanup, and cancellation covered |
| Results and publication | Validate artifacts before writes. Stream files, report partial publication, and clean only owned temporary files. | `NativeExportTest`, `NativeCompositionTest`, `NativeHlsTest` | Single-file outputs and owned HLS inventories verify publication order, false returns, partial failures, source protection, and cleanup |
| Adaptive HLS | Produce multiple MPEG-TS variants, first/all audio, copy, custom paths, and hooks. | `NativeHlsTest` | Complete decoding, keyframes, alignment, artwork exclusion, silent video, clipped watermarks, custom paths, and hooks verified |
| Encrypted HLS | Decrypt AES-128 segments and verify IVs, fixed/rotating keys, private storage, and cleanup. | `NativeHlsTest` | Byte-identical decryption, complete decoding, rotation, random IVs, padding, private publication, hook protection, repeated saves, and cleanup verified |
| Dynamic HLS | Rewrite playlists, media, init, alternate groups, and keys relative to containing playlists. | `NativePlaylistTest`, `NativeHlsTest`, `NativeHlsPackagingTest` | Laravel responses, signed URLs, encrypted playback, group traversal, fMP4 init rewriting, limits, and sanitized errors verified |
| fMP4 and alternate groups | Own init files and reusable audio/subtitle playlists with correct group metadata and timing. | `NativeHlsPackagingTest` | MPEG-TS/fMP4 decoding, separate audio frequencies, subtitle cues, relative paths, and timestamp maps verified |
| Loudness normalization | Measure and normalize identical processed samples, reporting requested/actual mode and silence. | `NativeLoudnessTest` | Independent output measurements, track/trim/channel processing, linear/dynamic modes, immutable sample snapshot, and cleanup verified |
| Waveform peaks | Reduce signed little-endian PCM into bounded versioned channel extrema. | `NativeWaveformPeaksTest` | Asymmetric stereo, mono, partial/chunk boundaries, track/rate selection, filters, clipping, empty input, and publication verified |
| Extension points | Inspect scoped arguments. Preserve filters, hooks, and output configuration without driver forwarding. | `NativeExportTest`, `NativeCompositionTest`, `NativeHlsTest` | Pure inspection, scoped options, graphs, output callbacks, and additive playlist hooks implemented; arbitrary playlist replacement is not supported |

## Compiler contracts

- An input's options precede its `-i`. Output maps, codecs, filters, metadata, and container options stay with that output. Only explicitly repeatable options may repeat.
- Without explicit maps, ordinary single-input exports select the first motion-video and audio streams when present. Audio-only and silent video are valid. Explicit maps replace these defaults and preserve declaration order.
- `map('0:a:0?')` can omit a missing stream but cannot conceal an invalid input index. Negative maps remove previously selected streams. Per-stream output options address the resulting output order.
- `video('libx264', crf: 23, preset: 'medium')` and `video('libx264', bitrate: '2500k')` are alternative high-level rate-control choices. Supplying both fails before launch. A normal encode has one pass.
- `clip(start: 12.5, duration: 8, seek: 'accurate')` selects the presentation interval beginning at 12.5 seconds and ending before 20.5 seconds. Copying an affected stream requires explicit keyframe mode. It never silently transcodes.
- Timeline changes omit chapters unless an explicit transform or mapping makes them valid. Multi-input composition requires an explicit metadata source.
- Fonts, logos, LUTs, subtitle files, and literal-text files belong to the execution workspace. Literal text, paths, and FFmpeg expressions require different escaping. A graph compiles to one argument value, not a shell fragment.
- Compilation and inspection allocate no workspace, download nothing, and do not mutate a pending builder. Scoped options cannot replace reserved filenames, binary selection, progress transport, or pass-log paths.
- Native encoding stops on FFmpeg errors with `-xerror`. A decodable remainder of a damaged input is not a successful artifact.

`NativeExportTest` covers the single-output subset. `NativeCompositionTest` adds pure inspection, process fakes, and real-media acceptance for graphs, resources, independent outputs, validation-before-publication, and partial failure. A resource and the same key used as a media input have separate staging entries so resource staging cannot bypass manifest checks.

## Delivery and limits

The native implementation replaces the legacy backend and dependency tree. [README.md](README.md) contains code-first examples, and [UPGRADING.md](UPGRADING.md) covers the breaking migration. Additional contracts include bounded frame/packet queries, capability diagnostics, operation deadlines, per-track audio encoding, finite clear DASH with shared HLS segments, and single-rendition continuous HLS. Their limits are documented in the README. [Laravel application integrations](docs/integrations.md) covers profiles and durable application-owned inventories without adding package-owned media models.

Release verification uses randomized suites with deprecations treated as failures on PHP 8.4 and 8.5, Laravel 12 and 13, and matching FFmpeg/FFprobe 9.0.2. Hosted CI must verify Ubuntu 26.04 execution after pushing. No performance improvement, real-cloud integration, low-latency HLS, DRM, GPU usability, or cross-worker live-session recovery is claimed.

## Test commands

Real-media cases require `FFMPEG_9_FFPROBE` to identify an installed FFprobe 9.0.x binary and report skips when that variable is absent. Acceptance uses a matching `ffmpeg` binary in the same directory, built with libx264, libx265, libxml2, FreeType, HarfBuzz, and libass. Font acceptance requires `/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf`. Setup and CI install these prerequisites.

```bash
FFMPEG_9_FFPROBE=/opt/ffmpeg-9.0.2/bin/ffprobe vendor/bin/phpunit --order-by random \
    --display-deprecations --display-phpunit-deprecations \
    --fail-on-deprecation --fail-on-phpunit-deprecation
```

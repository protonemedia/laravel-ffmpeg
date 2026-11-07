# 9.x implementation status and acceptance contract

This file tracks the Laravel-native rewrite. The [design proposal](https://ampcode.com/artifacts/9-x-rewrite-feature-contract-and-laravel-native-api-640fbf41-d6df-45af-b75a-249a8018ba15) defines the target API and priorities.

## Current implementation

`FFMpeg::disk($disk)->open($key)` returns a media descriptor with native `probe()` and `export()` operations through Laravel Process. A disk can be a name or an `Illuminate\Contracts\Filesystem\Filesystem` instance. `open()` also accepts `Illuminate\Http\File` and `UploadedFile`. Creating a descriptor or inspecting an export performs no transfers or process launches.

`FFMpeg::disk()->open($key)` and `FFMpeg::disk(null)->open($key)` select `filesystems.default` when `disk()` is called. Existing selectors and media descriptors keep their selected disk when the configuration changes. The legacy `FFMpeg::open()` entry point remains unchanged.

Local disks and file objects use their local paths. Other filesystem instances stream into a private workspace with transfer-size checks. Known remote manifests are rejected because staging a playlist alone loses its dependencies. This detection is not a sandbox for hostile media.

Probing requires FFprobe 9.0.x. It reads format, streams, and chapters together. `Metadata` and `Stream` retain raw fields through `get()` and `toArray()`. Duration is nullable, frame rates retain their rational source values, and the default video accessor excludes attached pictures. Width and height are encoded dimensions, not rotation-adjusted display dimensions.

The process boundary clears inherited application environment variables, limits JSON capture to 16 MiB, and retains only the last 64 KiB of stderr. Internal callbacks drain both streams without Symfony retaining a second copy. `laravel-ffmpeg.timeout` sets the total deadline. Optional `laravel-ffmpeg.idle_timeout` sets the silence deadline. Both use seconds. Failures expose a `MediaException` with `phase`, `category`, and bounded diagnostics, without a raw credential-bearing exception chain. Workspaces are removed after the child stops, including on failure.

Exports support codecs, explicit-unit bitrates, CRF and preset settings, ordered stream maps, audio and video filter chains, metadata and chapter controls, and accurate or keyframe clipping. An explicit map replaces default selection. Selector-specific FFprobe queries validate maps against the staged asset without interpreting FFmpeg's selector grammar in PHP. Empty explicit selection fails rather than allowing FFmpeg to select tracks automatically.

`addInput(Media)` returns a stable numeric input index. Repeated descriptors share staging and probe results within an execution, but retain separate `-i` entries. `filterComplex()` compiles one graph argument. `output($path, $callback)` configures independent destinations and encoding settings. Every output in a multi-input or graph operation requires explicit maps. Multiple inputs also require explicit metadata and chapter sources, or explicit omission. Output callbacks run immediately. Settings freeze when execution starts.

All outputs, including local destinations, are generated in the workspace. Every expected file passes validation before any publication begins. Composition outputs are probed for stream counts and for the types of directly mapped streams. Graph-pad types remain FFmpeg's responsibility. This catches extra unlabeled graph outputs without parsing filter pad counts in PHP. Publication preserves Storage write options but is not atomic. A failed upload can leave a partial object or an already-published sibling. Failures identify the destination and all objects whose writes returned success. Already-published objects are not deleted automatically.

`save()` returns generated-file records and bounded, redacted process summaries. Structured progress separates staging, probing, encoding, validation, and publication. Percentage, speed, and remaining time can be unknown. A callback can cancel by throwing `Exceptions\ExportCancelled`; other callback exceptions propagate unchanged as failures. Both stop and reap the child before workspace cleanup. Lifecycle events work without a progress callback, and completion follows publication and cleanup.

The old API and dependency remain temporarily while their replacements are built. The new `disk()` entry point does not invoke the old driver or fall back to it. Additional geometry helpers and the other workflows below remain open. The completed release must remove the old implementation and dependency together.

### HTTP inputs

```php
$public = FFMpeg::disk()->openUrl('https://media.example/video.mp4');
$private = FFMpeg::disk()->openUrl(
	'https://media.example/private.mp4',
	headers: ['Authorization' => 'Bearer '.$token],
	download: true,
);
$private->export()->video('copy')->audio('copy')->toDisk('videos')->save('private.mp4');
```

`InputSelector::openUrl()` accepts HTTP and HTTPS URLs. Direct input is the default for public or signed URLs. Choosing direct input permits the URL in FFmpeg and FFprobe process arguments, visible to operating-system process inspection. Package inspection, process summaries, diagnostics, and probe filenames redact the URL. Query values also receive diagnostic redaction. A direct read has a 15-second network read timeout, plus the configured process deadline.

Per-input headers require `download: true`. URL user information, fragments, invalid header names, and multiline header values are rejected. Downloads use Laravel Http with a 10-second connection deadline, a 60-second request deadline, TLS verification, and a private file sink. Redirects and transparent decompression are disabled. Only a complete, nonempty HTTP 200 response is accepted. A declared `Content-Length` must match the downloaded file. Headers and downloaded URLs never enter media process arguments. Laravel Http middleware and events still receive the original request, so application HTTP instrumentation must redact credentials.

URL inputs default to `filesystems.default` for publication, regardless of the selector's disk. Repeated inputs share one download within an execution. Separate headers produce distinct sources. URL resources such as fonts download through Http even when declared without `download: true`. Known manifest extensions and downloaded manifest contents are rejected as standalone media. This check is not a sandbox for hostile inputs or developer-supplied filters.

Protocol capabilities are checked only when a direct URL executes. Local inputs and Http downloads do not require network protocols in FFmpeg. HTTPS direct input verifies the certificate and excludes plaintext HTTP from its protocol whitelist. The native build includes GnuTLS. Other protocols and live-input policies remain outside this HTTP implementation.

### Native acceptance toolchain

`.agents/setup` installs matching FFmpeg and FFprobe 9.0.2 binaries at `/opt/ffmpeg-9.0.2/bin`. The shared `.agents/install-ffmpeg` script verifies the source archive's SHA-256 before building with libx264, FreeType, HarfBuzz, libass, zlib for PNG support, and GnuTLS for HTTPS. An existing matching pair with PNG encoding and HTTPS protocols is reused. Resume checks for the binaries without installing anything.

```bash
FFMPEG_9_FFPROBE=/opt/ffmpeg-9.0.2/bin/ffprobe vendor/bin/phpunit --order-by random
```

CI uses the same installer and runs native real-media acceptance throughout the PHP 8.4/8.5 and Laravel 12/13 matrix. The compiled pair is cached by operating system, architecture, and installer contents. A cold installation compiles from source; warm setup and cache hits reuse the binaries. Distribution FFmpeg remains separate for legacy tests until the old backend is removed.

### Single-frame extraction

```php
$media = FFMpeg::disk('videos')->open('converted.mp4');
$result = $media->frame(at: '00:00:12.500')->resize(width: 320)
	->toDisk('thumbnails')->save('covers/preview.png');
$bytes = $media->frame(at: 12.5)->get(); // Raw JPEG bytes, not Base64.
$first = $media->frame()->get();         // Timestamp zero, with fresh settings.
```

`frame()` selects the first non-attached-picture video stream and decodes the first frame at or after the timestamp. Timestamps accept nonnegative numeric seconds or the same strict timecodes as `clip()`. There is no artificial sampling window, so sparse variable-frame-rate media can return a frame several seconds later. A timestamp beyond the final frame throws a `MediaException` instead of publishing an empty file.

`save()` accepts `.jpg`, `.jpeg`, or `.png` destinations and returns an `ExportResult` with an image-role file. Generated images must have positive dimensions and the requested image format before publication. Destination defaults, Storage write options, filters, callbacks, cancellation, and workspace cleanup use the export pipeline. A `%` in a destination remains literal; single-frame output is not an image sequence.

`get()` returns JPEG bytes after validation and workspace cleanup. It performs no destination Storage write or publication notification. Lifecycle events contain empty destination and file lists for this in-memory result. Single-image extraction does not infer a percentage from the video's full duration.

### Image sequences

```php
$media->frames(every: 2)->resize(width: 320)->save('frames/%05d.jpg');
$result = $media->frames(count: 10)->save('previews/%03d.png');
$result->files(); // Ordered files with concrete numbered paths and the image role.
```

`frames()` requires exactly one of `every` or `count`. Intervals are finite seconds, at least one microsecond. Counts are positive integers. Both resample decoded video at a constant rate through FFmpeg's `fps` filter with nearest timestamp rounding. This can duplicate source frames. It is not a sequence of independent `frame(at:)` seeks.

Count-based extraction requires a known, positive duration and produces exactly the requested number of images. The sampling rate is the count divided by the source duration, or the intersecting clip duration when clipped. Short sources can repeat frames, with final-frame padding if needed. Missing or nonpositive duration fails during probing rather than inventing a sampling interval.

Destinations require one `%d` or `%01d` through `%09d` placeholder in the filename, with no other percent signs. Numbering starts at one. Encoding uses a private workspace pattern, never the caller's destination as an FFmpeg path. All numbered files must be contiguous and valid JPEG or PNG images before any Storage write starts. Publication is not atomic. An upload failure reports concrete published paths and the failed destination, without deleting successful siblings.

### Storyboards and WebVTT cues

```php
$result = $media->storyboard(every: 2, columns: 5, rows: 4, margin: 3, padding: 2)
	->resize(width: 160)->clip(10, duration: 45)
	->vtt('metadata/preview.vtt')->toDisk('previews')
	->save('images/sheet-%03d.jpg');
```

`storyboard()` samples decoded video at the declared interval and tiles thumbnails in row-major order. Intervals are positive whole milliseconds expressed in seconds. The default grid is five columns by five rows. Margin and padding are nonnegative pixel counts. `resize()` and `fit()` configure each thumbnail before tiling. JPEG quality remains an output option, such as `outputOptions(['-q:v', '2'])`.

The operation requires one input with a known, positive duration. Complex graphs and keyframe seeks are rejected. Accurate clips bound thumbnails before tiling, and cue times start at zero within the clip. Sampling uses the `fps` filter with nearest timestamp rounding and can repeat source frames. The final sheet contains black unused cells. Those cells have no cues.

`save()` requires a numbered JPEG or PNG destination, with the same placeholder and numbering rules as `frames()`. `vtt()` optionally writes WebVTT cues on the output disk. Without a resolver, image URLs are percent-encoded paths relative to the VTT file's directory. A custom resolver receives each concrete sheet key and its one-based index:

```php
$media->storyboard(every: 2)->vtt('preview.vtt', fn (string $key, int $index) => Storage::disk('previews')->url($key))
	->toDisk('previews')->save('images/sheet-%03d.png');
```

Resolved URLs must be nonempty strings without whitespace, control characters, or fragments. Query strings are retained before the generated `#xywh=x,y,width,height` fragment. Coordinates use the actual sheet dimensions, margin, and padding. Each cue ends at the next sample or the clip's end, rounded up to a millisecond.

Every sheet and the cue file are validated before publication. The result lists image-role files first and the optional `vtt`-role file last. Publication is not atomic. If the cue upload fails, the exception identifies the sheets already published and the failed cue destination. Source bytes and successful uploads are retained, and private workspace files are removed.

### Waveform images

```php
$media->waveform(width: 640, height: 120, colors: ['#0000ff'])
	->toDisk('previews')->save('audio/waveform.png');
$media->waveform(width: 640, height: 120, colors: ['red'])
	->audioFilter('volume=0.5')->clip(10, duration: 5)
	->resize(width: 320)->save('audio/excerpt.jpg');
```

`waveform()` renders the first audio stream through FFmpeg's `showwavespic` filter. Defaults are 640 × 120 pixels with blue channels on one canvas. Dimensions are positive integers and may be odd. Colors are a nonempty list of FFmpeg color names, six-digit RGB strings, or eight-digit RGBA strings. Hexadecimal colors require a `#` or `0x` prefix. Filter syntax is not accepted as a color.

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

The result exposes `exitCode`, `output`, `diagnostics`, `elapsedSeconds`, `command`, and `phase` properties. `output()` returns stdout, and `errorOutput()` returns the diagnostic tail. `summary()` returns status, timing, the redacted command, and diagnostics. Captured stdout is limited to 16 MiB, and diagnostics retain the final 64 KiB. Analysis redacts known input paths, signed URLs, and query values in both streams and the returned command. Direct URL inputs still expose their URL to operating-system process inspection. Structured volume parsing and loudness normalization are not part of this operation.

### Adaptive HLS

```php
use ProtoneMedia\LaravelFFMpeg\HlsVariant;

$result = $media->hls(segmentDuration: 6)
	->video('libx264')->audio('aac', bitrate: '96k')
	->variant('360p', fn (HlsVariant $variant) => $variant->resize(height: 360)->video(bitrate: '800k'))
	->variant('720p', fn (HlsVariant $variant) => $variant->resize(height: 720)->video(bitrate: '2800k'))
	->toDisk('streams', ['visibility' => 'private'])->save('lesson/master.m3u8');

$result->playlist();                   // The master GeneratedFile.
$result->variant('720p')->playlist();  // lesson/720p/index.m3u8.
$result->variant('720p')->files();     // This variant's segments and playlist.
```

`hls()` returns a fresh `PendingHlsExport` for MPEG-TS VOD. Its default codecs are `libx264` and `aac`. At least one named variant is required. Names contain ASCII letters, digits, underscores, or hyphens and start with a letter or digit. Names must be unique. Variant callbacks run immediately and configure encoding, maps, and filters, without `save()` or `toDisk()` methods.

Shared defaults resolve at compilation, including settings declared after a variant. Variants override individual codec settings, metadata tags, maps, and matching output-option flags. Variant filters append after shared filters. Explicitly repeatable stream IDs remain ordered. Rate-control conflicts still fail rather than silently dropping a bitrate or CRF. Shared Storage options apply to every generated file. Variants freeze when execution starts.

All variants run in one FFmpeg invocation against the same staged inputs. Explicit graphs can share processing through `split` or `asplit`. Different variants still encode separately. When a variant maps a graph-produced stream, its geometry and other video filters belong inside that graph, not in a separate `resize()` or `videoFilter()` chain. Multi-input operations retain the explicit map, metadata, and chapter-source requirements of ordinary composition. Accurate clips and watermark graphs use the shared execution pipeline. No performance improvement has been measured.

Without explicit maps, each variant selects the first motion-video and audio streams when present. Cover artwork does not become video in an audio-only export. Silent video, audio-only media, and `video('copy')->audio('copy')` are supported. Explicit maps replace defaults. For example, `map('0:V:0')->map('0:a')` retains every audio stream with the first motion-video stream.

Segment duration ranges from 0.001 to 86400 seconds. Encoded video requests closed GOPs and keyframes at segment-duration intervals. Actual boundaries depend on available frame timestamps and the encoder. Copy mode retains source keyframes and cannot promise exact segment lengths. Neither mode advertises independent segments. Finalization sets a positive integer target duration that covers the longest segment. Master bandwidth includes MPEG-TS overhead: `BANDWIDTH` conservatively uses the highest individual segment bitrate, and `AVERAGE-BANDWIDTH` uses total bytes divided by total duration. FFmpeg-supplied codec and resolution attributes are retained when available.

The default layout places each variant's `index.m3u8` and `segment-00000000.ts` files in a subdirectory beside the master. Playlist references are relative to their containing playlist. Encoder filenames stay private. Finalization requires complete VOD playlists, contiguous referenced segments, nonempty files, and no unreferenced matching segments. External, traversal, duplicate, missing, and encrypted references fail before any Storage write. Playlist reads are limited to 16 MiB each. Validation also probes each variant's first segment for the expected stream count and directly mapped stream types, catching extra unlabeled graph outputs. It does not decode every encoded frame or probe every segment.

The result lists all segments first, then variant playlists, then the master. Their roles are `hls-segment`, `hls-playlist`, and `hls-master`. Publication follows that order. A failed upload identifies the concrete published siblings and failed destination without deleting successful uploads. Saving replaces the requested definitions, does not append variants or remove old files, and is not transactional. Existing segments may already be replaced when a later upload fails.

Custom playlist and segment paths, playlist hooks, AES-128 encryption and rotation, dynamic responses, two-pass HLS, and fMP4 packaging remain separate increments. The legacy HLS implementation remains until native acceptance covers its required workflows.

### Progress helpers

```php
use ProtoneMedia\LaravelFFMpeg\Progress;

$media->export()->onProgress(function (Progress $progress) {
	if ($progress->isEncoding()) {
		// elapsedSeconds, percentage, speed, and remainingSeconds may be null.
	}

	if ($progress->phase === Progress::PUBLICATION) {
		// Outputs are validated; publication is not complete yet.
	}
});
```

`Progress` defines typed string constants `STAGING`, `PROBING`, `ENCODING`, `VALIDATION`, and `PUBLICATION`. Their values remain `staging`, `probing`, `encoding`, `validation`, and `publication`. The `phase` property remains a string, so existing callback comparisons continue to work. `isEncoding()` is true for the encoding-start notification and encoder updates, including updates without a known percentage. Other progress phases return false. Callback timing, cancellation, and lifecycle events are unchanged; these helpers add no progress events or completion phase.

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
$url = $file->temporaryUrl(now()->addMinutes(5));
$stream = $file->readStream(); // The caller closes the stream with fclose().
```

`readStream()` returns a readable resource or `null`, following the destination's configured Storage failure behavior. Streams are not cached or serialized.

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
$metadata->subtitleStreams()->first(fn ($stream) => $stream->isForced());
$metadata->coverArt()->first();
$metadata->chapters()->first();
$metadata->tag('title');
```

`Stream` exposes `index()`, `type()`, `codec()`, `language()`, `tag($key)`, `isDefault()`, `isForced()`, `isCoverArt()`, `channels()`, `sampleRate()`, and `bitrate()`. Missing scalar values return `null`. Channels, sample rate, and bitrate require positive integers and return `null` for unavailable, fractional, or overflowing values. Missing disposition flags return `false`. `get()` and `toArray()` retain the original values without conversion.

Stream and format `tag()` lookups use literal, case-sensitive keys, including keys containing dots. Missing tags return `null`. `subtitle()` returns the first subtitle stream or `null`. `coverArt()` returns a collection of attached-picture video streams, separate from `videoStreams()` and `video()`. An audio file with cover art does not satisfy `hasVideo()`. `chapters()` returns a collection of unchanged chapter arrays, or an empty collection when chapters are absent. All stream collections retain source identity for the mapping helpers below.

### Current export API

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
$media->export()->clip('00:00:13.375', duration: '00:00:02.125');
$media->export()->clip('01:02:59.750', end: '01:03:01.125');
$media->export()->clip(13.375, duration: '00:00:02.125');
```

Input options, `globalOptions()`, and `outputOptions()` accept flat option/value pairs from a closed allowlist. Every allowed option has library-defined arity and value validation. Unknown options, aliases, switches with caller-supplied values, duplicate nonrepeatable options, filename controls, authentication headers, and encoder parameter blobs are rejected. Output `-f` permits only listed single-file muxers. These methods are not unrestricted argv passthrough.

Filters are developer-trusted FFmpeg expressions, not end-user text. They can read local resources or load plugins. Local and downloaded inputs use `-protocol_whitelist file`. Direct HTTP inputs use a transport-specific protocol whitelist. Neither policy isolates local file access or applies to files opened by filters. Untrusted media still requires process isolation and resource limits. A demuxer allowlist remains under investigation and is not implemented in this checkpoint.

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
$media = FFMpeg::disk('uploads')->open('original.mov');

$media->export()
	->mapVideo(0)
	->mapAudio(0, language: 'eng', optional: true)
	->video('copy')->audio('copy')
	->toDisk('videos')->save('english.mkv');

$metadata = $media->probe();
$video = $metadata->videoStreams()->first();
$english = $metadata->audioStreams()
	->first(fn ($stream) => $stream->language() === 'eng');

$media->export()->mapVideo($video)->mapAudio($english)
	->video('copy')->audio('copy')->toDisk('videos')->save('selected.mkv');
```

The object example assumes both streams exist. `mapVideo()` and `mapAudio()` accept a numeric input index or a probed `Stream`. The default selects input 0's first stream of that type. `track:` selects a zero-based track within that type. `language:` selects all matching audio tracks and cannot accompany `track:`. `optional: true` omits missing matches, but an entirely empty selection still fails. Raw `map()` remains available for other selectors, negative maps, and graph labels. Optional maps compile with a separator before `?` so FFmpeg 9 does not treat it as part of a metadata value.

A probed stream maps its absolute `index()` and retains its source identity. Its source must be an export input. When the same source occurs more than once, `mapVideo($video, input: 1)` chooses its input position explicitly. A hint referring to different media fails. Object maps cannot accompany `track:` or `language:` and never probe again during inspection. A later `save()` stages and validates the current input as usual. These helpers also work in `output()` callbacks.

### Geometry shortcuts

```php
$media->export()->resize(width: 1280);          // Preserve display aspect ratio.
$media->export()->resize(height: 720);          // Infer an even width.
$media->export()->resize(1280, 720);            // Stretch to both dimensions.
$media->export()->fit(1280, 720);               // Contain with centered black padding.
$media->export()->fit(1280, 720, mode: 'cover'); // Fill with a centered crop.
```

These settings work inside `output()` callbacks too. They append to the video filter chain in call order and cannot accompany video stream copy. Graph-fed streams follow the same simple-versus-complex filter restrictions as `videoFilter()`.

Both helpers produce square pixels and allow upscaling. A one-dimension resize uses display aspect ratio, including the input's sample aspect ratio, and rounds the inferred dimension to a multiple of two. Two explicit resize dimensions stretch the image. `fit()` preserves display aspect ratio within rounding, requires positive even dimensions, and returns that exact canvas size. `contain` preserves the image with black bars. `cover` removes the edges outside a centered crop. Rotation and standalone crop and pad helpers remain open.

### Text and subtitle shortcuts

```php
$font = FFMpeg::disk('assets')->open('fonts/Brand.ttf');
$captions = FFMpeg::disk('assets')->open('captions/English.srt');

$media->export()
	->resize(width: 1280)
	->drawText("100%: café 'quoted' %{literal}", font: $font, size: 32, x: 24, y: 24)
	->burnSubtitles($captions)
	->clip(4, duration: 2)
	->video('libx264', crf: 23)->toDisk('videos')->save('captioned.mp4');
```

`drawText()` stages literal UTF-8 text in a private file with expansion and reload disabled. Font descriptors can use any supported disk. Size must be positive, coordinates are integers, and color accepts a name or `0xRRGGBB` with optional alpha bytes. `burnSubtitles()` uses the subtitle validation described below. Both helpers work in `output()` callbacks and share staged assets across outputs without named placeholders.

Filters run in call order. Burning subtitles before resizing scales the rendered text with the image. Burning them after resizing renders at the final resolution. Any subtitle helper rejects input/keyframe seeking, even when it belongs to another output. Settings freeze at execution, and resource failures identify the output index and helper. Font files must be readable regular files; FFmpeg validates the font contents when rendering.

### Concatenation

```php
$first = FFMpeg::disk('uploads')->open('first.mp4');
$second = FFMpeg::disk('archive')->open('second.mp4');
$joined = $first->concat($second);
$joined->export()->video('copy')->audio('copy')->toDisk('videos')->save('joined.mp4');
```

`concat()` returns a media descriptor and does no IO until probing or export. It requires at least one following input. Compatible finite files join through the concat demuxer. Local files and non-local Storage inputs can be mixed. URL inputs require `download: true`. Input-specific media options, nested concat inputs, manifest sources, unknown durations, and nonzero reported presentation origins are rejected. Nonzero origins require an explicit normalization graph rather than a guessed timestamp correction.

Stream order, codecs, profiles, time bases, geometry, pixel formats, sample rates, channel layouts, and other observable codec parameters must match. This comparison cannot prove bitstream compatibility. FFmpeg remains authoritative, and failures never trigger automatic transcoding. Codec choices remain explicit export settings. The ordinary first-video and first-audio selection defaults still apply unless maps replace them.

The operation writes its own relative-name manifest with declared durations. Safe generated aliases isolate FFconcat filename syntax from caller keys, including quotes, Unicode, and newlines in local filenames. Aliases use private symlinks where supported, otherwise private copies. Cleanup does not modify or delete source files. Original chapter timelines are not combined. Results and publication use the ordinary export pipeline.

Heterogeneous inputs use the existing composition API with an explicit normalization policy. This video-only example stretches both inputs to the requested geometry, sets square pixels and a common frame rate, resets video timestamps, and deliberately excludes audio:

```php
$export = $first->export();
$export->addInput($second);
$export->filterComplex(
	'[0:v]scale=128:96,setsar=1,fps=10,setpts=PTS-STARTPTS[first];'
	.'[1:v]scale=128:96,setsar=1,fps=10,setpts=PTS-STARTPTS[second];'
	.'[first][second]concat=n=2:v=1:a=0[joined]'
)->map('[joined]')->video('libx264', crf: 20)->withoutMetadata(chapters: true)
	->toDisk('videos')->save('normalized.mp4');
```

Audio-inclusive graphs must declare their audio policy and preserve each segment's audio delay relative to video. Resetting each stream independently to its first sample can erase that delay. The library does not infer silence insertion, audio layout conversion, or intermediate encodes. Graph timing leaves percentage unknown.

`NativeConcatTest` verifies a zero-origin policy with different dimensions, frame rates, sample rates, and channel layouts. It selects the first audio channel, resamples to 16000 Hz with `aresample=16000:async=1:first_pts=0`, and pads and trims each audio segment to its video duration before concatenation. Decoded PCM confirms silence during each original audio delay and the expected signal afterward. Inputs with other origins require a common timeline correction, not independent first-sample resets.

### Image-sequence inputs and timelapse

```php
FFMpeg::disk('photos')->openSequence('camera/frame-%04d.png', count: 9, framerate: 1)
	->export()->video('libx264', crf: 23)->outputOptions(['-pix_fmt', 'yuv420p'])
	->toDisk('videos')->save('timelapse.mp4');
```

`openSequence()` returns a media descriptor without reading Storage or launching a process. The pattern is a disk-relative PNG or JPEG key with one `%d` or `%0Nd` placeholder in its filename, where N is from 1 to 99. Globs, directory placeholders, and other image formats are not supported. `count` is positive, and `start` defaults to 1 but can be zero. Every key in that contiguous range must exist. Files outside the range are not read.

`framerate` is a positive decimal rate or a rational string such as `'30000/1001'`. It controls input timing, not an output frame-rate conversion. Nine images at one frame per second produce a nine-second video. Output codecs, geometry, filters, clipping, and publication use the ordinary export API. The default output disk remains the sequence's input disk.

Each execution stages non-local images and prepares safe private numbered aliases. Local sources use symlinks where supported, otherwise private copies. All image headers must match the declared format and share dimensions. Missing files, mismatched dimensions, and invalid headers fail before encoding. Native exports use `-xerror` so corrupt image data fails encoding instead of silently publishing a shortened sequence. Cleanup removes only owned files and aliases, never the originals.

Sequences can be graph inputs but are not standalone files for copy concatenation or filter resources. Encode a sequence to a regular media file before passing it to `concat()`.

### Watermarks and image preparation

Watermarks are ordinary graph inputs. FFmpeg controls placement, scaling, and alpha compositing. This example keeps a static image visible through the end of the video and copies the source audio:

```php
$export = FFMpeg::disk('videos')->open('source.mkv')->export();
$logo = $export->addInput(FFMpeg::disk('assets')->open('brand/logo.png'));
$export->filterComplex(
	'['.$logo.':v]scale=160:-1,format=rgba[logo];'
	.'[0:v][logo]overlay=x=main_w-overlay_w-20:y=main_h-overlay_h-15:'
	.'eof_action=repeat:repeatlast=1:shortest=0[video]'
)->map('[video]')->map('0:a:0?')->video('libx264', crf: 23)->audio('copy')
	->withoutMetadata(chapters: true)->toDisk('videos')->save('branded.mkv');
```

Local and non-local Storage images use the same API. A downloaded URL image uses `openUrl($url, headers: $headers, download: true)`. No filename is embedded in the filter graph.

`Media::prepareImage(callable $prepare)` optionally preprocesses an image before FFmpeg reads it. The callback receives the path of a private writable copy, not the original. It must write the prepared image to that same path. The package does not depend on an image library or forward unknown methods to one. For example, an application with Spatie Image installed can supply:

```php
$logo = FFMpeg::disk('assets')->open('brand/logo.png')
	->prepareImage(function (string $path) {
		\Spatie\Image\Image::load($path)->width(320)->save();
	});
// Pass $logo to addInput(), then compose it with an overlay graph.
```

Preparation returns an immutable media descriptor. Construction and inspection do no IO and do not call the callback. The callback runs during staging once per media identity and staging purpose in each execution. Reusing the descriptor in a later execution creates a new private copy and reruns preparation. Callback results do not persist across executions. Input options and multi-file sources are rejected.

URL images always download through Laravel Http before preparation, even when the source descriptor selects direct input. Callbacks and FFmpeg receive local filenames, never URLs or headers. Input and prepared output must have readable raster image headers. Callback errors and invalid prepared images fail with phase `staging` and category `resource`, without publication or an exception chain that could expose private paths or credentials. FFmpeg still validates image decoding. The callback is trusted application code, not a sandbox.

Ordinary exports are verified with transparent local and staged PNGs, scaled bottom-right offsets, persistent overlays, unchanged decoded audio, private-copy preprocessing, source retention, and repeated executions. Watermark integration with native HLS remains open.

### Composition and resources

```php
$export = FFMpeg::disk('uploads')->open('source.mov')->export();
$audio = $export->addInput(FFMpeg::disk('recordings')->open('narration.wav'));

$result = $export
	->resource('look', FFMpeg::disk('assets')->open('looks/grade.cube'))
	->filterComplex('[0:v]lut3d=file={{look}},split[full][small];[small]scale=640:360[preview]')
	->output('full.mp4', fn (ExportOutput $out) => $out
		->map('[full]')->map($audio.':a:0')
		->video('libx264', crf: 21)->audio('aac', bitrate: '128k')
		->metadataFrom(0)->chaptersFrom(null)->toDisk('videos'))
	->output('preview.mp4', fn (ExportOutput $out) => $out
		->map('[preview]')->video('libx264', crf: 28)
		->withoutMetadata(chapters: true)->toDisk('previews'))
	->save();
```

The example uses `ProtoneMedia\LaravelFFMpeg\ExportOutput`. Explicit outputs default to `filesystems.default`, not the first input disk. Adding an input also changes the single-output convenience default to `filesystems.default` unless `toDisk()` was explicit. Root output settings cannot be mixed with `output()` callbacks. After `output()`, `save()` takes no path. Duplicate destinations and composition destinations matching an input's disk/key identity are rejected.

`withoutMetadata(chapters: true)` is shorthand for `withoutMetadata()->chaptersFrom(null)`. It explicitly omits both global metadata and chapters. The default `withoutMetadata()` and `chapters: false` leave the chapter source unchanged, including earlier selections. A later `chaptersFrom($input)` overrides the omission. The shorthand works on the single-output export and in output callbacks. Multiple inputs still require explicit metadata and chapter sources or omission for each output. Clip defaults remain unchanged.

Graph labels are simple identifiers. Each named output label must be consumed or mapped exactly once. Branching requires `split` or `asplit`. A quote-aware lexer ignores brackets inside literal values. FFmpeg remains responsible for filter syntax and pad types. Graph-produced streams do not automatically preserve input language tags or dispositions.

### Output stream tags and dispositions

```php
$media->export()
	->filterComplex('[0:a]volume=0.5[commentary]')
	->map('[commentary]')->mapVideo(0) // Audio is output stream 0, video is stream 1.
	->audio('aac', bitrate: '128k')->video('copy')
	->streamMetadata('a:0', 'language', 'eng')
	->streamMetadata(0, 'title', 'English commentary')
	->disposition('a:0', ['default', 'comment'])
	->toDisk('videos')->save('commentary.mkv');
```

These methods also work in `output()` callbacks and apply to that output only. Their selectors address **output order after mapping**, not input positions or graph labels. `0` selects the first output stream overall, `'a:0'` selects the first output audio stream, and `'a'` selects all output audio streams. Supported types are `v`, `a`, `s`, `d`, and `t`. Input selectors such as `'0:a:0'`, graph labels, and optional `?` suffixes are rejected.

`streamMetadata($stream, $key, $value)` sets an output-stream tag. An empty value removes that tag. Values may contain Unicode and punctuation, but not NUL bytes. Global `metadata()` remains separate. Explicit stream tags can restore language or title on graph-produced streams even when global metadata is omitted.

`disposition($stream, $flags)` replaces all disposition flags for matching output streams. It accepts a supported FFmpeg 9 flag name, a `+`-joined set such as `'default+forced'`, or a list such as `['default', 'forced']`. An empty list or `'0'` clears all flags. Relative `+flag` and `-flag` edits are not accepted by this helper. Raw `outputOptions()` remains available for those edits and is emitted after the named helpers.

Calls preserve declaration order, including overlapping selectors. Later matches override earlier values. These settings do not select streams, and retention depends on the output container. Configuration freezes when execution starts.

Type-scoped encoding settings and simple filters apply to every selected stream of that type, including graph outputs. A graph-filtered video can accompany copied input audio. Copying a graph-fed stream or applying both a simple filter and a complex graph to the same stream fails during FFmpeg output setup. These failures report `MediaException` with phase `encoding` and category `configuration`, without publication. Graph types are not guessed during pure inspection.

`resource($name, Media)` supports `drawtext`'s `fontfile` and `textfile`, and `lut3d`'s `file` option. Every placeholder must be a whole, unquoted named option value, such as `file={{look}}`. The compiler substitutes a generated relative filename, and the process runs inside the workspace. Original keys can contain punctuation or Unicode without entering the graph. Unknown, unused, quoted, or embedded placeholders fail before staging. A logo or other media resource uses `addInput()` and a graph input label, not `movie` or `amovie`.

```php
$media->export()
	->resource('font', FFMpeg::disk('assets')->open('fonts/Brand.ttf'))
	->textResource('title', "100%: café 'quoted' [literal],; %{not_expanded}")
	->videoFilter('drawtext=fontfile={{font}}:textfile={{title}}:expansion=none:fontcolor=white:fontsize=24')
	->video('libx264', crf: 23)->toDisk('videos')->save('title.mp4');

$media->export()
	->subtitleResource('captions', FFMpeg::disk('assets')->open('captions/English.srt'))
	->videoFilter('subtitles=filename={{captions}}')
	->clip(4, duration: 2) // A cue at source time 5 appears at output time 1.
	->video('libx264', crf: 23)->toDisk('videos')->save('captioned.mp4');
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
| Network inputs | Read explicit public URLs. Stage authenticated finite downloads through Http. Keep per-input headers separate and redact signed URLs. | `NativeUrlTest` | HTTP direct probe/export and authenticated downloads verified with real network fixtures; redirect, truncation, TLS rejection, capability, redaction, and cleanup checks covered |
| Scoped and read-only filesystems | Preserve prefixes and wrappers. Never write through a read-only adapter or escape a destination root. | `DiskTest`, new wrapper scenarios required | Open |
| Ordinary encoding | Encode audio and video to different disks. Verify codecs, duration, channels, geometry, and write options. | `NativeExportTest`, `ExportTest` | Basic CRF encode and Storage publication verified; broader media coverage open |
| Display geometry | Resize by one dimension or stretch explicitly. Contain or cover an exact canvas, preserving anamorphic display aspect ratio. | `NativeExportTest` | Resize, square pixels, centered padding, centered crop, and independent output geometry verified with real media; rotation helpers open |
| Independent operations | Start two exports from the same media descriptor without inheriting settings or temporary files. | `NativeExportTest`, `ExportTest`, `MediaOpenerTest` | Single-output builders and repeated real exports verified |
| Stream selection | Use multilingual audio, forced subtitles, cover art, and chapters. Verify ordered, optional, and negative maps and resulting dispositions. | `NativeExportTest`, `HlsExportTest` | Language selection, negative maps, order, dispositions, and absent optional maps verified; subtitle/artwork exports open |
| Metadata preservation | Copy global tags. Preserve chapters only for an unchanged timeline unless explicitly transformed. Test remove and override controls. | `NativeExportTest` | Copy, override, and clip chapter omission verified; broader removal cases open |
| Quality and passes | Compare CRF and target-bitrate contracts. Reject conflicts. Prove explicit two-pass encoding with isolated pass logs. | `NativeExportTest`, `ExportTest`, README pass-progress contract | CRF and conflict checks verified; explicit libx264 two-pass verified with independent output logs and nested exports |
| Copy and clipping | Remux without changing codecs. Distinguish accurate transcoding from keyframe copy cuts. Preserve audio offsets and VFR. | `NativeExportTest`, `CopyTest` | Remux, accurate duration, and decoded keyframe identity verified; VFR and offset coverage open |
| Filters and resources | Apply ordered audio and video filters with punctuation, Unicode, fonts, and staged subtitles. Reject filters on copied streams. | `NativeExportTest`, `NativeCompositionTest`, `AddFilterTest`, `LegacyFilterMappingTest`, `ExportTest` | Simple filters, LUTs, fonts, literal text, and SRT/ASS/WebVTT rendering verified with real media, including clipped cue timing and manifest rejection |
| Multiple inputs and outputs | Compose mixed-disk inputs and map separate audio and video to different output disks. Verify stream identity and common-filter branching. | `NativeCompositionTest`, `ExportTest` | Mixed-disk overlay, LUT, branching, independent clips, copied audio, validation, and partial publication verified |
| Concatenation | Copy compatible inputs and report mismatches. Normalize heterogeneous inputs only under an explicit geometry, timing, and audio policy. | `NativeConcatTest` | Compatible video/audio copy, mixed disks, decoded order, layout rejection, source retention, and audio-delay packet timestamps verified; explicit video-only and audio-inclusive normalization graphs verified, including delayed audio across different sample rates and channel layouts |
| Frames | Seek to a selected timestamp. Return image bytes and save an image with verified dimensions and content. Fail when no frame exists. | `NativeExportTest`, `FrameTest` | Single images and interval/count output sequences verified with real media; image-quality controls remain available through output options |
| Image sequences and timelapse | Read a numbered image sequence at a declared input rate and verify output timing. | `NativeSequenceTest` | Local PNG and staged JPEG inputs verified with decoded order, explicit count, decimal and rational rates, non-default starts, exact output duration, source retention, and cleanup; missing, mismatched, disguised, and corrupt images reject publication |
| Storyboards and VTT | Verify tile order, dimensions, quality, margin, padding, and cue coordinates. Use different VTT and image directories. | `NativeStoryboardTest` | Real PNG and JPEG sheets verify decoded tile order, asymmetric grids, spacing, partial sheets, quality, clipped timing, relative and custom URLs, cue coordinates, validation before publication, partial upload failure, source retention, and cleanup |
| Waveform images | Generate and inspect an actual waveform image from audio. | `NativeExportTest`; `MediaOpenerTest` only checks the old object type | Real amplitude, muted audio, clipped timing, colors, odd image dimensions, and post-render resize verified |
| Watermarks | Apply local and staged remote images, offsets, scaling, and optional image preprocessing to ordinary and HLS output. | `NativeCompositionTest`, `NativeUrlTest`, `NativeHlsTest`, legacy `HlsExportTest` | Ordinary graph exports verify transparency, offsets, scaling, repeated static images, copied audio, and optional private-copy image preparation; URL downloads and callback failure cleanup verified; native HLS verifies a shared overlay graph, two scaled branches, clipped duration, and decoded watermark pixels |
| Analysis | Inspect volume-analysis output and nonzero status without requiring a media artifact. | `VolumeDetectTest` | Real two-track PCM verifies mean and peak volume, track selection, filters, silence, local and staged inputs, inspectable nonzero exits, opt-in exceptions, redaction, timeout reaping, source retention, and cleanup without publication |
| Progress and events | Parse fragmented protocol records. Handle unknown duration and multiple passes. Stop on callback failure. Complete only after validation and publication. | `NativeExportTest`, `ExportTest` | Single-pass lifecycle and real child reaping verified; both encoding passes, failure cleanup, and cancellation covered |
| Results and publication | Validate nonempty expected artifacts before remote writes. Stream files, handle false Storage returns, report partial publication, and clean only owned temporary files. | `NativeExportTest`, `NativeCompositionTest`, `NativeHlsTest`, `ExportTest`, `TileTest` | Multiple single-file outputs and owned HLS inventories verified, including publication order, validation before writes, concrete partial failures, source protection, and cleanup |
| Adaptive HLS | Produce multiple MPEG-TS variants, audio-only media with artwork, silent video, first or all audio, copy output, and custom nested paths. Verify every playlist reference. | `NativeHlsTest`, legacy `HlsExportTest`, `HLSPlaylistGeneratorTest` | Multiple variants, complete playlist decoding, segment keyframes and aligned boundaries, audio-only artwork exclusion, silent video, first/all audio, copy, clipped watermarks, staged input, streamed publication, and relative references under a nested master verified; custom paths and playlist hooks remain open |
| Encrypted HLS | Decrypt AES-128 segments and verify IVs, supplied keys, key rotation boundaries, private key storage, and failure cleanup. | `EncryptedHlsExportTest` | Open |
| Dynamic HLS | Rewrite master, variant, segment, and key URLs relative to their containing playlists. Verify response integration and missing or invalid input errors. | `DynamicHLSPlaylistTest` | Open |
| Extension points | Inspect scoped argument arrays without execution. Preserve custom filters, playlist hooks, and output configuration without arbitrary driver forwarding. | `NativeExportTest`, `NativeCompositionTest`, `BeforeSavingTest`, README | Pure inspection, scoped options, graphs, and output callbacks implemented; playlist hooks open |

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

## Remaining sequence

1. Secondary-resource and composition acceptance now covers fonts, literal text, staged subtitles, and multiple inputs and outputs. Broader stream and filesystem preservation scenarios remain open in the acceptance table.
2. Preserve ordinary encoding, composition, concatenation, frames, timelapse, watermarks, waveform images, storyboards, and VTT.
3. Preserve adaptive and encrypted HLS and dynamic playlist responses against the same execution contract.
4. Replace the README and upgrade guide, then remove the legacy driver and dependency tree. No parallel legacy backend remains in the released package.
5. Pass every acceptance row on PHP 8.4 and 8.5, Laravel 12 and 13, and matching tested FFmpeg and FFprobe builds. Pin setup and CI to the tested stable patch. Measure performance before making speed claims.

Optional follow-ups remain ordered as loudness normalization, fMP4 HLS and alternate groups, then waveform-peaks data. They do not replace an open 9.0 acceptance row.

## Test commands

`vendor/bin/phpunit tests/NativeProbeTest.php tests/NativeExportTest.php tests/NativeCompositionTest.php` runs the native process and Storage contract tests. Real-media cases require `FFMPEG_9_FFPROBE` to identify an installed FFprobe 9.0.x binary and report skips when that variable is absent. Export acceptance uses a matching `ffmpeg` binary in the same directory, built with libx264, libfreetype, libharfbuzz, and libass. Font acceptance requires `/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf`. This is temporary while the legacy suite still uses the distribution build.

`FFMPEG_9_FFPROBE=/opt/ffmpeg-9.0.2/bin/ffprobe vendor/bin/phpunit` runs both suites when that binary is installed. Native acceptance uses FFmpeg and FFprobe 9.0.2. Legacy encoding tests still use the separately configured legacy binaries. Passing legacy tests does not establish native support for their workflows. Pinning the matching native build in setup and CI remains a release gate.

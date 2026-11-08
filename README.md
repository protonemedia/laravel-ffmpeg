# Laravel FFmpeg

[![Latest Version on Packagist](https://img.shields.io/packagist/v/pbmedia/laravel-ffmpeg.svg?style=flat-square)](https://packagist.org/packages/pbmedia/laravel-ffmpeg)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
![run-tests](https://github.com/protonemedia/laravel-ffmpeg/workflows/run-tests/badge.svg)
[![Total Downloads](https://img.shields.io/packagist/dt/pbmedia/laravel-ffmpeg.svg?style=flat-square)](https://packagist.org/packages/pbmedia/laravel-ffmpeg)

FFmpeg workflows using Laravel Process, Storage, and Http. Requires PHP 8.4+, Laravel 12 or 13, and matching FFmpeg/FFprobe 9.0.x binaries. Setup and CI use 9.0.2.

Version 9 replaces the PHP-FFMpeg backend and its format/filter objects. Existing applications must follow the [upgrade guide](UPGRADING.md). The [API reference and acceptance contract](REWRITE.md) describes limits and advanced options.

## Sponsor us

[<img src="https://inertiaui.com/visit-card.jpg" />](https://inertiaui.com/inertia-table?utm_source=github&utm_campaign=laravel-ffmpeg)

Support maintenance through [GitHub Sponsors](https://github.com/sponsors/pascalbaljet), and see [Inertia Table](https://inertiaui.com/inertia-table?utm_source=github&utm_campaign=laravel-ffmpeg).

## Installation

Version 9 is under development on `9.x`. Use `9.x-dev` before the 9.0 release; the command below targets the released version.

```bash
composer require pbmedia/laravel-ffmpeg:^9.0
php artisan vendor:publish --provider="ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider" --tag=config
ffmpeg -version
ffprobe -version
```

Package discovery registers the provider and `FFMpeg` facade. Binary validation is lazy, so package discovery and configuration caching do not launch FFmpeg.

```dotenv
FFMPEG_BINARIES=/opt/ffmpeg/bin/ffmpeg
FFPROBE_BINARIES=/opt/ffmpeg/bin/ffprobe
FFMPEG_TEMPORARY_FILES_ROOT=/var/tmp/media
```

The temporary root must exist and be writable. FFmpeg needs the encoders, filters, and protocols used by your application. The repository's `.agents/install-ffmpeg` builds the tested binary pair with libx264, libass, fonts, image support, and HTTPS.

## Encode and copy

```php
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$media = FFMpeg::disk('uploads')->open('lesson.mov');

$result = $media->export()
	->video('libx264', crf: 23, preset: 'medium')
	->audio('aac', bitrate: '128k')
	->resize(width: 1280)
	->toDisk('videos', ['visibility' => 'private'])
	->save('lesson.mp4');

$media->export()->video('copy')->audio('copy')->save('lesson.mkv');
```

Each operation has independent settings. Bitrates require units such as `'2500k'`, not bare numbers. CRF and target bitrate cannot combine. Copies reject filters and accurate clipping. A compatible container is still required.

```php
$media->export()->video('libx264', bitrate: '2500k')->audio('aac')->twoPass()->save('two-pass.mp4');

$media->export()->video('libx264')->audio('aac')
	->clip('00:00:12.500', duration: 8)->save('accurate.mp4');

$media->export()->video('copy')->audio('copy')
	->clip(12.5, end: 20.5, seek: 'keyframe')->save('keyframe.mkv');
```

Two-pass video encoding owns private statistics for each output. Keyframe cuts can start before the requested position. Accurate cuts transcode the affected streams. Timeline changes omit chapters unless explicitly mapped.

## Inputs and probing

```php
$media = FFMpeg::disk()->open('lesson.mp4'); // Current filesystems.default.
$media = FFMpeg::disk($filesystem)->open('lesson.mp4');
$media = FFMpeg::disk()->open($request->file('video'));
$media = FFMpeg::disk()->open(new \Illuminate\Http\File('/absolute/path/lesson.mp4'));

$public = FFMpeg::disk()->openUrl('https://cdn.example.com/lesson.mp4');
$private = FFMpeg::disk()->openUrl($url, headers: ['Authorization' => 'Bearer '.$token], download: true);

$metadata = $media->probe();
$metadata->duration(); // Nullable seconds.
$metadata->video()->width();
$metadata->audioStreams();
$metadata->chapters();
$metadata->get('format.tags.title');
$metadata->toArray();
```

Storage paths are disk-relative keys, not URLs or local absolute paths. Descriptor construction and `inspect()` do no I/O. Local inputs can be read in place; other filesystems stream into a private operation workspace. Scoped and read-only input adapters retain their boundaries. Read-only destinations fail publication.

Authenticated HTTP inputs require `download: true`. Downloads stream to private disk with deadlines and reject redirects. Direct public or signed URLs enter FFmpeg arguments; never put reusable credentials in them. HTTPS verification stays enabled. URL transport details are in the [reference](REWRITE.md#http-inputs).

## Tracks and metadata

```php
$media->export()
	->mapVideo()->mapAudio(language: 'eng')
	->video('copy')->audio('copy')
	->metadata('title', 'English lesson')
	->streamMetadata('a:0', 'language', 'eng')
	->disposition('a:0', ['default', 'comment'])
	->save('english.mkv');

$media->export()->map('0:V:0')->map('0:a')->map('0:s')->map('0:v:1')
	->video('copy')->audio('copy')->outputOptions(['-c:s', 'copy'])
	->save('subtitles-and-cover.mp4');

$media->export()->video('copy')->audio('copy')
	->withoutMetadata(chapters: true)->save('without-tags.mkv');
```

Without maps, exports select the first motion-video and audio stream. Explicit maps replace that selection. Map subtitles and cover art explicitly and choose container-compatible codecs. `Stream` exposes `language()`, `isForced()`, `isDefault()`, and `isCoverArt()`. Stream tags and dispositions address output order. Raw fields stay available through `get()`.

## Geometry and filters

```php
$media->export()->video('libx264')->audio('copy')
	->crop(1280, 720, x: 40, y: 20)
	->rotate(90)
	->pad(800, 1280, color: 'black')
	->save('portrait.mp4');

$media->export()->fit(1280, 720, mode: 'contain'); // Black bars.
$media->export()->fit(1280, 720, mode: 'cover');   // Center crop.
$media->export()->video('libx264')->audio('aac')
	->deinterlace()->frameRate('30000/1001')->flipHorizontal()->grayscale()
	->volume(0.5)->delayAudio(0.25)
	->fadeIn(1)->fadeOut(1, start: 9)->save('treated.mp4');
```

Filters run in registration order. One-dimension `resize()` preserves display aspect ratio; two dimensions stretch. `fit()` produces square pixels. Crop and pad use positive even dimensions and centered offsets by default. Rotation accepts clockwise quarter turns and negative equivalents, after input autorotation. FFmpeg validates source-dependent geometry.

`frameRate()` drops or duplicates frames without changing playback speed. Deinterlace before resizing; the default `frame` mode preserves frame count, while `mode: 'field'` produces a frame per field. `flipVertical()` is also available. `volume()` takes a linear multiplier, where zero mutes. `delayAudio()` inserts silence on every channel and extends the audio. Fades affect both video and audio unless you pass `video: false` or `audio: false`; `fadeOut()` requires an explicit start in seconds. Filtered streams must be encoded, not copied. Use `videoFilter()` and `audioFilter()` for other trusted FFmpeg expressions.

```php
$media->export()
	->drawText('100%: café [literal]', FFMpeg::disk('assets')->open('Brand.ttf'), size: 24, x: 12, y: 18)
	->burnSubtitles(FFMpeg::disk('assets')->open('captions.srt'))
	->video('libx264')->audio('copy')->save('captioned.mp4');
```

Text and subtitle helpers stage private resources instead of interpolating file paths into arbitrary filter strings. Filters are trusted application code, not an untrusted-media sandbox.

## Frames, timelapse, and storyboards

```php
$media->frame(at: 12.5)->resize(width: 640)->toDisk('images')->save('poster.jpg');
$bytes = $media->frame(at: 12.5)->get();
$media->frames(every: 2)->resize(width: 320)->save('frames/frame-%05d.jpg');
$media->frames(count: 10)->save('frames/frame-%05d.png');

FFMpeg::disk('images')->openSequence('frame-%04d.png', count: 90, framerate: '30000/1001', start: 1)
	->export()->video('libx264')->save('timelapse.mp4');

$media->storyboard(every: 5, columns: 5, rows: 3, margin: 2, padding: 1)
	->resize(width: 160)->vtt('cues/lesson.vtt')->toDisk('images')
	->save('sheets/lesson-%03d.jpg');

$media->waveform(width: 800, height: 120, colors: ['blue'])
	->clip(2, duration: 10)->save('waveform.png');
```

Image patterns require one integer placeholder. Storyboard VTT references are relative to the cue file, even when images use a different directory. JPEG quality uses `outputOptions(['-q:v', '3'])`. A waveform PNG is distinct from waveform-peaks data.

## Change playback speed and make GIFs

```php
$media->speed(2)->video('libx264')->audio('aac')->save('double-speed.mp4');
$media->speed(0.5)->video('libx264')->audio('aac')->save('half-speed.mp4');

$media->gif(width: 480, frameRate: 12, loop: 0)
	->clip(4, duration: 3)->toDisk('images')->save('preview.gif');
```

`speed()` changes video timing and audio tempo without changing audio pitch. It requires a finite positive factor, a zero container/video origin, and nonnegative audio origins. It scales an existing audio delay with playback speed. Stream copy, clipping, input options, custom graphs, watermark inputs, and multiple outputs are not supported. Encode a clip first if you need to retime only part of a source.

`gif()` selects the first motion-video stream and omits audio. Width defaults to 480 and frame rate to 12. `loop: 0` loops forever; `-1` disables looping. Accurate `clip()` runs before palette generation. The helper writes a private lossless intermediate, generates a palette, then encodes the GIF. This avoids buffering the entire clip in memory, but adds disk use and processing passes. Keep previews short and small. GIF output rejects custom maps, graphs, additional inputs or outputs, audio settings, and encoder overrides.

## Waveform-peaks data

```php
$peaks = $media->waveformPeaks(samplesPerBucket: 256)
	->audioTrack(1)->clip(10, duration: 30)->mono()->buckets(1000)->get();

$peaks->sampleRate;
$peaks->duration();
$peaks->channels; // Channel-major signed 16-bit [min, max] pairs.
$peaks->bucketFrames;
$peaks->toArray();

$media->waveformPeaks()->buckets(2000)->toDisk('waveforms')->save('lesson.json');
```

Separate channels are the default. `mono()` mixes channels with equal weights before reduction. `buckets(n)` produces at most `n` nonempty buckets; otherwise each bucket contains up to `samplesPerBucket` decoded frames. Partial final buckets have no padding. Version-1 JSON includes the selected sample rate, channel mode, frame count, and bucket timing. Reduction uses bounded reads and caps results at 100,000 channel-buckets. Private PCM disk usage grows with decoded duration and is cleaned after the operation.

## Two-pass loudness normalization

```php
$result = $media->normalizeLoudness(integrated: -18, truePeak: -2, range: 7,
	linear: true, sampleRate: 48000, audio: 1)
	->clip(3, duration: 4)->audioFilter('pan=mono|c0=c0,volume=0.5')
	->audio('pcm_s24le')->toDisk('audio')->save('normalized.wav');

$result->measurement;
$result->normalization;
$result->requestedMode;
$result->actualMode(); // FFmpeg can fall back to dynamic normalization.
```

Normalization selects one audio track and produces one audio-only output. It decodes the trimmed, preprocessed samples once into a private lossless intermediate, then measures and normalizes those same samples. Channel processing uses `audioFilter()`; `sampleRate:` owns the output rate. Silence remains silence, with nonfinite statistics represented by null. Graphs, additional inputs or outputs, stream copy, and video settings are rejected.

## Add a watermark

```php
$logo = FFMpeg::disk('assets')->open('logo.png');

$media->export()->resize(width: 1280)
	->watermark($logo, position: 'bottom-right', x: 20, y: 15, width: 160, opacity: 0.8)
	->video('libx264')->audio('copy')->toDisk('videos')->save('branded.mp4');
```

`watermark()` adds the image input and maps for you. It accepts static PNG and JPEG images from local or non-local Storage. Marks render after all output video filters, regardless of where you call `watermark()`. Offsets are inward margins from the chosen corner. Anchors also include `top-left`, `top-right`, `bottom-left`, and `center`. Call the helper again to add another mark.

Watermarks work with ordinary single or multiple media outputs and copied audio, but require video encoding. Custom maps, custom graphs, additional media inputs, and HLS require an explicit overlay graph instead. For image preprocessing, call `prepareImage()` with your own image-library callback; it receives a private writable copy:

```php
$logo = FFMpeg::disk('assets')->open('logo.png')->prepareImage(function (string $privatePath) {
	\Spatie\Image\Image::load($privatePath)->width(160)->save();
});
```

## Join clips

```php
$first = FFMpeg::disk('clips')->open('first.mp4');
$second = FFMpeg::disk('archive')->open('second.mp4');

// Different dimensions, frame rates, or audio layouts: encode a common format.
$first->concatTranscoded($second)
	->normalizeVideo(1280, 720, frameRate: 30, fit: 'contain')
	->normalizeAudio(sampleRate: 48000, channels: 2)
	->video('libx264')->audio('aac')->toDisk('videos')->save('joined.mp4');

// Compatible streams: join without re-encoding.
$first->concat($second)->export()->video('copy')->audio('copy')->save('joined.mkv');
```

`concatTranscoded()` requires a video policy and either `normalizeAudio()` or `withoutAudio()`. `contain` adds black bars; `cover` crops; `stretch` changes the aspect ratio. It selects each source's first motion-video and audio streams and preserves delayed audio through silence. Sources must be finite with zero container/video origins. Audio normalization requires audio in every source; it does not invent a track for a silent clip. The helper owns one output and its graph, so root filters, custom graphs, and stream copy are rejected. See the [concat reference](REWRITE.md#concatenation) for limits and raw graphs.

Copy concatenation requires compatible streams and never falls back to transcoding. Transcoded concatenation decodes and encodes every clip. Neither method combines source chapters.

## Compose multiple inputs and outputs

Use `addInput()` and `filterComplex()` when you need custom branching, stream selection, or an HLS watermark:

```php
use ProtoneMedia\LaravelFFMpeg\ExportOutput;

$export = $media->export();
$logo = $export->addInput(FFMpeg::disk('assets')->open('logo.png'));

$result = $export
	->filterComplex('[0:v]['.$logo.':v]overlay=x=W-w-20:y=H-h-20,split[full][small];[small]scale=640:360[preview]')
	->output('full.mp4', fn (ExportOutput $out) => $out
		->map('[full]')->map('0:a:0?')->video('libx264')->audio('copy')
		->metadataFrom(0)->chaptersFrom(null)->toDisk('videos'))
	->output('preview.mp4', fn (ExportOutput $out) => $out
		->map('[preview]')->video('libx264')
		->withoutMetadata(chapters: true)->toDisk('previews'))
	->save();
```

Mixed-disk inputs stage once per execution. Each output requires explicit maps, metadata/chapter policy, and a destination. Consume each graph label once; use `split` or `asplit` to branch. Resources such as LUTs use `resource('look', $media)` and whole named placeholders such as `lut3d=file={{look}}`.

## Adaptive HLS and encryption

```php
use ProtoneMedia\LaravelFFMpeg\HlsVariant;

$result = $media->hls(segmentDuration: 6)
	->variant('360p', fn (HlsVariant $variant) => $variant
		->resize(height: 360)->video('libx264', bitrate: '800k'))
	->variant('720p', fn (HlsVariant $variant) => $variant
		->resize(height: 720)->video('libx264', bitrate: '2500k'),
		playlist: 'lists/720p/index.m3u8', segments: 'segments/720p/part-%03d.ts')
	->encrypt('secrets', fn (string $keyPath) => route('lesson.key', ['key' => basename($keyPath)]),
		rotateEvery: 3, directory: 'lessons/42/keys')
	->toDisk('streams')->save('lessons/42/master.m3u8');

$result->playlist();
$result->variant('720p')->files();
$result->files()->where('role', 'hls-key');
```

MPEG-TS is the default packaging. Custom paths are disk-relative, with playlist references relative to each containing playlist. Keys live on an explicitly selected private disk and publish before media. A supplied `key:` must contain 16 raw bytes and cannot combine with rotation. Generated keys are fresh for each save; rotation groups share keys across variants. Every segment has an independent random IV.

Your application authorizes key requests and serves the 16 raw bytes. AES-128 is not DRM. OpenSSL is required for encryption. Segments precede playlists, with the master last. All expected artifacts validate before publication, but writes are not transactional.

```php
$media->hls()->variant('720p', fn ($variant) => $variant->resize(height: 720))
	->playlistUsing(fn (string $text, string $path, ?string $variant) =>
		str_replace("#EXTM3U\n", "#EXTM3U\n# Lesson playlist\n", $text))
	->save('master.m3u8');
```

Playlist hooks can add comments and supported non-resource tags. They cannot remove or change generated timing, references, stream attributes, or encryption directives.

## fMP4 and alternate HLS groups

```php
$result = $media->hls(segmentDuration: 6)->fmp4()
	->video('libx264', bitrate: '2500k')->twoPass()
	->variant('360p', fn (HlsVariant $variant) => $variant
		->resize(height: 360)->video(bitrate: '800k')
		->audioGroup('sound')->subtitlesGroup('captions'))
	->variant('720p', fn (HlsVariant $variant) => $variant
		->resize(height: 720)->audioGroup('sound')->subtitlesGroup('captions'),
		playlist: 'video/720p/index.m3u8', segments: 'video/720p/part-%03d.m4s',
		init: 'video/720p/start.mp4')
	->audioRendition('sound', 'English', fn (HlsVariant $rendition) => $rendition
		->map('0:a:0')->audio('aac', bitrate: '128k'), language: 'en', default: true)
	->audioRendition('sound', 'Español', fn (HlsVariant $rendition) => $rendition
		->map('0:a:1')->audio('aac', bitrate: '128k'), language: 'es')
	->subtitleRendition('captions', 'English', fn (HlsVariant $rendition) => $rendition
		->map('0:s:0'), language: 'en', default: true)
	->toDisk('streams')->save('lesson/master.m3u8');

$result->audioRendition('sound', 'English')->playlist();
$result->subtitleRendition('captions', 'English')->files();
$result->files()->where('role', 'hls-init');
```

Each audio rendition encodes once and can serve several variants. Renditions require one explicit map of their declared type. Variants with an audio group omit muxed audio. Subtitle renditions use one full-presentation WebVTT segment and require known duration without a complex timing graph. Shared subtitle groups require aligned variant presentation clocks.

fMP4 owns one initialization file per audio/video playlist and uses `.m4s` segments. AES-128 encryption is supported only with MPEG-TS and is rejected with fMP4 or alternate subtitles. Referencing variants and renditions require matching clips. `twoPass()` measures bitrate-configured video variants without producing first-pass HLS files. Init files and media publish before playlists. Dynamic responses rewrite init references through `mediaUrlsUsing()` and traverse alternate playlists through `playlistUrlsUsing()`.

## Dynamic HLS responses

```php
// Inside an authorized controller. Resolve the path from this lesson's owned inventory.
return FFMpeg::disk('streams')->playlist($path)
	->playlistUrlsUsing(fn (?string $target, string $source, string $uri) =>
		$target === null ? $uri : route('lesson.playlist', ['lesson' => $lesson->id, 'path' => $target]))
	->mediaUrlsUsing(fn (?string $target, string $source, string $uri) =>
		$target === null ? $uri : Storage::disk('streams')->temporaryUrl($target, now()->addMinutes(10)))
	->keyUrlsUsing(function (?string $target, string $source, string $uri) use ($lesson) {
		$key = $lesson->keys()->where('playlist_uri', $uri)->firstOrFail();

		return route('lesson.key', ['lesson' => $lesson->id, 'key' => $key->id]);
	});
```

Resolvers receive the canonical disk target, containing playlist key, and original URI. HTTP(S) and root-relative references have a `null` disk target. Responses implement Laravel `Responsable` with the HLS content type and `private, no-store`. The application owns authorization, scoped signatures, key lookup, CORS, and cache policy. Never expose a generic disk/key download route.

```php
$playlist = FFMpeg::disk('streams')->playlist('lessons/42/master.m3u8');
$text = $playlist->get(); // Selected playlist only.
$all = $playlist->all();  // Local playlists keyed by their original disk paths.
```

Rendering never fetches external URLs or reads media or keys. Repeated calls generate fresh signed URLs. Traversal and input/output sizes are bounded.

## Progress, results, and failures

```php
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;

$export = $media->export()->onProgress(function (Progress $progress) {
	if ($progress->isEncoding()) {
		// percentage, speed, and remainingSeconds can be null.
		// pass and totalPasses identify video encoding passes.
	}
	// Throw ExportCancelled to stop and reap the encoder before cleanup.
});

try {
	$result = $export->video('libx264')->audio('aac')->save('lesson.mp4');
	$result->file()->path;
	$result->file('lesson.mp4', disk: 'uploads');
	$result->files();
	$result->processes(); // Bounded, redacted summaries.
	$stream = $result->file()->readStream(); // The caller closes this stream.
} catch (MediaException $exception) {
	// phase, category, diagnostics, published, and destination.
}

$analysis = $media->analyze('volumedetect', audio: 0);
$analysis->successful();
$analysis->errorOutput();
$analysis->throw(); // Opt in to throwing on a nonzero analysis exit.
```

`save()` throws on encoding, validation, or publication failure. Analysis-only requests retain inspectable exit status. Lifecycle events are `ExportStarting`, `ExportCompleted`, `ExportFailed`, and `ExportCancelled`. Completion follows publication and workspace cleanup. Callback exceptions propagate unchanged.

Failures report successfully published siblings; they do not delete them. Existing objects may already have been replaced. Temporary workspaces and pass statistics are operation-owned and cleaned on success, failure, or cancellation. No global cleanup call is needed.

## Configuration and security

Configuration is published as `config/laravel-ffmpeg.php`. Total and idle deadlines are distinct and use seconds. Output validation is not a hostile-media sandbox. Untrusted inputs require operating-system isolation and resource limits. Known credentials are redacted from diagnostics, but direct signed URLs remain visible in process arguments.

Use `inspect()` for pure argument inspection, `globalOptions()`, `outputOptions()`, and `inputOptions:` for supported scoped options. Reserved filenames, binaries, progress transport, and statistics paths cannot be overridden. There is no driver forwarding or arbitrary before-save command mutation.

## Testing and development

```bash
.agents/setup
FFMPEG_9_FFPROBE=/opt/ffmpeg-9.0.2/bin/ffprobe vendor/bin/phpunit --order-by random \
	--display-deprecations --display-phpunit-deprecations \
	--fail-on-deprecation --fail-on-phpunit-deprecation
```

Setup installs the toolchain and dependencies once; resume checks them without reinstalling. CI uses Ubuntu 26.04, PHP 8.4/8.5, Laravel 12/13, and lowest/stable dependencies. Real-media acceptance requires the matching tested binary pair.

## Contributing and security

See [CONTRIBUTING](CONTRIBUTING.md) and [CHANGELOG](CHANGELOG.md). Report security issues to code@protone.media, not the public issue tracker.

## Credits

- [Pascal Baljet](https://github.com/pascalbaljet)
- [All contributors](../../contributors)

## License

The [MIT license](LICENSE.md).

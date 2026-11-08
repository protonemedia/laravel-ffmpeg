# Upgrade to version 9

Version 9 replaces PHP-FFMpeg with Laravel Process, Storage, and Http. The old builders, format objects, driver forwarding, and filter factories are removed. This is a breaking upgrade, not a compatibility layer.

## Update the runtime

Use PHP 8.4 or later, Laravel 12 or 13, and matching FFmpeg and FFprobe 9.0.x binaries. The tested patch is 9.0.2. Install the codecs, filters, fonts, and HTTPS support that your application needs.

Republish the configuration and review your application's values. Keep `ffmpeg.binaries`, `ffprobe.binaries`, `ffmpeg.threads`, `timeout`, and `temporary_files_root`. `idle_timeout` is separate from the total deadline. The legacy log channel and encrypted-HLS temporary root no longer apply. Set `FFMPEG_BINARIES` and `FFPROBE_BINARIES` to the matching pair.

## Replace the entrypoint and format objects

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

// Before:
FFMpeg::fromDisk('uploads')->open('lesson.mov')->export()
	->toDisk('videos')->inFormat(new \FFMpeg\Format\Video\X264)
	->save('lesson.mp4');

// After:
$media = FFMpeg::disk('uploads')->open('lesson.mov');
$result = $media->export()->video('libx264', crf: 23)->audio('aac', bitrate: '128k')
	->toDisk('videos')->save('lesson.mp4');
```

Use `FFMpeg::open($key)` for disk-relative keys on the current `filesystems.default`. Keep `disk('uploads')` when selecting a Storage disk, or use `disk($filesystem)` for a supplied filesystem. Pass a Laravel `File` or `UploadedFile` directly to `FFMpeg::open()` to read that local file instead of a Storage key.

```php
$media = FFMpeg::open($request->file('video'));
$public = FFMpeg::openUrl('https://cdn.example.com/lesson.mp4');
$private = FFMpeg::openUrl($url, headers: ['Authorization' => 'Bearer '.$token], download: true);
```

`FFMpeg::openUrl()` uses the same HTTP rules as the disk selector. Headers require `download: true`. Downloads use Laravel Http with TLS verification and reject redirects. Direct URLs enter media process arguments, so do not include reusable credentials. See the [HTTP input reference](REWRITE.md#http-inputs) for transport limits and redaction rules.

Builders do not forward methods to PHP-FFMpeg. Use explicit codecs, scoped `inputOptions:`, `globalOptions()`, `outputOptions()`, and filters. Bitrates include units such as `'2500k'`. `inspect()` returns the argument plan without reading inputs or launching a process. `save()` returns `ExportResult`, not the builder.

## Replace filters and clipping

```php
$media->export()->video('libx264')->audio('copy')
	->resize(width: 1280)->crop(1280, 720)->rotate(90)
	->save('portrait.mp4');

$media->export()->video('libx264')->audio('aac')
	->clip('00:00:12.500', duration: 8)->save('clip.mp4');

$media->export()->video('copy')->audio('copy')
	->clip(12.5, end: 20.5, seek: 'keyframe')->save('copy.mkv');
```

Filters run in registration order. One-dimension resize preserves display aspect ratio; two explicit dimensions stretch. Use `fit()` for a contain or cover policy. Stream copy rejects filters and accurate clipping. Keyframe cuts can begin before the requested time. Timeline changes omit chapters unless explicitly mapped.

Use `frameRate()`, `flipHorizontal()`, `flipVertical()`, `grayscale()`, and `deinterlace()` instead of filter-factory callbacks. Deinterlace before resizing. `volume()` takes a linear multiplier; `delayAudio()` inserts silence on all channels. `fadeIn()` and `fadeOut()` affect video and audio by default. Fade-out requires an explicit start in seconds. Disable either stream type with `video: false` or `audio: false`.

Use `drawText()` and `burnSubtitles()` for staged fonts, literal text, and subtitle files. Image preprocessing is an explicit `prepareImage()` callback on a private copy, with your own image-library dependency. `videoFilter()` and `audioFilter()` remain available for trusted expressions.

## Replace watermark and concat builders

```php
$logo = FFMpeg::disk('assets')->open('logo.png');
$media->export()->watermark($logo, x: 20, y: 15, width: 160)
	->resize(width: 1280)->video('libx264')->audio('copy')->save('branded.mp4');

$second = FFMpeg::disk('uploads')->open('second.mp4');
$media->concatTranscoded($second)
	->normalizeVideo(1280, 720, frameRate: 30, fit: 'contain')
	->normalizeAudio(sampleRate: 48000, channels: 2)
	->video('libx264')->audio('aac')->save('joined.mp4');

$media->concat($second)->export()->video('copy')->audio('copy')->save('joined.mkv');
```

`watermark()` adds inputs and maps automatically for static PNG or JPEG images. The default anchor is bottom-right; offsets are inward margins. Marks run after all output video filters, regardless of call order. Repeated marks and multiple ordinary outputs are supported, but custom maps, graphs, additional media inputs, HLS, and video copy are not. Use `addInput()` and `filterComplex()` for custom composition and HLS overlays.

`concat()` joins compatible streams without silently transcoding. `concatTranscoded()` returns an export builder with one output. Choose `normalizeVideo()` and either `normalizeAudio()` or `withoutAudio()` before saving. Normalization selects each input's first motion-video and audio streams, preserves audio delays, and requires finite sources with zero container/video origins. It rejects root filters, custom graphs, and stream copy. Sources without audio require `withoutAudio()` or a custom graph; the helper does not create missing audio tracks. Source chapters are not combined.

## Replace audio mixing graphs

```php
$music = FFMpeg::disk('music')->open('background.mp3');
$media->backgroundMusic($music, volume: 0.2)
	->video('copy')->audio('aac')->save('with-music.mp4');

$media->mixAudio($music, volume: 0.4, originalVolume: 0.8, normalize: true)
	->fadeIn(1, video: false)->video('copy')->audio('aac')->save('mixed.mp4');
```

`backgroundMusic()` loops by default. Use `loop: false` for one playback, or `mixAudio()` for one-shot mixing with explicit gains. Call `loopAudio()` on the returned builder to repeat the added track. The primary container's duration sets output length. Short nonlooping audio ends in silence, and longer audio is trimmed. Silent video and audio-only primaries are supported.

Mixing selects the first audio track from each input and the primary's first motion-video track when present. Output audio is stereo at 48000 Hz and must be encoded. Video copy is allowed without video filters. Audio filters and `volume()` run after mixing. Added audio starts at its first decoded sample; original audio retains its offset relative to video.

The default `normalize: false` adds the weighted signals and can clip. With original audio present, `normalize: true` divides both padded branches equally by two, without changing gain when a track ends. This is not loudness normalization.

Both inputs require finite positive durations, and the added input requires audio. Video primaries require known zero container and video origins and a known nonnegative original-audio origin. Looping URL audio requires `openUrl($url, download: true)`. The builder supports one output and rejects maps, custom graphs, watermarks, clips, and two-pass encoding. Save a clip first if needed. See the [audio mixing reference](REWRITE.md#audio-mixing) for the complete contract.

## Replace speed and GIF graphs

```php
$media->speed(2)->video('libx264')->audio('aac')->save('faster.mp4');
$media->gif(width: 480, frameRate: 12, loop: 0)
	->clip(4, duration: 3)->save('preview.gif');
```

`speed()` retimes video and preserves audio pitch. It requires a finite positive factor, zero container/video origins, and nonnegative audio origins. It rejects clipping, input options, custom graphs, watermark inputs, multiple outputs, and stream copy. To retime a clip, save that clip first.

`gif()` owns the palette graph, maps, and encoder and produces no audio. Accurate clipping happens before palette generation. A private lossless intermediate avoids buffering the entire clip in memory, at the cost of disk space and additional processing passes. Keep clips short and dimensions small. Custom maps, graphs, additional inputs or outputs, audio settings, and encoder overrides are rejected.

## Select streams and metadata explicitly

```php
$media->export()->mapVideo()->mapAudio(language: 'eng')
	->video('copy')->audio('copy')->withoutMetadata(chapters: true)
	->streamMetadata('a:0', 'language', 'eng')->disposition('a:0', ['default'])
	->save('english.mkv');

$metadata = $media->probe();
$metadata->video()?->width();
$metadata->audioStreams();
$metadata->chapters();
$metadata->toArray();
```

Without maps, ordinary exports select the first motion-video and audio streams. Subtitles and cover art require explicit maps and container-compatible codecs. Multiple-input outputs require explicit maps and metadata/chapter policy. Stream tags and dispositions address output order, not input indexes.

## Replace image and analysis operations

```php
$jpeg = $media->frame(at: 12.5)->get();
$media->frames(every: 2)->resize(width: 320)->save('frames/%05d.jpg');
$media->storyboard(every: 5, columns: 4, rows: 3, margin: 2, padding: 1)
	->resize(width: 160)->vtt('previews/lesson.vtt')->save('sheets/%03d.jpg');
$media->waveform()->save('waveform.png');
$analysis = $media->analyze('volumedetect');
$analysis->errorOutput();
$analysis->throw();
```

`get()` returns raw JPEG bytes, not Base64. Storyboard geometry applies to each tile before assembly. `waveform()` writes an image; `waveformPeaks()` returns versioned amplitude data. Analysis-only nonzero exits remain inspectable until `throw()` is called.

## Replace HLS builders and responses

```php
use ProtoneMedia\LaravelFFMpeg\HlsVariant;

$result = $media->hls(segmentDuration: 6)
	->variant('360p', fn (HlsVariant $variant) => $variant
		->resize(height: 360)->video('libx264', bitrate: '800k'))
	->encrypt('secrets', fn ($path) => route('lesson.key', ['key' => basename($path)]), rotateEvery: 3)
	->toDisk('streams')->save('lesson/master.m3u8');

$result->playlist();
$result->variant('360p')->files();

return FFMpeg::disk('streams')->playlist('lesson/master.m3u8')
	->mediaUrlsUsing(fn ($target, $source, $uri) =>
		$target === null ? $uri : \Illuminate\Support\Facades\Storage::disk('streams')
			->temporaryUrl($target, now()->addMinutes(10)));
```

Variants configure encoding only. MPEG-TS remains the default. Private key storage is explicit; key routes return 16 raw bytes after authorization. Keys rotate deterministically by segment group, with a random IV per segment. Playlist hooks are additive and cannot replace generated references or encryption lines. URL resolvers receive the canonical target, containing playlist, and original URI, not just a basename.

Do not expose a generic disk download route. Scope playlist and key paths to the authorized asset. Dynamic responses default to `private, no-store`; your application owns authorization, expiry, CORS, and cache policy.

## Update progress and failure handling

```php
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

try {
	$result = $media->export()->video('libx264')->onProgress(function (Progress $progress) {
		// percentage, speed, and remainingSeconds can be null.
		// pass and totalPasses describe encoding passes.
	})->save('lesson.mp4');
	$file = $result->file();
} catch (MediaException $exception) {
	// phase, category, bounded diagnostics, and successfully published siblings.
}
```

Completion follows validation, publication, and cleanup. Throw `ExportCancelled` from a callback to stop the operation. Other callback exceptions propagate unchanged. There is no global `cleanupTemporaryFiles()` call and no arbitrary before-save command mutation.

All artifacts validate before the first Storage write, but publication is not transactional. A later transfer failure can leave earlier outputs or replaced objects. Failures report successful writes without deleting them. Streams returned by `GeneratedFile::readStream()` belong to the caller and must be closed.

See the [README](README.md) for executable API examples and the [reference](REWRITE.md) for detailed limits.

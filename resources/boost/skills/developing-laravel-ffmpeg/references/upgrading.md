# Upgrading From v8

Version 9 replaces PHP-FFMpeg with its own builders, so each v8 call below maps to a v9 call instead of running unchanged. For a call this reference does not list, read the matching section of `vendor/pbmedia/laravel-ffmpeg/UPGRADE.md`.

## Dependencies

Version 9 requires PHP ^8.4, `laravel/framework` ^13.44.1, and matching FFmpeg and FFprobe binaries of release 9.0 or later:

```bash
composer require pbmedia/laravel-ffmpeg:^9.0
```

Version 9 no longer depends on `php-ffmpeg/php-ffmpeg`, so Composer removes it. Delete every import from the `FFMpeg\` namespace, such as `FFMpeg\Format\Video\X264` and `FFMpeg\Coordinate\TimeCode`, and every import of a removed v8 class, such as `ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFactory`, `Filters\TileFactory`, `Exporters\HLSExporter`, `Exporters\EncodingException`, and `Filesystem\Media`. The `ProtoneMedia\LaravelFFMpeg\Support\FFMpeg` facade keeps its name.

Watermark image edits, such as `grayscale` and `blur`, need `intervention/image` instead of `spatie/image`. An app that required `spatie/image` only for watermark edits may run `composer remove spatie/image`. Then republish the configuration, reapply your own values, and check the binaries:

```bash
composer require intervention/image:^4.2

php artisan vendor:publish --provider="ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider" --tag=config --force

php artisan ffmpeg:doctor --encoder=libx264 --encoder=aac
```

Version 8 logged every FFmpeg command to the channel of its `log_channel` option, and version 9 logs none. To keep a record, log the `inspect()` arguments before `save()`, as the failures section below shows, because the `ExportStarting` and `ExportFailed` events carry the `operationId` and `outputs` but no command.

## Formats

PHP-FFMpeg's `new X264` encoded two passes at 1000 kb/s video and 128 kb/s audio. A plain `video('libx264')` call encodes one pass at constant quality instead, so set both bitrates and call `twoPass` to keep the v8 output:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('uploads')
    ->open('videos/intro.mov')
    ->export()
    ->video('libx264', bitrate: '1000k')
    ->audio('aac', bitrate: '128k')
    ->audioOptions(['-ac', '1'])
    ->twoPass()
    ->toDisk('videos')
    ->save('videos/intro.mp4');
```

- `setKiloBitrate(1000)` becomes `bitrate: '1000k'` on `video`, and `setAudioKiloBitrate(128)` becomes `bitrate: '128k'` on `audio`.
- `setAudioChannels(1)` becomes `audioOptions(['-ac', '1'])`.
- `(new X264)->setKiloBitrate(0)` encoded one pass without a bitrate, as `video('libx264')` does.
- `new CopyFormat` becomes `video('copy')->audio('copy')`.
- `new WebM` becomes `video('libvpx', bitrate: '1000k')->audio('libvorbis', bitrate: '128k')`, in one pass. The package does not check the codecs of a `.webm` output, and FFmpeg's WebM muxer takes VP8, VP9, or AV1 video with Vorbis or Opus audio, so a build without `libvpx` may use `video('libsvtav1', bitrate: '1000k')`, and a build without `libvorbis` the native `vorbis` encoder below.
- `new Wav` becomes `audio('pcm_s16le')`, `new Mp3` becomes `audio('libmp3lame', bitrate: '128k')`, and `new Flac` becomes `audio('flac')`.
- `new Aac` used the `libfdk_aac` encoder. Call `audio('aac', bitrate: '128k')` for FFmpeg's own encoder, or pass `libfdk_aac` when the build includes it.
- `new Vorbis` becomes `audio('vorbis')->audioOptions(['-strict', 'experimental', '-ac', '2'])`, or `audio('libvorbis')` when the build includes it.

Audio in another container, such as `.mp4`, needs `mapAudio`. An audio-only destination, such as an `.mp3`, `.m4a`, `.wav`, `.flac`, or `.ogg` file, keeps only the first audio stream, as v8's audio formats did:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('uploads')
    ->open('videos/intro.mov')
    ->export()
    ->audio('aac', bitrate: '128k')
    ->toDisk('audio')
    ->save('audio/intro.m4a');
```

## Raw Options

Raw arguments, such as `addFilter(['-movflags', '+faststart'])`, become `outputOptions`, `videoOptions`, `audioOptions`, or `globalOptions`. Each takes option and value pairs as strings, apart from the `-shortest` flag of `outputOptions`, and only the options the package allows:

- `['-strict', '-2']` is rejected, while `['-strict', 'experimental']` works.
- `['-q:v', 5]` is rejected, while `['-q:v', '5']` works.
- `['-metadata', 'title=Intro']` is rejected, so call `metadata('title', 'Intro')` instead.
- `beforeSaving()`, which edited the command, and `$media()`, which returned PHP-FFMpeg's media object, have no counterpart, so use the builder methods and the options above.

## Opening and Probing Media

- `FFMpeg::fromDisk('uploads')` becomes `FFMpeg::disk('uploads')`, and `fromFilesystem($filesystem)` becomes `disk($filesystem)`.
- `FFMpeg::openUrl($url, $headers)` becomes `FFMpeg::openUrl($url, headers: $headers, download: true)`. Laravel's Http client then downloads the URL once per operation, such as once for `probe` and again for `save`, and FFmpeg never contacts it.
- `open([$first, $second])` becomes one `open` call plus `addInput`, as the multiple inputs section below shows.
- `getDurationInSeconds()`, which rounded to whole seconds and threw an `UnknownDurationException` without a duration, becomes `probe()->duration()`, which returns a `?float` and `null` without one. The `(int) round($duration)` call returns the v8 value, and `getDurationInMiliseconds()` becomes `(int) round($duration * 1000)`.
- `getVideoStream()->get('codec_name')` becomes `probe()->video()?->codec()`, and `probe()->toArray()` returns the raw FFprobe data.
- `each($items, $callback)` has no counterpart, so loop over one opened `Media`:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$media = FFMpeg::disk('uploads')
    ->open('videos/intro.mp4');

$duration = $media->probe()
    ->duration(); // Null when FFprobe reports no duration.

$wholeSeconds = $duration === null ? null : (int) round($duration);

$milliseconds = $duration === null ? null : (int) round($duration * 1000);

foreach ([1, 4, 7] as $key => $seconds) {
    $media->frame(at: $seconds)
        ->toDisk('images')
        ->save("thumbnails/intro-{$key}.png");
}
```

## Filters, Watermarks, and Chained Exports

- `addFilter()` callbacks and PHP-FFMpeg filter objects become builder methods, such as `resize`, `crop`, `rotate`, and `clip`, from the [encoding reference](encoding.md).
- `addFilter('-vf', 'hflip')` becomes `flipHorizontally()`, and `addFilter(['-af', 'volume=0.5'])` becomes `volume(0.5)`. Pass other expressions to `videoFilter` and `audioFilter`.
- `new ClipFilter(TimeCode::fromSeconds(12.5), TimeCode::fromSeconds(8))` becomes `clip(12.5, duration: 8)`, and `new ClipFilter(TimeCode::fromSeconds(30))`, which ran to the end of the media, becomes `clip(30)`.
- `addWatermark(fn (WatermarkFactory $watermark) => ...)` becomes `watermark($logo, fn (Watermark $watermark) => ...)` with an opened image, and the factory's `width(100)` and `greyscale()` become `size(width: 100)` and `grayscale()`.
- `concatWithoutTranscoding()` and `concatWithTranscoding()` become `$first->concat($second)` and `$first->concatTranscoded($second)`.
- `withVisibility('public')` becomes `visibility('public')`, or `toDisk('public', ['visibility' => 'public'])`.
- The `save` method returns a result instead of the builder, so a statement that chained another `export()` after `save()` becomes a second export of the same `Media`:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$media = FFMpeg::disk('uploads')
    ->open('videos/intro.mov');

$media->export()
    ->video('libx264')
    ->audio('aac')
    ->toDisk('videos')
    ->save('videos/intro.mp4');

$media->export()
    ->resize(width: 640)
    ->video('libvpx', bitrate: '1000k')
    ->audio('libvorbis', bitrate: '128k')
    ->toDisk('public')
    ->visibility('public')
    ->save('videos/intro-640.webm');
```

## Multiple Inputs and Outputs

The `open([$left, $right])`, `addFilter('[0:v][1:v]', 'hstack', '[v]')`, and `addFormatOutputMapping($format, Media::make($disk, $path), $maps)` calls become `addInput`, `filterComplex`, and one `output` closure per file. Each output of an export with several inputs also needs a metadata and chapter policy, such as `metadataFrom(0)` with `chaptersFrom(null)`, or `withoutMetadata(chapters: true)`:

```php
use ProtoneMedia\LaravelFFMpeg\ExportOutput;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$export = FFMpeg::disk('uploads')
    ->open('videos/left.mp4')
    ->export();

$right = $export->addInput(FFMpeg::disk('uploads')
    ->open('videos/right.mp4'));

$export->filterComplex("[0:v][{$right}:v]hstack[v]")
    ->output('videos/stacked.mp4', fn (ExportOutput $output) => $output
        ->map('[v]')
        ->map('0:a:0')
        ->video('libx264')
        ->audio('aac')
        ->metadataFrom(0)
        ->chaptersFrom(null)
        ->toDisk('videos'))
    ->output('audio/left.wav', fn (ExportOutput $output) => $output
        ->map('0:a:0')
        ->audio('pcm_s16le')
        ->withoutMetadata(chapters: true)
        ->toDisk('audio'))
    ->save();
```

An export with `output` callbacks throws an `InvalidArgumentException` for root output settings, such as `toDisk`, `video`, and `resize`, in either order, so call them inside each callback, as the example does.

## Frames

- `getFrameFromSeconds(10)->export()->save($path)` becomes `frame(at: 10)->save($path)`, and `getFrameContents()` becomes `frame(at: 10)->get()`, which returns JPEG bytes.
- `getFrameFromString('00:00:05.50')` becomes `frame(at: '00:00:05.50')`, and `getFrameFromTimecode(new TimeCode(0, 0, 8, 0))` becomes `frame(at: 8)`.
- `accurate()` has no counterpart, because `frame()` always seeks accurately.
- `exportFramesByInterval(2, 640, 360)` becomes `frames(every: 2)->resize(width: 640, height: 360)`, and `exportFramesByAmount(5, 320, 180)` becomes `frames(count: 5)->resize(width: 320, height: 180)`.
- `exportTile(fn (TileFactory $factory) => $factory->interval(5)->scale(160, 90)->grid(4, 3)->generateVTT('tiles/intro.vtt'))` becomes `storyboard(every: 5, columns: 4, rows: 3)->resize(width: 160, height: 90)->vtt('tiles/intro.vtt')`.
- The JPEG quality argument becomes `outputOptions(['-q:v', '5'])`, where a lower value means a higher quality.

The `frames` and `storyboard` methods take the frame nearest the middle of each interval, such as about 1, 3, and 5 seconds with `every: 2`, where v8 took the first frame of each interval at 0, 2, and 4 seconds. To keep the v8 times, loop over `frame(at:)`, which runs FFmpeg once per frame:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$media = FFMpeg::disk('uploads')
    ->open('videos/intro.mp4');

$duration = $media->probe()
    ->duration() ?? 0;

for ($index = 0; $index * 2 < $duration; $index++) {
    $media->frame(at: $index * 2)
        ->resize(width: 640, height: 360)
        ->outputOptions(['-q:v', '5'])
        ->toDisk('images')
        ->save(sprintf('frames/intro-%05d.jpg', $index + 1));
}
```

With a count, `frames(count: 5)` takes about 1, 3, 5, 7, and 9 seconds of a 10-second video, where `exportFramesByAmount(5)` took one frame every `(getDurationInSeconds() + 1) / 5` seconds from 0, which is 0, 2.2, 4.4, 6.6, and 8.8 seconds. To keep the v8 times, loop over the same interval:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$media = FFMpeg::disk('uploads')
    ->open('videos/intro.mp4');

$duration = $media->probe()
    ->duration() ?? 0;

$interval = (round($duration) + 1) / 5;

for ($index = 0; $index < 5 && $index * $interval < $duration; $index++) {
    $media->frame(at: $index * $interval)
        ->resize(width: 320, height: 180)
        ->toDisk('images')
        ->save(sprintf('frames/intro-%05d.png', $index + 1));
}
```

## Timelapses

The `asTimelapseWithFramerate(4)` call on an opened pattern becomes `openSequence`, whose `count` argument is required, because the package stages exactly that many images. Numbering starts at 1 unless you pass `start`:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('assets')
    ->openSequence('sequence/frame_%04d.png', count: 24, framerate: 4)
    ->export()
    ->video('libx264')
    ->toDisk('videos')
    ->save('timelapse/sequence.mp4');
```

## HLS Exports

- `exportForHLS()` becomes `hls()`, and `setSegmentLength(2)` becomes `hls(segmentDuration: 2)`.
- `addFormat((new X264)->setKiloBitrate(500), fn ($media) => $media->scale(960, 540))` becomes `variant('500', fn (HlsVariant $variant) => $variant->resize(width: 960, height: 540)->video('libx264', bitrate: '500k'))`.
- The `addFormat` callback's `HLSVideoFilters` becomes the `HlsVariant`, so `resize(640, 360)`, `addFilter('scale=640:360')`, `addLegacyFilter(fn (VideoFilters $filters) => $filters->resize(new Dimension(640, 360)))`, and `addFilter(fn ($filters, $in, $out) => $filters->custom($in, 'scale=640:360', $out))` each become `$variant->resize(width: 640, height: 360)`. Pass other expressions to the variant's `videoFilter`, such as `videoFilter('hqdn3d')`.
- `HLSVideoFilters::addWatermark()` has no per-variant counterpart, so call `watermark` on the `hls` builder, which marks every variant.
- Every variant starts each segment with a keyframe and closes its GOPs. Version 8 also set `-g 48`, or the value of `setKeyFrameInterval`, with `-sc_threshold 0`, so call `videoOptions(['-g', '48', '-sc_threshold', '0'])` on the `hls` builder, with `'60'` in place of `'48'` after `setKeyFrameInterval(60)`, to keep that keyframe interval within the segments of every variant.

Each variant writes `{variant}/index.m3u8` next to the master playlist, with segments named `segment-00000000.ts` onward, where v8 wrote flat names built from the master playlist's name, such as `adaptive_0_250.m3u8` and `adaptive_0_250_00000.ts` next to `adaptive.m3u8`. The `playlist` and `segments` arguments of `variant` take disk-relative paths, so they keep the v8 names and replace `useSegmentFilenameGenerator()`:

```php
use ProtoneMedia\LaravelFFMpeg\HlsVariant;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('uploads')
    ->open('videos/intro.mp4')
    ->hls(segmentDuration: 2)
    ->variant('250', fn (HlsVariant $variant) => $variant
        ->resize(width: 640, height: 360)
        ->video('libx264', bitrate: '250k'),
        playlist: 'hls/adaptive_0_250.m3u8',
        segments: 'hls/adaptive_0_250_%05d.ts')
    ->variant('500', fn (HlsVariant $variant) => $variant
        ->resize(width: 960, height: 540)
        ->video('libx264', bitrate: '500k'),
        playlist: 'hls/adaptive_1_500.m3u8',
        segments: 'hls/adaptive_1_500_%05d.ts')
    ->toDisk('public')
    ->save('hls/adaptive.m3u8');
```

The `encrypt` method from the [streaming reference](streaming.md) replaces the encryption methods:

- `withEncryptionKey($key)` becomes `encrypt('secrets', $keyUrl, key: $key)`, where `$key` holds 16 bytes.
- `withRotatingEncryptionKey($callback, 2)` becomes `encrypt('secrets', $keyUrl, rotateEvery: 2)`. The package writes each key to the `secrets` disk itself, so the callback that stored the keys goes away.
- `HLSExporter::generateEncryptionKey()` has no counterpart. Omit `key` to generate the key, or call `random_bytes(16)`.
- The `directory` argument sets the key directory on that disk, `hls-keys` by default. The package stores every key there under a generated name, including a `key` you pass.
- The playlists reference whatever `$keyUrl` returns, including a relative URI, so an app that stores and serves its own key as v8's default `secret.key` may keep that reference with `encrypt('secrets', fn () => 'secret.key', key: $key)`. The package still stores a copy of that key under a generated name.

## HLS Playlists

- `FFMpeg::dynamicHLSPlaylist()->fromDisk('public')->open($path)` becomes `FFMpeg::disk('public')->playlist($path)`, or the `Hls` facade's signed playback routes from the [streaming reference](streaming.md).
- `setKeyUrlResolver`, `setMediaUrlResolver`, and `setPlaylistUrlResolver` become `keyUrlsUsing`, `mediaUrlsUsing`, and `playlistUrlsUsing`. Each closure receives `?string $target`, `string $source`, and `string $uri`. The `$uri` argument holds the reference as the playlist writes it, which v8 passed as the filename, while `$target` holds its disk path, or `null` for an HTTP(S) or root-relative URL, and `$source` holds the path of the playlist that contains it.

## Progress and Failures

- `onProgress(fn ($percentage, $remaining, $rate) => ...)` becomes `onProgress(fn (Progress $progress) => ...)`. The `$percentage` argument becomes `$progress->percentage`, `$remaining` becomes `$progress->remainingSeconds`, and `$rate` becomes `$progress->speed`, which FFmpeg reports as a multiple of real time, such as `2.5`.
- Each property is a `?float`, such as a percentage of `27.09333`, where v8 passed whole numbers, so round a non-null percentage down with `floor` to match v8.
- The callback also runs outside the encoding phase with a `null` percentage. With `twoPass`, the percentage runs from 0 to 100 and `remainingSeconds` restarts once per pass, where v8 ran one percentage across both passes. The [running exports reference](running-exports.md) combines the passes into one percentage.
- `EncodingException` becomes `ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException`.
- `getCommand()` becomes `inspect()` on the builder before `save()`, because a `MediaException` carries no command. It returns one array of arguments, with placeholders such as `[input:0]` and `[output:0]` for the files, where v8's exception returned a shell-quoted string and its exporter one string per pass. With `twoPass`, it shows only the final pass, while a first-pass failure names `[analysis:0]` in `diagnostics`.
- `getErrorOutput()` becomes the exception's `diagnostics` property, which holds FFmpeg's error output with the same placeholders for the staged files.
- `getProcessOutput()` with a `volumedetect` filter becomes `analyze('volumedetect')`. Its `ProcessResult` offers `output()` and `errorOutput()` instead of `out()`, `errors()`, and `all()`, and FFmpeg prints the filter statistics to the error output.
- `FFMpeg::cleanupTemporaryFiles()` has no counterpart, because every operation removes its own workspace.

To log the command as one string, as v8's exception returned it, quote each argument:

```php
use Illuminate\Support\Facades\Log;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$export = FFMpeg::disk('uploads')
    ->open('videos/intro.mov')
    ->export()
    ->video('libx264', bitrate: '1000k')
    ->audio('aac', bitrate: '128k')
    ->twoPass()
    ->toDisk('videos');

$command = implode(' ', array_map('escapeshellarg', $export->inspect()));

try {
    $export->save('videos/intro.mp4');
} catch (MediaException $exception) {
    Log::error('The video could not be encoded.', [
        'command' => $command,
        'phase' => $exception->phase,
        'category' => $exception->category,
        'diagnostics' => $exception->diagnostics,
    ]);

    throw $exception;
}
```

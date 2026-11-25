# Encoding

## Opening and Probing Media

The `disk` method selects a Storage disk by name or instance, and `FFMpeg::open()` uses the `filesystems.default` disk. Nothing is read until an operation runs:

```php
use Illuminate\Http\File;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$media = FFMpeg::disk('uploads')
    ->open('videos/intro.mov');

$local = FFMpeg::open(new File('/var/media/intro.mov'));

$remote = FFMpeg::openUrl('https://media.example.com/intro.mp4', headers: [
    'Authorization' => 'Bearer '.config('services.media.token'),
], download: true);

$metadata = $media->probe();

$seconds = $metadata->duration(); // Null when FFprobe reports no duration.

$width = $metadata->video()
    ?->width();

$audioStreams = $metadata->audioStreams();
```

Headers require `download: true`, which streams the URL to a private file. Without it, FFmpeg reads the URL directly and the URL appears in its arguments.

To validate an upload, use the `isValid` method. It returns `false` instead of throwing when FFprobe cannot read the file or finds no audio or video stream:

```php
use Illuminate\Http\Request;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

/** @var Request $request The controller action's request. */
if (! FFMpeg::open($request->file('video'))->isValid()) {
    abort(422, 'The video could not be read.');
}
```

The `isValid` method reads only the container header. A truncated upload whose header comes first, such as a faststart MP4 cut short, still passes, and its export fails later with the `input` category.

## Encoding and Copying

Each call to `export` starts an independent configuration. The output goes to the input's disk unless you call `toDisk`:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$result = FFMpeg::disk('uploads')
    ->open('videos/intro.mov')
    ->export()
    ->video('libx264', crf: 23, preset: 'medium')
    ->audio('aac', bitrate: '128k')
    ->resize(width: 1280)
    ->toDisk('videos', ['visibility' => 'private'])
    ->save('videos/intro.mp4');

$path = $result->file()->path;
```

To change only the container, pass `copy` to the `video` and `audio` methods:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('uploads')
    ->open('videos/intro.mp4')
    ->export()
    ->video('copy')
    ->audio('copy')
    ->save('videos/intro.mkv');
```

## Pixel Formats

Every video track encoded with `libx264` uses the 8-bit 4:2:0 `yuv420p` pixel format of the High profile, so 10-bit, 4:2:2, and 4:4:4 sources still play in browsers. Other encoders keep the source's pixel format. To keep a 10-bit source as the High 10 profile, pass another pixel format to the `videoOptions` method:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('uploads')
    ->open('videos/intro.mov')
    ->export()
    ->video('libx264')
    ->videoOptions(['-pix_fmt', 'yuv420p10le'])
    ->save('videos/intro-10-bit.mp4');
```

## HDR and SDR Video

An export keeps the PQ or HLG color tags of an HDR source and its HDR10 mastering display and content light level metadata. The `color` method only relabels the video: a transfer other than `smpte2084` or `arib-std-b67` also drops the HDR10 metadata, but the pixel values stay HDR. Converting to SDR requires a tonemapping filter, such as `zscale` with `tonemap`, or `libplacebo`, so check for them with `FFMpeg::capabilities()` first:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$capabilities = FFMpeg::capabilities();

if ($capabilities->has('filters', 'zscale') && $capabilities->has('filters', 'tonemap')) {
    FFMpeg::disk('uploads')
        ->open('videos/intro-hdr.mov')
        ->export()
        ->videoFilter('zscale=t=linear:npl=100,format=gbrpf32le,zscale=p=bt709,'
            .'tonemap=tonemap=hable:desat=0,zscale=t=bt709:m=bt709:r=tv,format=yuv420p')
        ->color('bt709', 'bt709', 'bt709')
        ->video('libx264')
        ->save('videos/intro-sdr.mp4');
}
```

## Clipping and Two-Pass Encoding

The `clip` method cuts accurately by transcoding. Passing `seek: 'keyframe'` allows stream copy, but the clip may start early. Two-pass encoding requires a video bitrate:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$media = FFMpeg::disk('uploads')
    ->open('videos/intro.mov');

$media->export()
    ->video('libx264')
    ->audio('aac')
    ->clip('00:00:12.500', duration: 8)
    ->save('clips/accurate.mp4');

$media->export()
    ->video('copy')
    ->audio('copy')
    ->clip(12.5, end: 20.5, seek: 'keyframe')
    ->save('clips/keyframe.mkv');

$media->export()
    ->video('libx264', bitrate: '2500k')
    ->audio('aac')
    ->twoPass()
    ->save('videos/two-pass.mp4');
```

## Resizing and Filters

Passing one dimension to `resize` keeps the aspect ratio, while two dimensions stretch the video. The `contain` and `cover` methods work like their Laravel image counterparts: `contain` fits the video inside the canvas with bars, and `cover` fills the canvas with a center crop. Filters run in the order you call them:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$media = FFMpeg::disk('uploads')
    ->open('videos/intro.mov');

$media->export()
    ->contain(1280, 720, background: 'black')
    ->video('libx264')
    ->audio('copy')
    ->save('videos/contained.mp4');

$media->export()
    ->cover(1080, 1920)
    ->video('libx264')
    ->audio('copy')
    ->save('videos/portrait.mp4');

$media->export()
    ->crop(1280, 720, x: 40, y: 20)
    ->rotate(90)
    ->grayscale()
    ->fadeIn(1)
    ->fadeOut(1, start: 9)
    ->volume(0.5)
    ->videoFilter('hqdn3d')
    ->video('libx264')
    ->audio('aac')
    ->save('videos/treated.mp4');
```

The `resize`, `crop`, `pad`, `contain`, and `cover` methods take even dimensions, as most video encoders require. A source with odd dimensions needs an even `resize` or `crop` before `libx264` encodes it, or the export fails with the `dimensions` category.

The builder also offers `pad`, `deinterlace`, `frameRate`, `flipHorizontally`, `flipVertically`, `delayAudio`, `audioFilter`, `drawText`, `burnSubtitles`, and `lut3d`. Fades affect video and audio unless you pass `video: false` or `audio: false`. Pass only trusted expressions to `videoFilter` and `audioFilter`.

## Watermarks

The `watermark` method overlays an opened image from any disk, configured by a closure that receives a `Watermark`:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Watermark;

$logo = FFMpeg::disk('assets')
    ->open('logo.png');

FFMpeg::disk('uploads')
    ->open('videos/intro.mov')
    ->export()
    ->resize(width: 1280)
    ->watermark($logo, fn (Watermark $watermark) => $watermark
        ->right(20)
        ->bottom(15)
        ->size(width: 160)
        ->opacity(0.8))
    ->video('libx264')
    ->audio('copy')
    ->toDisk('videos')
    ->save('videos/branded.mp4');
```

Without a closure, the mark appears at its original size in the bottom-right corner. The `left`, `right`, `top`, and `bottom` methods take a margin in output pixels, and `center` centers each axis that no edge method pins. Call `watermark` again to add another mark. Marks are drawn after every other video filter. Image edits on the `Watermark`, such as `grayscale`, `blur`, and `rotate`, require `intervention/image`.

## Tracks and Metadata

An export selects the first video and audio stream, and either may be missing. An export that configures its video, such as through `video`, `resize`, or `videoFilter`, requires a video stream and fails with the `mapping` category for an audio-only source. The `mapVideo`, `mapAudio`, and `map` methods replace that selection:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('uploads')
    ->open('videos/intro.mkv')
    ->export()
    ->mapVideo()
    ->mapAudio(language: 'eng')
    ->video('copy')
    ->audio('copy')
    ->metadata('title', 'English lesson')
    ->save('videos/english.mkv');
```

Subtitles and cover art need explicit maps, such as `map('0:s:0')`. The `videoStream`, `audioStream`, `coverArt`, and `withoutMetadata` methods are covered in the README's "Selecting Tracks and Metadata" section.

## Changing Playback Speed

The `speed` method changes the playback speed without changing the audio pitch:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('uploads')
    ->open('videos/intro.mov')
    ->speed(2)
    ->video('libx264')
    ->audio('aac')
    ->save('videos/double-speed.mp4');
```

The factor must be positive. Stream copy, clipping, watermarks, and multiple outputs are not supported, so export a clip first to retime part of a video.

## Joining Clips

The `concatTranscoded` method encodes clips with different formats to a common one. The `concat` method joins compatible streams by copying and returns a `Media`, so it needs `export()`:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$first = FFMpeg::disk('uploads')
    ->open('clips/first.mp4');

$second = FFMpeg::disk('uploads')
    ->open('clips/second.mp4');

$first->concatTranscoded($second)
    ->conformVideo(1280, 720, frameRate: 30, fit: 'contain')
    ->conformAudio(sampleRate: 48000, channels: 2)
    ->video('libx264')
    ->audio('aac')
    ->toDisk('videos')
    ->save('videos/joined.mp4');

$first->concat($second)
    ->export()
    ->video('copy')
    ->audio('aac')
    ->save('videos/joined.mkv');
```

The `concatTranscoded` method requires `conformVideo` and either `conformAudio` or `withoutAudio`. The `concat` method requires the same codec parameters in every clip. Encode the audio even then, because FFmpeg rejects copied AAC audio at the boundary between clips.

## Multiple Outputs

Each `output` closure configures one output of a single run, and publishes to the first input's disk unless it calls `toDisk`:

```php
use ProtoneMedia\LaravelFFMpeg\ExportOutput;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('uploads')
    ->open('videos/intro.mov')
    ->export()
    ->output('videos/full.mp4', fn (ExportOutput $output) => $output
        ->resize(width: 1920)
        ->video('libx264')
        ->audio('aac')
        ->toDisk('videos'))
    ->output('videos/preview.mp4', fn (ExportOutput $output) => $output
        ->resize(width: 640)
        ->video('libx264')
        ->audio('aac')
        ->toDisk('videos'))
    ->save();
```

For a hand-written filter graph, the `addInput` method returns the input's index for `filterComplex`, and each output needs explicit maps. The README's "Custom Filter Graphs" section shows the full pattern.

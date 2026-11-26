# Frames and Previews

## Thumbnails

The `frame` method extracts one image. The `save` method publishes it, while `get` returns the JPEG bytes without writing a file:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$media = FFMpeg::disk('uploads')
    ->open('videos/intro.mp4');

$media->frame(at: 12.5)
    ->resize(width: 640)
    ->toDisk('images')
    ->save('posters/intro.jpg');

$bytes = $media->frame(at: '00:00:12.500')
    ->get();
```

## Frame Sequences

The `frames` method samples at an interval in seconds or as an evenly spaced count. The path needs one integer placeholder:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

$media = FFMpeg::disk('uploads')
    ->open('videos/intro.mp4');

$media->frames(every: 2)
    ->resize(width: 320)
    ->toDisk('images')
    ->save('frames/intro-%05d.jpg');

$media->frames(count: 10)
    ->toDisk('images')
    ->save('frames/intro-%05d.png');
```

Each frame is the one nearest the middle of its interval, such as the frame at about 1 second for the first image with `every: 2`. To take frames at exact times, call `frame(at:)` for each time.

The `FFMpeg::disk('images')->openSequence('frame-%04d.png', count: 90, framerate: 30)` call turns numbered images back into a video through `export()`. The `count` argument is required, because the package stages exactly that many images, numbered from 1 unless you pass `start`.

## Storyboards

The `storyboard` method builds sprite sheets for a player's scrubbing preview, and `vtt` writes WebVTT cues that point at the sheets:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('uploads')
    ->open('videos/intro.mp4')
    ->storyboard(every: 5, columns: 5, rows: 3, margin: 2, padding: 1)
    ->resize(width: 160)
    ->vtt('storyboards/intro.vtt')
    ->toDisk('images')
    ->save('storyboards/intro-%03d.jpg');
```

Each tile shows the frame nearest the middle of its interval, such as the frame at about 2.5 seconds for the first cue with `every: 5`. A video that ends before the middle of its last interval shows its final frame in the last tile.

## GIFs

The `gif` method creates a silent, looping preview. Pass `loop: false` to play it once:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('uploads')
    ->open('videos/intro.mp4')
    ->gif(width: 480, frameRate: 12, loop: true)
    ->clip(4, duration: 3)
    ->toDisk('images')
    ->save('previews/intro.gif');
```

Keep GIFs short, because the builder writes a lossless intermediate file first.

## Waveform Images

The `waveform` method renders the audio as a PNG. For data that your own player draws, use `waveformPeaks` from the [audio reference](audio.md):

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('uploads')
    ->open('podcasts/episode.mp3')
    ->waveform(width: 800, height: 120, colors: '#2563eb')
    ->toDisk('images')
    ->save('waveforms/episode.png');
```

## Title Cards

The `still` method turns a PNG or JPEG into a silent H.264 video:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::disk('assets')
    ->open('title.png')
    ->still(duration: 5, frameRate: 25)
    ->toDisk('videos')
    ->save('videos/title.mp4');
```

Most encoders, including `libx264`, need even dimensions, so `resize` or `crop` an odd-sized image, or the export fails with the `dimensions` category.

## Camera Snapshots

The `openRtsp` method captures one frame from an RTSP camera, which you then process like any other frame:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::openRtsp(config('services.camera.url'), transport: 'tcp', timeout: 10)
    ->frame()
    ->resize(width: 640)
    ->toDisk('images')
    ->save('cameras/front-door.jpg');
```

Camera URLs belong in trusted configuration, never in user input. To publish a camera as a stream, use `FFMpeg::liveHls()` from the [streaming reference](streaming.md).

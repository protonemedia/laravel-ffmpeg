# Laravel FFMpeg Reference

Complete reference for `pbmedia/laravel-ffmpeg`. Full documentation: https://github.com/protonemedia/laravel-ffmpeg

## Installation

Verify FFmpeg is installed, then require the package:

```bash
ffmpeg -version
composer require pbmedia/laravel-ffmpeg
```

Publish the config file:

```bash
php artisan vendor:publish --provider="ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider"
```

If not using Package Discovery, register the service provider and facade manually:

```php
// config/app.php

'providers' => [
    ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider::class,
],

'aliases' => [
    'FFMpeg' => ProtoneMedia\LaravelFFMpeg\Support\FFMpeg::class,
],
```

## Basic Conversion

Convert an audio or video file between formats:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::fromDisk('songs')
    ->open('yesterday.mp3')
    ->export()
    ->toDisk('converted_songs')
    ->inFormat(new \FFMpeg\Format\Audio\Aac)
    ->save('yesterday.aac');
```

You can also use a filesystem instance directly:

```php
$media = FFMpeg::fromFilesystem($filesystem)->open('yesterday.mp3');
```

## Opening Files

### From a disk
```php
FFMpeg::fromDisk('videos')->open('video.mp4');
```

### From the default disk
```php
FFMpeg::open('video.mp4');
```

### From an uploaded file
```php
FFMpeg::open($request->file('video'));
```

### From a URL
```php
FFMpeg::openUrl('https://example.com/video.mp4');

FFMpeg::openUrl('https://example.com/video.mp4', [
    'Authorization' => 'Basic YWRtaW46MTIzNA==',
]);
```

### Multiple files
```php
FFMpeg::open('video1.mp4')->open('video2.mp4');

FFMpeg::open(['video1.mp4', 'video2.mp4']);

FFMpeg::fromDisk('uploads')
    ->open('video1.mp4')
    ->fromDisk('archive')
    ->open('video2.mp4');
```

### Multiple URLs
```php
FFMpeg::openUrl([
    'https://example.com/video1.mp4',
    'https://example.com/video2.mp4',
]);

FFMpeg::openUrl('https://example.com/video1.mp4', [
    'Authorization' => 'Basic YWRtaW46MTIzNA==',
])->openUrl('https://example.com/video2.mp4', [
    'Authorization' => 'Basic bmltZGE6NDMyMQ==',
]);
```

## Progress Monitoring

Monitor transcoding progress with a callback:

```php
FFMpeg::open('video.mp4')
    ->export()
    ->onProgress(function ($percentage) {
        echo "{$percentage}% transcoded";
    });
```

The callback may also expose remaining time and rate:

```php
FFMpeg::open('video.mp4')
    ->export()
    ->onProgress(function ($percentage, $remaining, $rate) {
        echo "{$remaining} seconds left at rate: {$rate}";
    });
```

## Handling Exceptions

When encoding fails, an `EncodingException` is thrown with access to the command and error output:

```php
use ProtoneMedia\LaravelFFMpeg\Exporters\EncodingException;

try {
    FFMpeg::open('video.mp3')
        ->export()
        ->inFormat(new \FFMpeg\Format\Audio\Aac)
        ->save('video.aac');
} catch (EncodingException $exception) {
    $command = $exception->getCommand();
    $errorLog = $exception->getErrorOutput();
}
```

## Filters

### Using a closure with VideoFilters
```php
use FFMpeg\Filters\Video\VideoFilters;

FFMpeg::fromDisk('videos')
    ->open('video.mp4')
    ->addFilter(function (VideoFilters $filters) {
        $filters->resize(new \FFMpeg\Coordinate\Dimension(640, 480));
    })
    ->export()
    ->toDisk('converted_videos')
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->save('small_video.mkv');
```

### Using a filter object
```php
$start = \FFMpeg\Coordinate\TimeCode::fromSeconds(5);
$clipFilter = new \FFMpeg\Filters\Video\ClipFilter($start);

FFMpeg::open('video.mp4')
    ->addFilter($clipFilter)
    ->export()
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->save('clipped.mkv');
```

### Filters after export
```php
FFMpeg::open('video.mp4')
    ->export()
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->addFilter(function (VideoFilters $filters) {
        $filters->resize(new \FFMpeg\Coordinate\Dimension(640, 480));
    })
    ->save('small_video.mkv');
```

### Custom filter arguments
```php
FFMpeg::open('video.mp4')
    ->addFilter(['-itsoffset', 1]);

// or

FFMpeg::open('video.mp4')
    ->addFilter('-itsoffset', 1);
```

## Resizing

A dedicated resize method for common use:

```php
FFMpeg::open('video.mp4')
    ->export()
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->resize(640, 480)
    ->save('resized.mp4');
```

The optional third argument is the mode: `fit` (default), `inset`, `width`, or `height`. The optional fourth argument forces standard ratios.

## Watermarks

### Basic positioning
```php
use ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFactory;

FFMpeg::open('video.mp4')
    ->addWatermark(function (WatermarkFactory $watermark) {
        $watermark->fromDisk('local')
            ->open('logo.png')
            ->right(25)
            ->bottom(25);
    });
```

### Alignment constants
```php
FFMpeg::open('video.mp4')
    ->addWatermark(function (WatermarkFactory $watermark) {
        $watermark->open('logo.png')
            ->horizontalAlignment(WatermarkFactory::LEFT, 25)
            ->verticalAlignment(WatermarkFactory::TOP, 25);
    });
```

Available constants: `WatermarkFactory::LEFT`, `WatermarkFactory::CENTER`, `WatermarkFactory::RIGHT`, `WatermarkFactory::TOP`, `WatermarkFactory::BOTTOM`.

### Watermark from URL
```php
FFMpeg::open('video.mp4')
    ->addWatermark(function (WatermarkFactory $watermark) {
        $watermark->openUrl('https://example.com/logo.png');

        // with headers
        $watermark->openUrl('https://example.com/logo.png', [
            'Authorization' => 'Basic YWRtaW46MTIzNA==',
        ]);
    });
```

### Watermark manipulation (requires spatie/image)
```php
FFMpeg::open('video.mp4')
    ->addWatermark(function (WatermarkFactory $watermark) {
        $watermark->open('logo.png')
            ->right(25)
            ->bottom(25)
            ->width(100)
            ->height(100)
            ->greyscale();
    });
```

## Export Without Transcoding

Use `CopyFormat` to change the container without re-encoding:

```php
use ProtoneMedia\LaravelFFMpeg\FFMpeg\CopyFormat;

FFMpeg::open('video.mp4')
    ->export()
    ->inFormat(new CopyFormat)
    ->save('video.mkv');
```

## Chaining Multiple Conversions

The `save` method returns a fresh `MediaOpener`, allowing chained exports:

```php
FFMpeg::open('movie.mov')
    ->export()
    ->toDisk('ftp')
    ->inFormat(new \FFMpeg\Format\Video\WMV)
    ->save('movie.wmv')

    ->export()
    ->toDisk('s3')
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->save('movie.mkv');
```

Set file visibility on export:

```php
FFMpeg::open('movie.mov')
    ->export()
    ->inFormat(new \FFMpeg\Format\Video\WebM)
    ->withVisibility('public')
    ->save('movie.webm');
```

## Frame Extraction

### Single frame
```php
FFMpeg::fromDisk('videos')
    ->open('video.mp4')
    ->getFrameFromSeconds(10)
    ->export()
    ->toDisk('thumbnails')
    ->save('frame_at_10s.png');
```

### Other frame methods
```php
$media = FFMpeg::open('video.mp4');

$frame = $media->getFrameFromString('00:00:13.37');

$timecode = new \FFMpeg\Coordinate\TimeCode(...);
$frame = $media->getFrameFromTimecode($timecode);
```

### Get raw frame contents
```php
$contents = FFMpeg::open('video.mp4')
    ->getFrameFromSeconds(2)
    ->export()
    ->getFrameContents();
```

### Export multiple frames by interval
```php
FFMpeg::open('video.mp4')
    ->exportFramesByInterval(2)
    ->save('thumb_%05d.jpg');
```

### Export multiple frames by amount
```php
FFMpeg::open('video.mp4')
    ->exportFramesByAmount(10, 320, 180)
    ->save('thumb_%05d.png');
```

Both methods accept optional width, height, and quality (JPEG range: 2-31) arguments:

```php
FFMpeg::open('video.mp4')
    ->exportFramesByInterval(2, 640, 360, 5)
    ->save('thumb_%05d.jpg');
```

## Tiles and Sprites

### Create tile images
```php
use ProtoneMedia\LaravelFFMpeg\Filters\TileFactory;

FFMpeg::open('video.mp4')
    ->exportTile(function (TileFactory $factory) {
        $factory->interval(5)
            ->scale(160, 90)
            ->grid(3, 5);
    })
    ->save('tile_%05d.jpg');
```

The `TileFactory` also supports `margin`, `padding`, `width`, `height`, and `quality` methods. You can pass only width or height to `scale()` to respect the aspect ratio.

### Generate VTT preview thumbnails
```php
FFMpeg::open('video.mp4')
    ->exportTile(function (TileFactory $factory) {
        $factory->interval(10)
            ->scale(320, 180)
            ->grid(5, 5)
            ->generateVTT('thumbnails.vtt');
    })
    ->save('tile_%05d.jpg');
```

## Multiple Exports Using Loops

```php
$mediaOpener = FFMpeg::open('video.mp4');

foreach ([5, 15, 25] as $key => $seconds) {
    $mediaOpener = $mediaOpener->getFrameFromSeconds($seconds)
        ->export()
        ->save("thumb_{$key}.png");
}
```

Using the built-in `each` method:

```php
FFMpeg::open('video.mp4')->each([5, 15, 25], function ($ffmpeg, $seconds, $key) {
    $ffmpeg->getFrameFromSeconds($seconds)->export()->save("thumb_{$key}.png");
});
```

## Timelapse

Create a timelapse from a sequence of images:

```php
FFMpeg::open('feature_%04d.png')
    ->export()
    ->asTimelapseWithFramerate(1)
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->save('timelapse.mp4');
```

## Multiple Inputs and Mappings

When using multiple inputs, add format output mappings so FFmpeg knows how to route streams:

```php
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;

FFMpeg::fromDisk('local')
    ->open(['video.mp4', 'audio.m4a'])
    ->export()
    ->addFormatOutputMapping(new X264, Media::make('local', 'new_video.mp4'), ['0:v', '1:a'])
    ->save();
```

### Stacking videos horizontally
```php
FFMpeg::fromDisk('local')
    ->open(['video.mp4', 'video2.mp4'])
    ->export()
    ->addFilter('[0:v][1:v]', 'hstack', '[v]')
    ->addFormatOutputMapping(new X264, Media::make('local', 'stacked.mp4'), ['0:a', '[v]'])
    ->save();
```

### Complex filters via callback
```php
use FFMpeg\Filters\AdvancedMedia\ComplexFilters;

FFMpeg::open(['video.mp4', 'video2.mp4'])
    ->export()
    ->addFilter(function (ComplexFilters $filters) {
        // $filters->watermark(...);
    });
```

## Concatenation

### Without transcoding
```php
FFMpeg::fromDisk('local')
    ->open(['video.mp4', 'video2.mp4'])
    ->export()
    ->concatWithoutTranscoding()
    ->save('concat.mp4');
```

### With transcoding
```php
FFMpeg::fromDisk('local')
    ->open(['video.mp4', 'video2.mp4'])
    ->export()
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->concatWithTranscoding($hasVideo = true, $hasAudio = true)
    ->save('concat.mp4');
```

## Determine Duration

```php
$media = FFMpeg::open('video.mp4');

$durationInSeconds = $media->getDurationInSeconds();        // int
$durationInMiliseconds = $media->getDurationInMiliseconds(); // float
```

## Handling Remote Disks

When working with remote disks, temporary files are created locally. Clean them up after processing:

```php
FFMpeg::cleanupTemporaryFiles();
```

Configure the temporary directory in `config/laravel-ffmpeg.php`:

```php
'temporary_files_root' => '/custom/tmp/path',
```

## HLS (HTTP Live Streaming)

### Basic HLS export
```php
use FFMpeg\Format\Video\X264;

$lowBitrate = (new X264)->setKiloBitrate(250);
$midBitrate = (new X264)->setKiloBitrate(500);
$highBitrate = (new X264)->setKiloBitrate(1000);

FFMpeg::fromDisk('videos')
    ->open('video.mp4')
    ->exportForHLS()
    ->setSegmentLength(10)
    ->setKeyFrameInterval(48)
    ->addFormat($lowBitrate)
    ->addFormat($midBitrate)
    ->addFormat($highBitrate)
    ->save('adaptive.m3u8');
```

### Keep all audio streams
By default, HLS exports include the first audio stream. To keep all audio streams:

```php
FFMpeg::open('video.mp4')
    ->exportForHLS()
    ->keepAllAudioStreams()
    ->addFormat($lowBitrate)
    ->save('adaptive.m3u8');
```

### Per-format filters in HLS
```php
$lowBitrate = (new X264)->setKiloBitrate(250);
$midBitrate = (new X264)->setKiloBitrate(500);
$highBitrate = (new X264)->setKiloBitrate(1000);
$superBitrate = (new X264)->setKiloBitrate(1500);

FFMpeg::open('video.mp4')
    ->exportForHLS()
    ->addFormat($lowBitrate, function ($media) {
        $media->addFilter('scale=640:480');
    })
    ->addFormat($midBitrate, function ($media) {
        $media->scale(960, 720);
    })
    ->addFormat($highBitrate, function ($media) {
        $media->addFilter(function ($filters, $in, $out) {
            $filters->custom($in, 'scale=1920:1200', $out);
        });
    })
    ->addFormat($superBitrate, function ($media) {
        $media->addLegacyFilter(function ($filters) {
            $filters->resize(new \FFMpeg\Coordinate\Dimension(2560, 1920));
        });
    })
    ->save('adaptive.m3u8');
```

### Custom segment patterns
```php
FFMpeg::open('video.mp4')
    ->exportForHLS()
    ->useSegmentFilenameGenerator(function ($name, $format, $key, callable $segments, callable $playlist) {
        $segments("{$name}-{$format->getKiloBitrate()}-{$key}-%03d.ts");
        $playlist("{$name}-{$format->getKiloBitrate()}-{$key}.m3u8");
    });
```

## Encrypted HLS

### Static encryption key
```php
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSExporter;

$encryptionKey = HLSExporter::generateEncryptionKey();

FFMpeg::open('video.mp4')
    ->exportForHLS()
    ->withEncryptionKey($encryptionKey)
    ->addFormat($lowBitrate)
    ->addFormat($midBitrate)
    ->addFormat($highBitrate)
    ->save('adaptive.m3u8');
```

### Rotating encryption keys
```php
FFMpeg::open('video.mp4')
    ->exportForHLS()
    ->withRotatingEncryptionKey(function ($filename, $contents) {
        $videoId = 1;

        Storage::disk('secrets')->put($videoId . '/' . $filename, $contents);

        // or store in database
        DB::table('hls_secrets')->insert([
            'video_id' => $videoId,
            'filename' => $filename,
            'contents' => $contents,
        ]);
    })
    ->addFormat($lowBitrate)
    ->addFormat($midBitrate)
    ->addFormat($highBitrate)
    ->save('adaptive.m3u8');
```

Set the number of segments per key (default is 1):

```php
FFMpeg::open('video.mp4')
    ->exportForHLS()
    ->withRotatingEncryptionKey($callable, 10);
```

For slow filesystems, configure a faster temporary storage for encryption keys:

```php
// config/laravel-ffmpeg.php
'temporary_files_encrypted_hls' => '/dev/shm',
```

## Protecting HLS Encryption Keys with DynamicHLSPlaylist

Use `DynamicHLSPlaylist` to serve playlists with modified key URLs, enabling authentication and authorization:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

Route::get('/video/secret/{key}', function ($key) {
    return Storage::disk('secrets')->download($key);
})->name('video.key');

Route::get('/video/{playlist}', function ($playlist) {
    return FFMpeg::dynamicHLSPlaylist()
        ->fromDisk('public')
        ->open($playlist)
        ->setKeyUrlResolver(function ($key) {
            return route('video.key', ['key' => $key]);
        })
        ->setMediaUrlResolver(function ($mediaFilename) {
            return Storage::disk('public')->url($mediaFilename);
        })
        ->setPlaylistUrlResolver(function ($playlistFilename) {
            return route('video.playlist', ['playlist' => $playlistFilename]);
        });
})->name('video.playlist');
```

Use `route('video.playlist', ['playlist' => 'adaptive.m3u8'])` instead of a direct storage URL to serve the playlist. The `DynamicHLSPlaylist` implements `Illuminate\Contracts\Support\Responsable`, so you can return it directly from a controller or route.

## Process Output

Analyze raw FFmpeg output, for example with the `volumedetect` filter:

```php
$processOutput = FFMpeg::open('video.mp4')
    ->export()
    ->addFilter(['-filter:a', 'volumedetect', '-f', 'null'])
    ->getProcessOutput();

$processOutput->all();     // all output lines
$processOutput->errors();  // error lines
$processOutput->out();     // standard output lines
```

## Advanced Usage

### Access underlying PHP-FFMpeg objects
```php
$media = FFMpeg::fromDisk('videos')->open('video.mp4');

// Dynamic method calls are proxied to the underlying Media object
$codec = $media->getVideoStream()->get('codec_name');

// Direct access to the underlying FFMpeg\Media\MediaTypeInterface
$baseMedia = $media();
```

### Progress listener decorator (experimental)
```php
use FFMpeg\Format\ProgressListener\AbstractProgressListener;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\ProgressListenerDecorator;

$format = new \FFMpeg\Format\Video\X264;
$decoratedFormat = ProgressListenerDecorator::decorate($format);

FFMpeg::open('video.mp4')
    ->export()
    ->inFormat($decoratedFormat)
    ->onProgress(function () use ($decoratedFormat) {
        $listeners = $decoratedFormat->getListeners();
        $listener = $listeners[0];

        $listener->getCurrentPass();
        $listener->getTotalPass();
        $listener->getCurrentTime();
    })
    ->save('new_video.mp4');
```

### Modify the FFmpeg command before saving (experimental)
```php
FFMpeg::open('video.mp4')
    ->export()
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->beforeSaving(function ($commands) {
        $commands[] = '-hello';
        return $commands;
    })
    ->save('output.mp4');
```

Note: `beforeSaving` does not work with concatenation or frame exports.

## Common Formats

Audio formats from PHP-FFMpeg:
- `\FFMpeg\Format\Audio\Aac`
- `\FFMpeg\Format\Audio\Flac`
- `\FFMpeg\Format\Audio\Mp3`
- `\FFMpeg\Format\Audio\Wav`
- `\FFMpeg\Format\Audio\Vorbis`

Video formats from PHP-FFMpeg:
- `\FFMpeg\Format\Video\X264`
- `\FFMpeg\Format\Video\WMV`
- `\FFMpeg\Format\Video\WebM`
- `\FFMpeg\Format\Video\Ogg`

Package-provided formats:
- `ProtoneMedia\LaravelFFMpeg\FFMpeg\CopyFormat` — copy streams without re-encoding
- `ProtoneMedia\LaravelFFMpeg\FFMpeg\CopyVideoFormat` — copy only video streams

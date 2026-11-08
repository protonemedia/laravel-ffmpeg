# Laravel FFMpeg Reference

Complete reference for `pbmedia/laravel-ffmpeg`. Full documentation: https://github.com/protonemedia/laravel-ffmpeg

## Opening Media

### From a Laravel disk
```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::fromDisk('local')
    ->open('video.mp4');

// Multiple files
FFMpeg::fromDisk('local')
    ->open(['video1.mp4', 'video2.mp4']);
```

### From a URL
```php
FFMpeg::openUrl('https://example.com/video.mp4');

// With custom headers
FFMpeg::openUrl('https://example.com/video.mp4', [
    'Authorization' => 'Bearer token',
    'Referer' => 'https://example.com',
]);
```

### From multiple disks
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->fromDisk('audio')
    ->open('narration.mp3');
```

## Format Conversion

### Video conversion
```php
use FFMpeg\Format\Video\X264;
use FFMpeg\Format\Video\WMV;
use FFMpeg\Format\Video\WebM;

FFMpeg::fromDisk('uploads')
    ->open('input.mp4')
    ->export()
    ->toDisk('converted')
    ->inFormat(new X264)
    ->save('output.mp4');
```

### Audio conversion
```php
use FFMpeg\Format\Audio\Aac;
use FFMpeg\Format\Audio\Mp3;

FFMpeg::fromDisk('uploads')
    ->open('input.wav')
    ->export()
    ->toDisk('converted')
    ->inFormat(new Aac)
    ->save('output.aac');
```

### Copy format (no transcoding)
```php
use ProtoneMedia\LaravelFFMpeg\FFMpeg\CopyFormat;

FFMpeg::fromDisk('uploads')
    ->open('input.mp4')
    ->export()
    ->inFormat(new CopyFormat)
    ->save('output.mkv');
```

### Setting bitrate and codec options
```php
$format = new X264;
$format->setKiloBitrate(1500);           // video bitrate
$format->setAudioKiloBitrate(128);        // audio bitrate
$format->setAudioChannels(2);             // stereo

FFMpeg::fromDisk('uploads')
    ->open('input.mp4')
    ->export()
    ->inFormat($format)
    ->save('output.mp4');
```

## Filters

### Resize
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->export()
    ->inFormat(new X264)
    ->resize(640, 480)
    ->save('resized.mp4');

// With mode: fit, inset, width, height
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->export()
    ->inFormat(new X264)
    ->resize(640, 480, 'inset')
    ->save('resized.mp4');
```

### Custom filter callback
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->export()
    ->inFormat(new X264)
    ->addFilter(function ($filters) {
        $filters->resize(new \FFMpeg\Coordinate\Dimension(640, 480));
    })
    ->save('filtered.mp4');
```

### Custom filter with raw parameters
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->export()
    ->inFormat(new X264)
    ->addFilter(['-vf', 'transpose=1'])   // rotate 90 degrees
    ->save('rotated.mp4');
```

## Watermarking

### Basic watermark
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->export()
    ->addWatermark(function ($watermark) {
        $watermark->fromDisk('local')
            ->open('logo.png')
            ->right(10)
            ->bottom(10);
    })
    ->inFormat(new X264)
    ->save('watermarked.mp4');
```

### Watermark positioning
```php
// Absolute positioning
$watermark->top(25)->left(25);
$watermark->bottom(25)->right(25);

// Alignment-based positioning
$watermark->horizontalAlignment(WatermarkFactory::LEFT, 10);
$watermark->horizontalAlignment(WatermarkFactory::CENTER);
$watermark->horizontalAlignment(WatermarkFactory::RIGHT, 10);
$watermark->verticalAlignment(WatermarkFactory::TOP, 10);
$watermark->verticalAlignment(WatermarkFactory::CENTER);
$watermark->verticalAlignment(WatermarkFactory::BOTTOM, 10);
```

### Watermark from URL
```php
$watermark->openUrl('https://example.com/logo.png');
```

### Watermark image manipulation (requires spatie/image)
```php
$watermark->fromDisk('local')
    ->open('logo.png')
    ->width(100)
    ->height(100)
    ->greyscale()
    ->right(10)
    ->bottom(10);
```

## Frame Extraction

### Single frame at time
```php
// From seconds
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->getFrameFromSeconds(10)
    ->export()
    ->toDisk('thumbnails')
    ->save('thumb.png');

// From timecode string
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->getFrameFromString('00:00:13.37')
    ->export()
    ->save('thumb.png');

// From TimeCode object
use FFMpeg\Coordinate\TimeCode;

FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->getFrameFromTimecode(TimeCode::fromSeconds(10))
    ->export()
    ->save('thumb.png');
```

### Get frame contents without saving
```php
$contents = FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->getFrameFromSeconds(10)
    ->export()
    ->getFrameContents();
```

### Export multiple frames by interval
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->exportFramesByInterval(5)       // every 5 seconds
    ->toDisk('frames')
    ->save('frame_%05d.png');

// With custom dimensions and quality
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->exportFramesByInterval(5, 320, 240, 2)
    ->save('frame_%05d.jpg');
```

### Export frames by count
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->exportFramesByAmount(10)        // 10 evenly spaced frames
    ->toDisk('frames')
    ->save('frame_%05d.png');

// With custom dimensions
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->exportFramesByAmount(10, 320, 240)
    ->save('frame_%05d.jpg');
```

## Tile / Sprite Generation

Create mosaic grids of video frames for preview thumbnails:

```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->exportTile(function ($tile) {
        $tile->interval(10)            // frame every 10 seconds
            ->scale(160, 90)           // each frame size
            ->grid(5, 5)              // 5x5 grid
            ->padding(2)              // 2px padding
            ->margin(4)              // 4px margin
            ->quality(10);            // JPEG quality (2-31, lower = better)
    })
    ->save('tiles_%05d.jpg');
```

### Generate WebVTT file for video player previews
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->exportTile(function ($tile) {
        $tile->interval(10)
            ->scale(160, 90)
            ->grid(5, 5)
            ->generateVTT('thumbnails/previews.vtt');
    })
    ->toDisk('public')
    ->save('thumbnails/tiles_%05d.jpg');
```

## HLS Adaptive Streaming

### Basic HLS export
```php
use FFMpeg\Format\Video\X264;

FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->exportForHLS()
    ->setSegmentLength(10)
    ->setKeyFrameInterval(48)
    ->addFormat((new X264)->setKiloBitrate(1000))
    ->addFormat((new X264)->setKiloBitrate(500))
    ->save('stream.m3u8');
```

### HLS with per-format filters
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->exportForHLS()
    ->addFormat((new X264)->setKiloBitrate(1000), function ($media) {
        $media->resize(1280, 720);
    })
    ->addFormat((new X264)->setKiloBitrate(500), function ($media) {
        $media->resize(640, 360);
    })
    ->save('adaptive.m3u8');
```

### Keep all audio streams
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->exportForHLS()
    ->keepAllAudioStreams()
    ->addFormat((new X264)->setKiloBitrate(1000))
    ->save('stream.m3u8');
```

### HLS with encryption
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->exportForHLS()
    ->withEncryptionKey(file_get_contents('/path/to/key'))
    ->addFormat((new X264)->setKiloBitrate(1000))
    ->save('encrypted.m3u8');
```

### HLS with rotating encryption keys
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->exportForHLS()
    ->withRotatingEncryptionKey(function ($filename, $contents) {
        // Store each key securely
        Storage::disk('secrets')->put($filename, $contents);
    }, 10)   // rotate every 10 segments
    ->addFormat((new X264)->setKiloBitrate(1000))
    ->save('encrypted.m3u8');
```

### Custom segment filenames
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->exportForHLS()
    ->useSegmentFilenameGenerator(function ($name, $format, $key, callable $segments, callable $playlist) {
        $segments("{$name}-{$format}-{$key}-%05d.ts");
        $playlist("{$name}-{$format}-{$key}.m3u8");
    })
    ->addFormat((new X264)->setKiloBitrate(1000))
    ->save('master.m3u8');
```

### Dynamic HLS Playlist (with auth)
Serve HLS playlists dynamically to control access:

```php
// In a route or controller
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

Route::get('/video/{playlist}', function ($playlist) {
    return FFMpeg::dynamicHLSPlaylist()
        ->fromDisk('encrypted-hls')
        ->open($playlist)
        ->setKeyUrlResolver(function ($key) {
            return route('hls.key', ['key' => $key]);
        })
        ->setMediaUrlResolver(function ($mediaFilename) {
            return Storage::disk('encrypted-hls')->temporaryUrl($mediaFilename, now()->addMinutes(30));
        })
        ->setPlaylistUrlResolver(function ($playlistFilename) {
            return route('hls.playlist', ['playlist' => $playlistFilename]);
        });
})->name('hls.playlist');
```

## Concatenation

### Without transcoding (same codec/resolution)
```php
FFMpeg::fromDisk('uploads')
    ->open(['clip1.mp4', 'clip2.mp4', 'clip3.mp4'])
    ->export()
    ->concatWithoutTranscoding()
    ->save('merged.mp4');
```

### With transcoding (different codecs/resolutions)
```php
FFMpeg::fromDisk('uploads')
    ->open(['clip1.mp4', 'clip2.avi'])
    ->export()
    ->inFormat(new X264)
    ->concatWithTranscoding($hasVideo = true, $hasAudio = true)
    ->save('merged.mp4');
```

## Image Sequence / Timelapse

Create a video from a series of images:

```php
FFMpeg::fromDisk('frames')
    ->open('img_%04d.png')
    ->export()
    ->asTimelapseWithFramerate(24)
    ->inFormat(new X264)
    ->save('timelapse.mp4');
```

## Advanced Media (Complex Filters)

Use complex filters for multi-input scenarios like stacking or overlaying:

```php
FFMpeg::fromDisk('uploads')
    ->open(['top.mp4', 'bottom.mp4'])
    ->export()
    ->addFormatOutputMapping(new X264, Media::make('local', 'stacked.mp4'), ['0:v', '1:v'])
    ->addFilter('[0:v][1:v]', 'vstack', '[v]')
    ->save();
```

## Progress Monitoring

### On export
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->export()
    ->inFormat(new X264)
    ->onProgress(function ($percentage) {
        echo "{$percentage}% transcoded";
    })
    ->save('output.mp4');
```

### On HLS export
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->exportForHLS()
    ->onProgress(function ($percentage, $remaining, $rate) {
        echo "{$percentage}% done, {$remaining} seconds remaining";
    })
    ->addFormat((new X264)->setKiloBitrate(1000))
    ->save('stream.m3u8');
```

## Debugging

### Get the FFmpeg command without executing
```php
$command = FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->export()
    ->inFormat(new X264)
    ->getCommand('output.mp4');
```

### Dump and die
```php
FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->export()
    ->inFormat(new X264)
    ->dd('output.mp4');
```

### Get process output after export
```php
$processOutput = FFMpeg::fromDisk('uploads')
    ->open('video.mp4')
    ->export()
    ->inFormat(new X264)
    ->save('output.mp4')
    ->getProcessOutput();

$processOutput->all();       // all output lines
$processOutput->errors();    // error lines
$processOutput->out();       // stdout lines
```

## Cleanup

Remove temporary files created during processing:

```php
FFMpeg::cleanupTemporaryFiles();
```

## Configuration

Key `config/laravel-ffmpeg.php` options:

```php
return [
    'ffmpeg' => [
        'binaries' => env('FFMPEG_BINARIES', 'ffmpeg'),    // FFmpeg binary path
        'threads'  => 12,                                    // encoding threads (false to disable)
    ],
    'ffprobe' => [
        'binaries' => env('FFPROBE_BINARIES', 'ffprobe'),  // FFProbe binary path
    ],
    'timeout' => 3600,                   // process timeout in seconds
    'log_channel' => env('LOG_CHANNEL', 'stack'),  // log channel (false to disable)
    'temporary_files_root' => env('FFMPEG_TEMPORARY_FILES_ROOT', sys_get_temp_dir()),
    'temporary_files_encrypted_hls' => env('FFMPEG_TEMPORARY_ENCRYPTED_HLS', env('FFMPEG_TEMPORARY_FILES_ROOT', sys_get_temp_dir())),
];
```

### Publish config
```bash
php artisan vendor:publish --provider="ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider"
```

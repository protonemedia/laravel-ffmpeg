# Laravel FFMpeg

[![Latest Version on Packagist](https://img.shields.io/packagist/v/pbmedia/laravel-ffmpeg.svg?style=flat-square)](https://packagist.org/packages/pbmedia/laravel-ffmpeg)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
![run-tests](https://github.com/protonemedia/laravel-ffmpeg/workflows/run-tests/badge.svg)
[![Quality Score](https://img.shields.io/scrutinizer/g/protonemedia/laravel-ffmpeg.svg?style=flat-square)](https://scrutinizer-ci.com/g/protonemedia/laravel-ffmpeg)
[![Total Downloads](https://img.shields.io/packagist/dt/pbmedia/laravel-ffmpeg.svg?style=flat-square)](https://packagist.org/packages/pbmedia/laravel-ffmpeg)

This package provides an integration with FFmpeg for Laravel 6.0 and higher. [Laravel's Filesystem](http://laravel.com/docs/7.0/filesystem) handles the storage of the files.

## Features
* Super easy wrapper around [PHP-FFMpeg](https://github.com/PHP-FFMpeg/PHP-FFMpeg), including support for filters and other advanced features.
* Integration with [Laravel's Filesystem](http://laravel.com/docs/7.0/filesystem), [configuration system](https://laravel.com/docs/7.0/configuration) and [logging handling](https://laravel.com/docs/7.0/errors).
* Compatible with Laravel 6.0 and higher, support for [Package Discovery](https://laravel.com/docs/7.0/packages#package-discovery).
* Built-in support for HLS.
* Built-in support for encrypted HLS (AES-128) and rotating keys (optional).
* Built-in support for concatenation, multiple inputs/outputs, image sequences (timelapse), complex filters (and mapping), frame/thumbnail exports.
* Built-in support for watermarks (positioning and manipulation).
* PHP 7.3 and higher.
* Lots of integration tests, GitHub Actions with both Ubuntu and Windows.

## Support

We proudly support the community by developing Laravel packages and giving them away for free. Keeping track of issues and pull requests takes time, but we're happy to help! If this package saves you time or if you're relying on it professionally, please consider [supporting the maintenance and development](https://github.com/sponsors/pascalbaljet).

## Installation

You can install the package via composer:

```bash
composer require pbmedia/laravel-ffmpeg
```

Add the Service Provider and Facade to your ```app.php``` config file if you're not using Package Discovery.

```php
// config/app.php

'providers' => [
    ...
    ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider::class,
    ...
];

'aliases' => [
    ...
    'FFMpeg' => ProtoneMedia\LaravelFFMpeg\Support\FFMpeg::class
    ...
];
```

Publish the config file using the artisan CLI tool:

```bash
php artisan vendor:publish --provider="ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider"
```

## Upgrading to v7

* The namespace has changed to `ProtoneMedia\LaravelFFMpeg`, the facade has been renamed to `ProtoneMedia\LaravelFFMpeg\Support\FFMpeg`, and the Service Provider has been renamed to `ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider`.
* Chaining exports are still supported, but you have to reapply filters for each export.
* HLS playlists now include bitrate, framerate and resolution data. The segments also use a new naming pattern ([read more](#using-custom-segment-patterns)). Please verify your exports still work in your player.
* HLS export is now executed as *one* job instead of exporting each format/stream separately. This uses FFMpeg's `map` and `filter_complex` features. It might be sufficient to replace all calls to `addFilter` with `addLegacyFilter`, but some filters should be migrated manually. Please read the [documentation on HLS](#hls) to find out more about adding filters.

## Usage

Convert an audio or video file:

```php
FFMpeg::fromDisk('songs')
    ->open('yesterday.mp3')
    ->export()
    ->toDisk('converted_songs')
    ->inFormat(new \FFMpeg\Format\Audio\Aac)
    ->save('yesterday.aac');
```

Instead of the ```fromDisk()``` method you can also use the ```fromFilesystem()``` method, where ```$filesystem``` is an instance of ```Illuminate\Contracts\Filesystem\Filesystem```.

```php
$media = FFMpeg::fromFilesystem($filesystem)->open('yesterday.mp3');
```

### Progress monitoring

You can monitor the transcoding progress. Use the ```onProgress``` method to provide a callback, which gives you the completed percentage. In previous versions of this package you had to pass the callback to the format object.

```php
FFMpeg::open('steve_howe.mp4')
    ->export()
    ->onProgress(function ($percentage) {
        echo "{$percentage}% transcoded";
    });
```

As of version 7.0, the callback also exposes `$remaining` (in seconds) and `$rate`:

```php
FFMpeg::open('steve_howe.mp4')
    ->export()
    ->onProgress(function ($percentage, $remaining, $rate) {
        echo "{$remaining} seconds left at rate: {$rate}";
    });
```

### Open files from the web

You can open files from the web by using the `openUrl` method. You can specify custom HTTP headers with the optional second parameter:

```php
FFMpeg::openUrl('https://videocoursebuilder.com/lesson-1.mp4');

FFMpeg::openUrl('https://videocoursebuilder.com/lesson-2.mp4', [
    'Authorization' => 'Basic YWRtaW46MTIzNA==',
]);
```

### Handling exceptions

When the encoding fails, a `ProtoneMedia\LaravelFFMpeg\Exporters\EncodingException` shall be thrown, which extends the underlying `FFMpeg\Exception\RuntimeException` class. This class has two methods that can help you identify the problem. Using the `getCommand` method, you can get the executed command with all parameters. The `getErrorOutput` method gives you a full output log.

For legacy reasons, the message of the exception is always *Encoding failed*. You can replace this message with a more informative message by updating the `set_command_and_error_output_on_exception` configuration key to `true`.

```php
try {
    FFMpeg::open('yesterday.mp3')
        ->export()
        ->inFormat(new Aac)
        ->save('yesterday.aac');
} catch (EncodingException $exception) {
    $command = $exception->getCommand();
    $errorLog = $exception->getErrorOutput();
}
```

### Filters

You can add filters through a ```Closure``` or by using PHP-FFMpeg's Filter objects:

```php
use FFMpeg\Filters\Video\VideoFilters;

FFMpeg::fromDisk('videos')
    ->open('steve_howe.mp4')
    ->addFilter(function (VideoFilters $filters) {
        $filters->resize(new \FFMpeg\Coordinate\Dimension(640, 480));
    })
    ->export()
    ->toDisk('converted_videos')
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->save('small_steve.mkv');

// or

$start = \FFMpeg\Coordinate\TimeCode::fromSeconds(5)
$clipFilter = new \FFMpeg\Filters\Video\ClipFilter($start);

FFMpeg::fromDisk('videos')
    ->open('steve_howe.mp4')
    ->addFilter($clipFilter)
    ->export()
    ->toDisk('converted_videos')
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->save('short_steve.mkv');
```

As of version 7.0, you can also call the `addFilter` method *after* the `export` method:

```php
use FFMpeg\Filters\Video\VideoFilters;

FFMpeg::fromDisk('videos')
    ->open('steve_howe.mp4')
    ->export()
    ->toDisk('converted_videos')
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->addFilter(function (VideoFilters $filters) {
        $filters->resize(new \FFMpeg\Coordinate\Dimension(640, 480));
    })
    ->save('small_steve.mkv');
```

#### Resizing

Since resizing is a common operation, we've added a dedicated method for it:

```php
FFMpeg::open('steve_howe.mp4')
    ->export()
    ->resize(640, 480);
```
The first argument is the width, and the second argument the height. The optional third argument is the mode. You can choose between `fit` (default), `inset`, `width` or `height`. The optional fourth argument is a boolean whether or not to force the use of standards ratios. You can find about these modes in the `FFMpeg\Filters\Video\ResizeFilter` class.

### Custom filters

Sometimes you don't want to use the built-in filters. You can apply your own filter by providing a set of options. This can be an array or multiple strings as arguments:

```php
FFMpeg::fromDisk('videos')
    ->open('steve_howe.mp4')
    ->addFilter(['-itsoffset', 1]);

// or

FFMpeg::fromDisk('videos')
    ->open('steve_howe.mp4')
    ->addFilter('-itsoffset', 1);
```

### Watermark filter

As of version 7.3, you can easily add a watermark using the `addWatermark` method. With the `WatermarkFactory`, you can open your watermark file from a specific disk, just like opening an audio or video file. When you discard the `fromDisk` method, it uses the default disk specified in the `filesystems.php` configuration file.

After opening your watermark file, you can position it with the `top`, `right`, `bottom`, and `left` methods. The first parameter of these methods is the offset, which is optional and can be negative.

```php
use ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFactory;

FFMpeg::fromDisk('videos')
    ->open('steve_howe.mp4')
    ->addWatermark(function(WatermarkFactory $watermark) {
        $watermark->fromDisk('local')
            ->open('logo.png')
            ->right(25)
            ->bottom(25);
    });
```

Instead of using the position methods, you can also use the `horizontalAlignment` and `verticalAlignment` methods.

For horizontal alignment, you can use the `WatermarkFactory::LEFT`, `WatermarkFactory::CENTER` and `WatermarkFactory::RIGHT` constants. For vertical alignment, you can use the `WatermarkFactory::TOP`, `WatermarkFactory::CENTER` and `WatermarkFactory::BOTTOM` constants. Both methods take an optional second parameter, which is the offset.

```php
FFMpeg::open('steve_howe.mp4')
    ->addWatermark(function(WatermarkFactory $watermark) {
        $watermark->open('logo.png')
            ->horizontalAlignment(WatermarkFactory::LEFT, 25)
            ->verticalAlignment(WatermarkFactory::TOP, 25);
    });
```

The `WatermarkFactory` also supports opening files from the web with the `openUrl` method. It supports custom HTTP headers as well.

```php
FFMpeg::open('steve_howe.mp4')
    ->addWatermark(function(WatermarkFactory $watermark) {
        $watermark->openUrl('https://videocoursebuilder.com/logo.png');

        // or

        $watermark->openUrl('https://videocoursebuilder.com/logo.png', [
            'Authorization' => 'Basic YWRtaW46MTIzNA==',
        ]);
    });
```

If you want more control over the GET request, you can pass in an optional third parameter, which gives you the Curl resource.

```php
$watermark->openUrl('https://videocoursebuilder.com/logo.png', [
    'Authorization' => 'Basic YWRtaW46MTIzNA==',
], function($curl) {
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
});
```

#### Watermark manipulation

This package can manipulate watermarks by using [Spatie's Image package](https://github.com/spatie/image). To get started, install the package with Composer:

```bash
composer require spatie/image
```

Now you can chain one more manipulation methods on the `WatermarkFactory` instance:

```php
FFMpeg::open('steve_howe.mp4')
    ->addWatermark(function(WatermarkFactory $watermark) {
        $watermark->open('logo.png')
            ->right(25)
            ->bottom(25)
            ->width(100)
            ->height(100)
            ->greyscale();
    });
```

Check out [the documentation](https://spatie.be/docs/image/v1/introduction) for all available methods.

### Export without transcoding

This package comes with a `ProtoneMedia\LaravelFFMpeg\FFMpeg\CopyFormat` class that allows you to export a file without transcoding the streams. You might want to use this to use another container:

```php
use ProtoneMedia\LaravelFFMpeg\FFMpeg\CopyFormat;

FFMpeg::open('video.mp4')
    ->export()
    ->inFormat(new CopyFormat)
    ->save('video.mkv');
```

### Chain multiple convertions

```php
// The 'fromDisk()' method is not required, the file will now
// be opened from the default 'disk', as specified in
// the config file.

FFMpeg::open('my_movie.mov')

    // export to FTP, converted in WMV
    ->export()
    ->toDisk('ftp')
    ->inFormat(new \FFMpeg\Format\Video\WMV)
    ->save('my_movie.wmv')

    // export to Amazon S3, converted in X264
    ->export()
    ->toDisk('s3')
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->save('my_movie.mkv');

    // you could even discard the 'toDisk()' method,
    // now the converted file will be saved to
    // the same disk as the source!
    ->export()
    ->inFormat(new FFMpeg\Format\Video\WebM)
    ->save('my_movie.webm')

    // optionally you could set the visibility
    // of the exported file
    ->export()
    ->inFormat(new FFMpeg\Format\Video\WebM)
    ->withVisibility('public')
    ->save('my_movie.webm')
```

### Create a frame from a video

```php
FFMpeg::fromDisk('videos')
    ->open('steve_howe.mp4')
    ->getFrameFromSeconds(10)
    ->export()
    ->toDisk('thumnails')
    ->save('FrameAt10sec.png');

// Instead of the 'getFrameFromSeconds()' method, you could
// also use the 'getFrameFromString()' or the
// 'getFrameFromTimecode()' methods:

$media = FFMpeg::open('steve_howe.mp4');
$frame = $media->getFrameFromString('00:00:13.37');

// or

$timecode = new FFMpeg\Coordinate\TimeCode(...);
$frame = $media->getFrameFromTimecode($timecode);
```

You can also get the raw contents of the frame instead of saving it to the filesystem:

```php
$contents = FFMpeg::open('video.mp4')
    ->getFrameFromSeconds(2)
    ->export()
    ->getFrameContents();
```

### Multiple exports using loops

Chaining multiple conversions works because the `save` method of the `MediaExporter` returns a fresh instance of the `MediaOpener`. You can use this to loop through items, for example, to exports multiple frames from one video:

```php
$mediaOpener = FFMpeg::open('video.mp4');

foreach ([5, 15, 25] as $key => $seconds) {
    $mediaOpener = $mediaOpener->getFrameFromSeconds($seconds)
        ->export()
        ->save("thumb_{$key}.png");
}
```

The `MediaOpener` comes with an `each` method as well. The example above could be refactored like this:

```php
FFMpeg::open('video.mp4')->each([5, 15, 25], function ($ffmpeg, $seconds, $key) {
    $ffmpeg->getFrameFromSeconds($seconds)->export()->save("thumb_{$key}.png");
});
```

### Create a timelapse

You can create a timelapse from a sequence of images by using the `asTimelapseWithFramerate` method on the exporter

```php
FFMpeg::open('feature_%04d.png')
    ->export()
    ->asTimelapseWithFramerate(1)
    ->inFormat(new X264)
    ->save('timelapse.mp4');
```

### Multiple inputs

As of version 7.0 you can open multiple inputs, even from different disks. This uses FFMpeg's `map` and `filter_complex` features. You can open multiple files by chaining the `open` method of by using an array. You can mix inputs from different disks.

```php
FFMpeg::open('video1.mp4')->open('video2.mp4');

FFMpeg::open(['video1.mp4', 'video2.mp4']);

FFMpeg::fromDisk('uploads')
    ->open('video1.mp4')
    ->fromDisk('archive')
    ->open('video2.mp4');
```

When you open multiple inputs, you have to add mappings so FFMpeg knows how to route them. This package provides a `addFormatOutputMapping` method, which takes three parameters: the format, the output, and the output labels of the `-filter_complex` part.

The output (2nd argument) should be an instanceof `ProtoneMedia\LaravelFFMpeg\Filesystem\Media`. You can instantiate with the `make` method, call it with the name of the disk and the path (see example).

Check out this example, which maps separate video and audio inputs into one output.

```php
FFMpeg::fromDisk('local')
    ->open(['video.mp4', 'audio.m4a'])
    ->export()
    ->addFormatOutputMapping(new X264, Media::make('local', 'new_video.mp4'), ['0:v', '1:a'])
    ->save();
```

This is an example [from the underlying library](https://github.com/PHP-FFMpeg/PHP-FFMpeg#base-usage):

```php
// This code takes 2 input videos, stacks they horizontally in 1 output video and
// adds to this new video the audio from the first video. (It is impossible
// with a simple filter graph that has only 1 input and only 1 output).

FFMpeg::fromDisk('local')
    ->open(['video.mp4', 'video2.mp4'])
    ->export()
    ->addFilter('[0:v][1:v]', 'hstack', '[v]')  // $in, $parameters, $out
    ->addFormatOutputMapping(new X264, Media::make('local', 'stacked_video.mp4'), ['0:a', '[v]'])
    ->save();
```

Just like single inputs, you can also pass a callback to the `addFilter` method. This will give you an instance of `\FFMpeg\Filters\AdvancedMedia\ComplexFilters`:

```php
use FFMpeg\Filters\AdvancedMedia\ComplexFilters;

FFMpeg::open(['video.mp4', 'video2.mp4'])
    ->export()
    ->addFilter(function(ComplexFilters $filters) {
        // $filters->watermark(...);
    });
```

Opening files from the web works similarly. You can pass an array of URLs to the `openUrl` method, optionally with custom HTTP headers.

```php
FFMpeg::openUrl([
    'https://videocoursebuilder.com/lesson-3.mp4',
    'https://videocoursebuilder.com/lesson-4.mp4',
]);

FFMpeg::openUrl([
    'https://videocoursebuilder.com/lesson-3.mp4',
    'https://videocoursebuilder.com/lesson-4.mp4',
], [
    'Authorization' => 'Basic YWRtaW46MTIzNA==',
]);
```

If you want to use another set of HTTP headers for each URL, you can chain the `openUrl` method:

```php
FFMpeg::openUrl('https://videocoursebuilder.com/lesson-5.mp4', [
    'Authorization' => 'Basic YWRtaW46MTIzNA==',
])->openUrl('https://videocoursebuilder.com/lesson-6.mp4', [
    'Authorization' => 'Basic bmltZGE6NDMyMQ==',
]);
```

### Concat files without transcoding

```php
FFMpeg::fromDisk('local')
    ->open(['video.mp4', 'video2.mp4'])
    ->export()
    ->concatWithoutTranscoding()
    ->save('concat.mp4');
```

### Concat files with transcoding

```php
FFMpeg::fromDisk('local')
    ->open(['video.mp4', 'video2.mp4'])
    ->export()
    ->inFormat(new X264)
    ->concatWithTranscoding($hasVideo = true, $hasAudio = true)
    ->save('concat.mp4');
```

### Determinate duration

With the ```Media``` class you can determinate the duration of a file:

```php
$media = FFMpeg::open('wwdc_2006.mp4');

$durationInSeconds = $media->getDurationInSeconds(); // returns an int
$durationInMiliseconds = $media->getDurationInMiliseconds(); // returns a float
```

### Handling remote disks

When opening or saving files from or to a remote disk, temporary files will be created on your server. After you're done exporting or processing these files, you could clean them up by calling the ```cleanupTemporaryFiles()``` method:

```php
FFMpeg::cleanupTemporaryFiles();
```

By default, the root of the temporary directories is evaluated by PHP's `sys_get_temp_dir()` method, but you can modify it by setting the `temporary_files_root` configuration key to a custom path.

## HLS

You can create a M3U8 playlist to do [HLS](https://en.wikipedia.org/wiki/HTTP_Live_Streaming).

```php
$lowBitrate = (new X264)->setKiloBitrate(250);
$midBitrate = (new X264)->setKiloBitrate(500);
$highBitrate = (new X264)->setKiloBitrate(1000);

FFMpeg::fromDisk('videos')
    ->open('steve_howe.mp4')
    ->exportForHLS()
    ->setSegmentLength(10) // optional
    ->setKeyFrameInterval(48) // optional
    ->addFormat($lowBitrate)
    ->addFormat($midBitrate)
    ->addFormat($highBitrate)
    ->save('adaptive_steve.m3u8');
```

The ```addFormat``` method of the HLS exporter takes an optional second parameter which can be a callback method. This allows you to add different filters per format. First, check out the *Multiple inputs* section to understand how complex filters are handled.

You can use the `addFilter` method to add a complex filter (see `$lowBitrate` example). Since the `scale` filter is used a lot, there is a helper method (see `$midBitrate` example). You can also use a callable to get access to the `ComplexFilters` instance. The package provides the `$in` and `$out` arguments so you don't have to worry about it (see `$highBitrate` example).

As of version 7.0, HLS export is built using FFMpeg's `map` and `filter_complex` features. This is a breaking change from previous versions which performed a single export for each format. If you're upgrading, replace the `addFilter` calls with `addLegacyFilter` calls and verify the result (see `$superBitrate` example). Not all filters will work this way and some need to be upgraded manually.

```php
$lowBitrate = (new X264)->setKiloBitrate(250);
$midBitrate = (new X264)->setKiloBitrate(500);
$highBitrate = (new X264)->setKiloBitrate(1000);
$superBitrate = (new X264)->setKiloBitrate(1500);

FFMpeg::open('steve_howe.mp4')
    ->exportForHLS()
    ->addFormat($lowBitrate, function($media) {
        $media->addFilter('scale=640:480');
    })
    ->addFormat($midBitrate, function($media) {
        $media->scale(960, 720);
    })
    ->addFormat($highBitrate, function ($media) {
        $media->addFilter(function ($filters, $in, $out) {
            $filters->custom($in, 'scale=1920:1200', $out); // $in, $parameters, $out
        });
    })
    ->addFormat($superBitrate, function($media) {
        $media->addLegacyFilter(function ($filters) {
            $filters->resize(new \FFMpeg\Coordinate\Dimension(2560, 1920));
        });
    })
    ->save('adaptive_steve.m3u8');
```

### Using custom segment patterns

You can use a custom pattern to name the segments and playlists. The `useSegmentFilenameGenerator` gives you 5 arguments. The first, second and third argument provide information about the basename of the export, the format of the current iteration and the key of the current iteration. The fourth argument is a callback you should call with your *segments* pattern. The fifth argument is a callback you should call with your *playlist* pattern. Note that this is not the name of the primary playlist, but the name of the playlist of each format.

```php
FFMpeg::fromDisk('videos')
    ->open('steve_howe.mp4')
    ->exportForHLS()
    ->useSegmentFilenameGenerator(function ($name, $format, $key, callable $segments, callable $playlist) {
        $segments("{$name}-{$format}-{$key}-%03d.ts");
        $playlist("{$name}-{$format}-{$key}.m3u8");
    });
```

### Encrypted HLS

As of version 7.5, you can encrypt each HLS segment using AES-128 encryption. To do this, call the `withEncryptionKey` method on the HLS exporter with a key. We provide a `generateEncryptionKey` helper method on the `HLSExporter` class to generate a key. Make sure you store the key well, as the exported result is worthless without the key. By default, the filename of the key is `secret.key`, but you can change that with the optional second parameter of the `withEncryptionKey` method.

```php
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSExporter;

$encryptionKey = HLSExporter::generateEncryptionKey();

FFMpeg::open('steve_howe.mp4')
    ->exportForHLS()
    ->withEncryptionKey($encryptionKey)
    ->addFormat($lowBitrate)
    ->addFormat($midBitrate)
    ->addFormat($highBitrate)
    ->save('adaptive_steve.m3u8');
```

To secure your HLS export even further, you can rotate the key on each exported segment. By doing so, it will generate multiple keys that you'll need to store. Use the `withRotatingEncryptionKey` method to enable this feature and provide a callback that implements the storage of the keys.

```php
FFMpeg::open('steve_howe.mp4')
    ->exportForHLS()
    ->withRotatingEncryptionKey(function ($filename, $contents) {
        $videoId = 1;

        // use this callback to store the encryption keys

        Storage::disk('secrets')->put($videoId . '/' . $filename, $contents);

        // or...

        DB::table('hls_secrets')->insert([
            'video_id' => $videoId,
            'filename' => $filename,
            'contents' => $contents,
        ]);
    })
    ->addFormat($lowBitrate)
    ->addFormat($midBitrate)
    ->addFormat($highBitrate)
    ->save('adaptive_steve.m3u8');
```

The `withRotatingEncryptionKey` method has an optional second argument to set the number of segments that use the same key. This defaults to `1`.

```php
FFMpeg::open('steve_howe.mp4')
    ->exportForHLS()
    ->withRotatingEncryptionKey($callable, 10);
```

### Protecting your HLS encryption keys

To make working with encrypted HLS even better, we've added a `DynamicHLSPlaylist` class that modifies playlists on-the-fly and specifically for your application. This way, you can add your authentication and authorization logic. As we're using a plain Laravel controller, you can use features like [Gates](https://laravel.com/docs/master/authorization#gates) and [Middleware](https://laravel.com/docs/master/middleware#introduction).

In this example, we've saved the HLS export to the `public` disk, and we've stored the encryption keys to the `secrets` disk, which isn't publicly available. As the browser can't access the encryption keys, it won't play the video. Each playlist has paths to the encryption keys, and we need to modify those paths to point to an accessible endpoint.

This implementation consists of two routes. One that responses with an encryption key and one that responses with a modified playlist. The first route (`video.key`) is relatively simple, and this is where you should add your additional logic.

The second route (`video.playlist`) uses the `DynamicHLSPlaylist` class. Call the `dynamicHLSPlaylist` method on the `FFMpeg` facade, and similar to opening media files, you can open a playlist utilizing the `fromDisk` and `open` methods. Then you must provide three callbacks. Each of them gives you a relative path and expects a full path in return. As the `DynamicHLSPlaylist` class implements the `Illuminate\Contracts\Support\Responsable` interface, you can return the instance.

The first callback (KeyUrlResolver) gives you the relative path to an encryption key. The second callback (MediaUrlResolver) gives you the relative path to a media segment (.ts files). The third callback (PlaylistUrlResolver) gives you the relative path to a playlist.

Now instead of using `Storage::disk('public')->url('adaptive_steve.m3u8')` to get the full url to your primary playlist, you can use `route('video.playlist', ['playlist' => 'adaptive_steve.m3u8'])`. The `DynamicHLSPlaylist` class takes care of all the paths and urls.

```php
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

### Live Coding Session

Here you can find a Live Coding Session about HLS encryption:

[https://www.youtube.com/watch?v=WlbzWoAcez4](https://www.youtube.com/watch?v=WlbzWoAcez4)

## Process Output

You can get the raw process output by calling the `getProcessOutput` method. Though the use-case is limited, you can use it to analyze a file (for example, with the `volumedetect` filter). It returns a `ProtoneMedia\LaravelFFMpeg\Support\ProcessOutput` class that has three methods: `all`, `errors` and `output`. Each method returns an array with the corresponding lines.

```php
$processOutput = FFMpeg::open('video.mp4')
    ->export()
    ->addFilter(['-filter:a', 'volumedetect', '-f', 'null'])
    ->getProcessOutput();

$processOutput->all();
$processOutput->errors();
$processOutput->out();
```

## Advanced

The Media object you get when you 'open' a file, actually holds the Media object that belongs to the [underlying driver](https://github.com/PHP-FFMpeg/PHP-FFMpeg). It handles dynamic method calls as you can see [here](https://github.com/pascalbaljetmedia/laravel-ffmpeg/blob/master/src/Media.php#L114-L117). This way all methods of the underlying driver are still available to you.

```php
// This gives you an instance of ProtoneMedia\LaravelFFMpeg\MediaOpener
$media = FFMpeg::fromDisk('videos')->open('video.mp4');

// The 'getStreams' method will be called on the underlying Media object since
// it doesn't exists on this object.
$codec = $media->getStreams()->first()->get('codec_name');
```

If you want direct access to the underlying object, call the object as a function (invoke):

```php
// This gives you an instance of ProtoneMedia\LaravelFFMpeg\MediaOpener
$media = FFMpeg::fromDisk('videos')->open('video.mp4');

// This gives you an instance of FFMpeg\Media\MediaTypeInterface
$baseMedia = $media();
```

## Experimental

The [progress listener](#progress-monitoring) exposes the transcoded percentage, but the underlying package also has an internal `AbstractProgressListener` that exposes the current pass and the current time. Though the use-case is limited, you might want to get access to this listener instance. You can do this by decorating the format with the `ProgressListenerDecorator`. This feature is highly experimental, so be sure the test this thoroughly before using it in production.

```php
use FFMpeg\Format\ProgressListener\AbstractProgressListener;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\ProgressListenerDecorator;

$format = new \FFMpeg\Format\Video\X264;
$decoratedFormat = ProgressListenerDecorator::decorate($format);

FFMpeg::open('video.mp4')
    ->export()
    ->inFormat($decoratedFormat)
    ->onProgress(function () use ($decoratedFormat) {
        $listeners = $decoratedFormat->getListeners();  // array of listeners

        $listener = $listeners[0];  // instance of AbstractProgressListener

        $listener->getCurrentPass();
        $listener->getTotalPass();
        $listener->getCurrentTime();
    })
    ->save('new_video.mp4');
```

Since we can't get rid of some of the underlying options, you can interact with the final FFmpeg command by adding a callback to the exporter. You can add one or more callbacks by using the `beforeSaving` method:

```php
FFMpeg::open('video.mp4')
    ->export()
    ->inFormat(new X264)
    ->beforeSaving(function ($commands) {
        $commands[] = '-hello';

        return $commands;
    })
    ->save('concat.mp4');
```

*Note: this does not work with concatenation and frame exports*

## Example app

Here's a blog post that will help you get started with this package:

https://protone.media/en/blog/how-to-use-ffmpeg-in-your-laravel-projects

## Using Video.js to play HLS in any browser

Here's a 20-minute overview how to get started with Video.js. It covers including Video.js from a CDN, importing it as an ES6 module with Laravel Mix (Webpack) and building a reusable Vue.js component.

[https://www.youtube.com/watch?v=nA1Jy8BPjys](https://www.youtube.com/watch?v=nA1Jy8BPjys)

## Wiki

* [Custom filters](https://github.com/protonemedia/laravel-ffmpeg/wiki/Custom-filters)
* [FFmpeg failed to execute command](https://github.com/protonemedia/laravel-ffmpeg/wiki/FFmpeg-failed-to-execute-command)
* [Get the dimensions of a Video file](https://github.com/protonemedia/laravel-ffmpeg/wiki/Get-the-dimensions-of-a-Video-file)
* [Monitoring the transcoding progress](https://github.com/protonemedia/laravel-ffmpeg/wiki/Monitoring-the-transcoding-progress)
* [Unable to load FFProbe](https://github.com/protonemedia/laravel-ffmpeg/wiki/Unable-to-load-FFProbe)

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information about what has changed recently.

## Testing

```bash
$ composer test
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Other Laravel packages

* [`Laravel Analytics Event Tracking`](https://github.com/protonemedia/laravel-analytics-event-tracking): Laravel package to easily send events to Google Analytics.
* [`Laravel Blade On Demand`](https://github.com/protonemedia/laravel-blade-on-demand): Laravel package to compile Blade templates in memory.
* [`Laravel Cross Eloquent Search`](https://github.com/protonemedia/laravel-cross-eloquent-search): Laravel package to search through multiple Eloquent models.
* [`Laravel Eloquent Scope as Select`](https://github.com/protonemedia/laravel-eloquent-scope-as-select): Stop duplicating your Eloquent query scopes and constraints in PHP. This package lets you re-use your query scopes and constraints by adding them as a subquery.
* [`Laravel Eloquent Where Not`](https://github.com/protonemedia/laravel-eloquent-where-not): This Laravel package allows you to flip/invert an Eloquent scope, or really any query constraint.
* [`Laravel Form Components`](https://github.com/protonemedia/laravel-form-components): Blade components to rapidly build forms with Tailwind CSS Custom Forms and Bootstrap 4. Supports validation, model binding, default values, translations, includes default vendor styling and fully customizable!
* [`Laravel Mixins`](https://github.com/protonemedia/laravel-mixins): A collection of Laravel goodies.
* [`Laravel Paddle`](https://github.com/protonemedia/laravel-paddle): Paddle.com API integration for Laravel with support for webhooks/events.
* [`Laravel Verify New Email`](https://github.com/protonemedia/laravel-verify-new-email): This package adds support for verifying new email addresses: when a user updates its email address, it won't replace the old one until the new one is verified.
* [`Laravel WebDAV`](https://github.com/protonemedia/laravel-webdav): WebDAV driver for Laravel's Filesystem.

## Security

If you discover any security-related issues, please email code@protone.media instead of using the issue tracker. Please do not email any questions, open an issue if you have a question.

## Credits

- [Pascal Baljet](https://github.com/pascalbaljet)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

# Laravel FFMpeg

[![Latest Version on Packagist](https://img.shields.io/packagist/v/pbmedia/laravel-ffmpeg.svg?style=flat-square)](https://packagist.org/packages/pbmedia/laravel-ffmpeg)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Build Status](https://img.shields.io/travis/pascalbaljetmedia/laravel-ffmpeg/master.svg?style=flat-square)](https://travis-ci.org/pascalbaljetmedia/laravel-ffmpeg)
[![Quality Score](https://img.shields.io/scrutinizer/g/pascalbaljetmedia/laravel-ffmpeg.svg?style=flat-square)](https://scrutinizer-ci.com/g/pascalbaljetmedia/laravel-ffmpeg)
[![Total Downloads](https://img.shields.io/packagist/dt/pbmedia/laravel-ffmpeg.svg?style=flat-square)](https://packagist.org/packages/pbmedia/laravel-ffmpeg)

> # :warning: Under Development :construction:

This package provides an integration with FFmpeg for Laravel 5.1 and higher. The storage of the files is handled by [Laravel's Filesystem](http://laravel.com/docs/5.1/filesystem). Currenly only supports local drivers!

## Installation

You can install the package via composer:

``` bash
composer require pbmedia/laravel-ffmpeg
```

## Usage

Convert an audio track:

``` php
FFMpeg::fromDisk('songs')
    ->open('yesterday.mp3')
    ->export()
    ->toDisk('converted_songs')
    ->inFormat(new \FFMpeg\Format\Audio\Aac)
    ->save('yesterday.aac');
```

Create a frame from a video:

``` php
FFMpeg::fromDisk('videos')
    ->open('steve_howe.mp4')
    ->getFrameFromSeconds(10)
    ->export()
    ->toDisk('thumnails')
    ->save('FrameAt10sec.png');
```

## To do
* Writing test for exporting frames

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Testing

``` bash
$ composer test
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email pascal@pascalbaljetmedia.com instead of using the issue tracker.

## Credits

- [Pascal Baljet](https://github.com/pascalbaljet)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

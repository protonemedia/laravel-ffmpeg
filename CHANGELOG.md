# Changelog

All Notable changes to `pbmedia/laravel-ffmpeg` will be documented in this file

## 7.8.1 - 2022-02-10

### Added

-   Support for opening uploaded files

## 7.8.0 - 2022-02-09

### Added

-   Support for the [modernized php-ffmpeg release](https://github.com/PHP-FFMpeg/PHP-FFMpeg/releases/tag/v1.0.0)

## 7.7.3 - 2022-02-07

### Added

-   Abilty to disable the threads filter from the config (thanks @ibrainventures)

## 7.7.2 - 2021-01-12

### Fixed

-   Fix for getting the duration of a file opened with the `openUrl` method.

## 7.7.1 - 2021-01-03

### Fixed

-   Fix for missing `$remaining` and `$rate` values when using the progress handler on exports with multiple inputs/outputs.

## 7.7.0 - 2021-12-31

### Added

-   Added Tile filter and factory
-   Support for exporting frames using the Tile filter
-   Bugfix for exporting loops using external disks

## 7.6.0 - 2021-12-20

### Added

-   Support for PHP 8.1

### Removed

-   Support for PHP 7.3
-   Support for Laravel 6 and 7

## 7.5.12 - 2021-07-05

### Added

-   Fix for passing additional parameters to a format when using HLS exports

## 7.5.11 - 2021-04-25

### Added

-   Added `CopyVideoFormat` format class

## 7.5.10 - 2021-03-31

### Added

-   Add ability to disable -b:v (thanks @marbocub)

## 7.5.9 - 2021-03-19

### Fixed

-   Prevent duplicate encryption key listeners

## 7.5.8 - 2021-03-17

### Fixed

-   Bugfix for creating temporary directories on Windows
-   Bugfix for HLS exports with custom framerate

## 7.5.7 - 2021-03-08

### Fixed

-   Prevent HLS key rotation on non-rotating exports (thanks @marbocub)

## 7.5.6 - 2021-03-03

### Fixed

-   Bugfix for HLS exports to S3 disks (thanks @chistel)
-   Prevent duplicate progress handler when using loops

## 7.5.5 - 2021-01-18

### Added

-   Added `beforeSaving` method to add callbacks

## 7.5.4 - 2021-01-07

### Added

-   Added fourth optional argument to the resize method whether or not to force the use of standards ratios
-   Improved docs
-   Small refactor

## 7.5.3 - 2021-01-02

### Added

-   Support for custom encryption filename when using non-rotating keys

## 7.5.2 - 2021-01-02

### Added

-   Support for setting a custom path for temporary directories
-   GitHub Actions now runs on Windows in addition to Ubuntu

### Fixed

-   HLS Encryption I/O improvements
-   Path normalization on Windows, which solves common problems with HLS and watermarks
-   Some refactors and documentation improvements

## 7.5.1 - 2020-12-24

### Added

-   Support for codec in HLS playlist
-   Fixed bitrate bug in HLS playlist

## 7.5.0 - 2020-12-22

### Added

-   Support for PHP 8.0.
-   Encrypted HLS.
-   New `getProcessOutput` method to analyze media.
-   Support for dynamic HLS playlists.

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Support for PHP 7.2

## 7.4.1 - 2020-10-26

### Added

-   Better exceptions
-   dd() improvements

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

## 7.4.0 - 2020-10-25

### Added

-   Watermark manipulations
-   Dump and die
-   Resize filter shortcut
-   HLS export with multiple filters per format

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

## 7.3.0 - 2020-10-16

### Added

-   Built-in support for watermarks.

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

## 7.2.0 - 2020-09-17

### Added

-   Support for inputs from the web

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

## 7.1.0 - 2020-09-04

### Added

-   Support for Laravel 8.0

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

## 7.0.5 - 2020-07-04

### Added

-   Added `CopyFormat` to export a file without transcoding.

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

## 7.0.4 - 2020-06-03

### Added

-   Added an `each` method to the `MediaOpener`

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

## 7.0.3 - 2020-06-01

### Added

-   Added a `MediaOpenerFactory` to support pre v7.0 facade

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

## 7.0.2 - 2020-06-01

### Added

-   Nothing

### Deprecated

-   Nothing

### Fixed

-   Audio bugfix for HLS exports with filters

### Removed

-   Nothing


## 7.0.1 - 2020-05-28

### Added

-   Nothing

### Deprecated

-   Nothing

### Fixed

-   Fixed HLS playlist creation on Windows hosts

### Removed

-   Nothing

## 7.0.0 - 2020-05-26

### Added

-   Support for both Laravel 6.0 and Laravel 7.0
-   Support for multiple inputs/outputs including mapping and complex filters
-   Concatenation with transcoding
-   Concatenation without transcoding
-   Support for image sequences (timelapse)
-   Bitrate, framerate and resolution data in HLS playlist
-   Execute one job for HLS export instead of one job for each format
-   Custom playlist/segment naming pattern for HLS export
-   Support for disabling log

### Deprecated

-   Nothing

### Fixed

-   Improved progress monitoring
-   Improved handling of remote filesystems

### Removed

-   Nothing

## 6.0.0 - 2020-03-03

### Added

-   Support for Laravel 7.0

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Support for Laravel 6.0

## 5.0.0 - 2019-09-03

### Added

-   Support for Laravel 6.0

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Support for PHP 7.1
-   Support for Laravel 5.8

### Security

-   Nothing

## 4.1.0 - 2019-08-28

### Added

-   Nothing

### Deprecated

-   Nothing

### Fixed

-   Lower memory usage when opening remote files

### Removed

-   Nothing

### Security

-   Nothing

## 4.0.1 - 2019-06-17

### Added

-   Nothing

### Deprecated

-   Nothing

### Fixed

-   Support for php-ffmpeg 0.14

### Removed

-   Nothing

### Security

-   Nothing

## 4.0.0 - 2019-02-26

### Added

-   Support for Laravel 5.8.
-   Support for PHP 7.3.

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

### Security

-   Nothing

## 3.0.0 - 2018-09-03

### Added

-   Support for Laravel 5.7.

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

### Security

-   Nothing

## 2.1.0 - 2018-04-10

### Added

-   Option to disable format sorting in HLS exporter.

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

### Security

-   Nothing

## 2.0.1 - 2018-02-30

### Added

-   Nothing

### Deprecated

-   Nothing

### Fixed

-   Symfony 4.0 workaround

### Removed

-   Nothing

### Security

-   Nothing

## 2.0.0 - 2018-02-19

### Added

-   Support for Laravel 5.6.

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Support for Laravel 5.5 and earlier.

### Security

-   Nothing

## 1.3.0 - 2017-11-13

### Added

-   Support for monitoring the progress of a HLS Export.

### Deprecated

-   Nothing

### Fixed

-   Some refactoring

### Removed

-   Nothing

### Security

-   Nothing

## 1.2.0 - 2017-11-13

### Added

-   Support for adding filters per format in the `HLSPlaylistExporter` class by giving access to the `Media` object through a callback.

### Deprecated

-   Nothing

### Fixed

-   Some refactoring

### Removed

-   Nothing

### Security

-   Nothing

## 1.1.12 - 2017-09-05

### Added

-   Support for Package Discovery in Laravel 5.5.

### Deprecated

-   Nothing

### Fixed

-   Some refactoring

### Removed

-   Nothing

### Security

-   Nothing

## 1.1.11 - 2017-08-31

### Added

-   Added `withVisibility` method to the MediaExporter

### Deprecated

-   Nothing

### Fixed

-   Some refactoring

### Removed

-   Nothing

### Security

-   Nothing

## 1.1.10 - 2017-08-16

### Added

-   Added `getFirstStream()` method to the `Media` class

### Deprecated

-   Nothing

### Fixed

-   Some refactoring

### Removed

-   Nothing

### Security

-   Nothing

## 1.1.9 - 2017-07-10

### Added

-   Support for custom filters in the `Media` class

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

### Security

-   Nothing

## 1.1.8 - 2017-05-22

### Added

-   `getDurationInMiliseconds` method in Media class

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

### Security

-   Nothing

## 1.1.7 - 2017-05-22

### Added

-   `fromFilesystem` method in FFMpeg class

### Deprecated

-   Nothing

### Fixed

-   Fallback to format properties in `getDurationInSeconds` method (Media class)

### Removed

-   Nothing

### Security

-   Nothing

## 1.1.6 - 2017-05-11

### Added

-   `cleanupTemporaryFiles` method

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

### Security

-   Nothing

## 1.1.5 - 2017-03-20

### Added

-   Nothing

### Deprecated

-   Nothing

### Fixed

-   Bugfix for saving on remote disks

### Removed

-   Nothing

### Security

-   Nothing

## 1.1.4 - 2017-01-29

### Added

-   Nothing

### Deprecated

-   Nothing

### Fixed

-   Support for php-ffmpeg 0.8.0

### Removed

-   Nothing

### Security

-   Nothing

## 1.1.3 - 2017-01-05

### Added

-   Nothing

### Deprecated

-   Nothing

### Fixed

-   HLS segment playlists output path is now relative

### Removed

-   Nothing

### Security

-   Nothing

## 1.1.2 - 2017-01-05

### Added

-   Added 'getDurationInSeconds' method to Media class.

### Deprecated

-   Nothing

### Fixed

-   Nothing

### Removed

-   Nothing

### Security

-   Nothing

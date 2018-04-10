# Changelog

All Notable changes to `pbmedia/laravel-ffmpeg` will be documented in this file

## 2.1.0 - 2018-04-10

### Added
- Option to disable format sorting in HLS exporter.

### Deprecated
- Nothing

### Fixed
- Nothing

### Removed
- Nothing

### Security
- Nothing

## 2.0.1 - 2018-02-30

### Added
- Nothing

### Deprecated
- Nothing

### Fixed
- Symfony 4.0 workaround

### Removed
- Nothing

### Security
- Nothing

## 2.0.0 - 2018-02-19

### Added
- Support for Laravel 5.6.

### Deprecated
- Nothing

### Fixed
- Nothing

### Removed
- Support for Laravel 5.5 and earlier.

### Security
- Nothing

## 1.3.0 - 2017-11-13

### Added
- Support for monitoring the progress of a HLS Export.

### Deprecated
- Nothing

### Fixed
- Some refactoring

### Removed
- Nothing

### Security
- Nothing

## 1.2.0 - 2017-11-13

### Added
- Support for adding filters per format in the ```HLSPlaylistExporter``` class by giving access to the ```Media``` object through a callback.

### Deprecated
- Nothing

### Fixed
- Some refactoring

### Removed
- Nothing

### Security
- Nothing

## 1.1.12 - 2017-09-05

### Added
- Support for Package Discovery in Laravel 5.5.

### Deprecated
- Nothing

### Fixed
- Some refactoring

### Removed
- Nothing

### Security
- Nothing

## 1.1.11 - 2017-08-31

### Added
- Added ```withVisibility``` method to the MediaExporter

### Deprecated
- Nothing

### Fixed
- Some refactoring

### Removed
- Nothing

### Security
- Nothing

## 1.1.10 - 2017-08-16

### Added
- Added ```getFirstStream()``` method to the ```Media``` class

### Deprecated
- Nothing

### Fixed
- Some refactoring

### Removed
- Nothing

### Security
- Nothing

## 1.1.9 - 2017-07-10

### Added
- Support for custom filters in the ```Media``` class

### Deprecated
- Nothing

### Fixed
- Nothing

### Removed
- Nothing

### Security
- Nothing

## 1.1.8 - 2017-05-22

### Added
- ```getDurationInMiliseconds``` method in Media class

### Deprecated
- Nothing

### Fixed
- Nothing

### Removed
- Nothing

### Security
- Nothing

## 1.1.7 - 2017-05-22

### Added
- ```fromFilesystem``` method in FFMpeg class

### Deprecated
- Nothing

### Fixed
- Fallback to format properties in ```getDurationInSeconds``` method (Media class)

### Removed
- Nothing

### Security
- Nothing

## 1.1.6 - 2017-05-11

### Added
- ```cleanupTemporaryFiles``` method

### Deprecated
- Nothing

### Fixed
- Nothing

### Removed
- Nothing

### Security
- Nothing

## 1.1.5 - 2017-03-20

### Added
- Nothing

### Deprecated
- Nothing

### Fixed
- Bugfix for saving on remote disks

### Removed
- Nothing

### Security
- Nothing

## 1.1.4 - 2017-01-29

### Added
- Nothing

### Deprecated
- Nothing

### Fixed
- Support for php-ffmpeg 0.8.0

### Removed
- Nothing

### Security
- Nothing

## 1.1.3 - 2017-01-05

### Added
- Nothing

### Deprecated
- Nothing

### Fixed
- HLS segment playlists output path is now relative

### Removed
- Nothing

### Security
- Nothing


## 1.1.2 - 2017-01-05

### Added
- Added 'getDurationInSeconds' method to Media class.

### Deprecated
- Nothing

### Fixed
- Nothing

### Removed
- Nothing

### Security
- Nothing

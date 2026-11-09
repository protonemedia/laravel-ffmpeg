<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPlaylist;

/**
 * Opens media from a selected disk.
 */
class InputSelector
{
    /**
     * Create a new input selector instance.
     */
    public function __construct(protected readonly string|Filesystem $disk) {}

    /**
     * Get the HLS playlist at the given path for playback.
     */
    public function playlist(string $path): HlsPlaylist
    {
        return new HlsPlaylist($this->disk, $path);
    }

    /**
     * Open the media file at the given disk-relative path, or a local file.
     */
    public function open(string|File|UploadedFile $path, array $inputOptions = []): Media
    {
        return new Media($this->disk, $path, $inputOptions);
    }

    /**
     * Open the media file at the given HTTP(S) URL.
     */
    public function openUrl(string $url, array $headers = [], bool $download = false, array $inputOptions = []): Media
    {
        return new Media($this->disk, new UrlInput($url, $headers, $download), $inputOptions);
    }

    /**
     * Open a numbered sequence of images as a video input.
     */
    public function openSequence(string $pattern, int $count, float|string $framerate, int $start = 1): Media
    {
        return new Media($this->disk, new SequenceInput($this->disk, $pattern, $count, $framerate, $start));
    }
}

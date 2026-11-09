<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPlaylist;

class InputSelector
{
    public function __construct(private readonly string|Filesystem $disk) {}

    public function playlist(string $path): HlsPlaylist
    {
        return new HlsPlaylist($this->disk, $path);
    }

    public function open(string|File|UploadedFile $path, array $inputOptions = []): Media
    {
        return new Media($this->disk, $path, $inputOptions);
    }

    public function openUrl(string $url, array $headers = [], bool $download = false, array $inputOptions = []): Media
    {
        return new Media($this->disk, new UrlInput($url, $headers, $download), $inputOptions);
    }

    public function openSequence(string $pattern, int $count, float|string $framerate, int $start = 1): Media
    {
        return new Media($this->disk, new SequenceInput($this->disk, $pattern, $count, $framerate, $start));
    }
}

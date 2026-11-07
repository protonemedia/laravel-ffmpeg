<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;

class InputSelector
{
    public function __construct(private readonly string|Filesystem $disk) {}

    public function open(string|File|UploadedFile $path, array $inputOptions = []): Media
    {
        return new Media($this->disk, $path, $inputOptions);
    }

    public function openUrl(string $url, array $headers = [], bool $download = false, array $inputOptions = []): Media
    {
        return new Media($this->disk, new UrlInput($url, $headers, $download), $inputOptions);
    }
}

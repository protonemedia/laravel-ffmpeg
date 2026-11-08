<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use ProtoneMedia\LaravelFFMpeg\InputSelector;
use ProtoneMedia\LaravelFFMpeg\Media;

class MediaOpenerFactory
{
    public function open(string|File|UploadedFile $path, array $inputOptions = []): Media
    {
        return $this->disk()->open($path, $inputOptions);
    }

    public function openUrl(string $url, array $headers = [], bool $download = false, array $inputOptions = []): Media
    {
        return $this->disk()->openUrl($url, $headers, $download, $inputOptions);
    }

    public function disk(string|Filesystem|null $disk = null): InputSelector
    {
        return new InputSelector($disk ?? config('filesystems.default'));
    }
}

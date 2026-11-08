<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use ProtoneMedia\LaravelFFMpeg\InputSelector;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\PendingHlsMerge;
use ProtoneMedia\LaravelFFMpeg\RtspCamera;

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

    public function openRtsp(string $url, string $transport = 'tcp', float $timeout = 15): RtspCamera
    {
        return new RtspCamera($url, $transport, $timeout);
    }

    public function mergeHls(array $presentations): PendingHlsMerge
    {
        return new PendingHlsMerge($presentations);
    }

    public function disk(string|Filesystem|null $disk = null): InputSelector
    {
        return new InputSelector($disk ?? config('filesystems.default'));
    }
}

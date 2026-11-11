<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use ProtoneMedia\LaravelFFMpeg\Capabilities;
use ProtoneMedia\LaravelFFMpeg\InputSelector;
use ProtoneMedia\LaravelFFMpeg\LiveHlsSource;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\PendingHlsMerge;
use ProtoneMedia\LaravelFFMpeg\PendingLiveHls;
use ProtoneMedia\LaravelFFMpeg\RtspCamera;
use SensitiveParameter;

/**
 * The entry point behind the FFMpeg facade.
 */
class MediaOpenerFactory
{
    /**
     * Get a fresh snapshot of the compiled-in FFmpeg capabilities.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities;
    }

    /**
     * Open a media file on the default disk.
     */
    public function open(string|File|UploadedFile $path, array $inputOptions = []): Media
    {
        return $this->disk()->open($path, $inputOptions);
    }

    /**
     * Open a media file at an HTTP(S) URL.
     */
    public function openUrl(string $url, array $headers = [], bool $download = false, array $inputOptions = []): Media
    {
        return $this->disk()->openUrl($url, $headers, $download, $inputOptions);
    }

    /**
     * Open an RTSP camera for a snapshot.
     */
    public function openRtsp(string $url, string $transport = 'tcp', float $timeout = 15): RtspCamera
    {
        return new RtspCamera($url, $transport, $timeout);
    }

    /**
     * Begin publishing a live HLS stream from the given URL.
     */
    public function liveHls(
        #[SensitiveParameter] string $url,
        float $segmentDuration = 6,
        int $window = 6,
    ): PendingLiveHls {
        return new PendingLiveHls(new LiveHlsSource($url), config('filesystems.default'), $segmentDuration, $window);
    }

    /**
     * Begin composing completed HLS presentations into one presentation.
     */
    public function mergeHls(array $presentations): PendingHlsMerge
    {
        return new PendingHlsMerge($presentations);
    }

    /**
     * Select the disk to open media from.
     */
    public function disk(string|Filesystem|null $disk = null): InputSelector
    {
        return new InputSelector($disk ?? config('filesystems.default'));
    }
}

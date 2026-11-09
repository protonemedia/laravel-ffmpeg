<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Support;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ProtoneMedia\LaravelFFMpeg\Capabilities capabilities()
 * @method static \ProtoneMedia\LaravelFFMpeg\InputSelector disk(string|\Illuminate\Contracts\Filesystem\Filesystem|null $disk = null)
 * @method static \ProtoneMedia\LaravelFFMpeg\Media open(string|\Illuminate\Http\File|\Illuminate\Http\UploadedFile $path, array $inputOptions = [])
 * @method static \ProtoneMedia\LaravelFFMpeg\Media openUrl(string $url, array $headers = [], bool $download = false, array $inputOptions = [])
 * @method static \ProtoneMedia\LaravelFFMpeg\RtspCamera openRtsp(string $url, string $transport = 'tcp', float $timeout = 15)
 * @method static \ProtoneMedia\LaravelFFMpeg\PendingLiveHls liveHls(string $url, float $segmentDuration = 6, int $window = 6)
 * @method static \ProtoneMedia\LaravelFFMpeg\PendingHlsMerge mergeHls(array $presentations)
 *
 * @see MediaOpenerFactory
 */
class FFMpeg extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'laravel-ffmpeg';
    }
}

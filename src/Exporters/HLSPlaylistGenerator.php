<?php

namespace Pbmedia\LaravelFFMpeg\Exporters;

use FFMpeg\FFProbe\DataMapping\Stream;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Pbmedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;
use Pbmedia\LaravelFFMpeg\MediaOpener;

class HLSPlaylistGenerator implements PlaylistGenerator
{
    const PLAYLIST_START = '#EXTM3U';
    const PLAYLIST_END   = '#EXT-X-ENDLIST';

    private function getPathOfFirstSegment(Media $playlistMedia): string
    {
        $playlistContent = file_get_contents($playlistMedia->getLocalPath());

        return Collection::make(explode(PHP_EOL, $playlistContent))->first(function ($line) {
            return !Str::startsWith($line, '#') && Str::endsWith($line, '.ts');
        });
    }

    private function getVideoStream(MediaOpener $media): ?Stream
    {
        return Arr::first($media->getStreams(), function (Stream $stream) {
            return $stream->get('codec_type') === 'video';
        });
    }

    private function getBandwidth(MediaOpener $media)
    {
        return $media->getFormat()->get('bit_rate');
    }

    private function getResolution(MediaOpener $media)
    {
        $videoStream = $this->getVideoStream($media);

        $width  = optional($videoStream)->get('width');
        $height = optional($videoStream)->get('height');

        return ($width && $height) ? "{$width}x{$height}" : null;
    }

    private function getFrameRate(MediaOpener $media)
    {
        $mediaStream = $this->getVideoStream($media);

        $frameRate = trim(Str::before(optional($mediaStream)->get('avg_frame_rate'), "/1"));

        if (Str::endsWith($frameRate, '/0')) {
            return null;
        }

        return $frameRate ? number_format($frameRate, 3, '.', '') : null;
    }

    public function get(array $playlistMedia, PHPFFMpeg $driver): string
    {
        return Collection::make($playlistMedia)->map(function (Media $playlistMedia) use ($driver) {
            $media = (new MediaOpener($playlistMedia->getDisk(), $driver->fresh()))->open(
                $playlistMedia->getDirectory() . $this->getPathOfFirstSegment($playlistMedia)
            );

            $streamInfo = [
                "#EXT-X-STREAM-INF:BANDWIDTH={$this->getBandwidth($media)}",
                "RESOLUTION={$this->getResolution($media)}",
            ];

            if ($frameRate = $this->getFrameRate($media)) {
                $streamInfo[] = "FRAME-RATE={$frameRate}";
            }

            return [implode(',', $streamInfo), $playlistMedia->getFilename()];
        })->collapse()
            ->prepend(static::PLAYLIST_START)
            ->push(static::PLAYLIST_END)
            ->implode(PHP_EOL);
    }
}

<?php

namespace Pbmedia\LaravelFFMpeg\Exporters;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Pbmedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;
use Pbmedia\LaravelFFMpeg\MediaOpener;

class HLSPlaylistGenerator implements PlaylistGenerator
{
    public function get(array $playlistMedia, PHPFFMpeg $driver): string
    {
        return Collection::make($playlistMedia)->map(function (Media $playlistMedia) use ($driver) {
            $playlistContent = file_get_contents($playlistMedia->getLocalPath());

            $file = Collection::make(explode(PHP_EOL, $playlistContent))->first(function ($line) {
                return substr($line, 0, 1) !== '#' && Str::endsWith($line, '.ts');
            });

            $media = (new MediaOpener($playlistMedia->getDisk(), $driver->fresh()))->open($file);

            $mediaStream = $media->getStreams()[0];
            $mediaFormat = $media->getFormat();
            $frameRate = trim(Str::before($mediaStream->get('avg_frame_rate'), "/1"));

            $info = "#EXT-X-STREAM-INF:BANDWIDTH={$mediaFormat->get('bit_rate')}";
            $info .= ",RESOLUTION={$mediaStream->get('width')}x{$mediaStream->get('height')}";

            if ($frameRate) {
                $frameRate = number_format($frameRate, 3, '.', '');
                $info .= ",FRAME-RATE={$frameRate}";
            }

            return [$info, $playlistMedia->getPath()];
        })->collapse()->prepend('#EXTM3U')->push('#EXT-X-ENDLIST')->implode(PHP_EOL);
    }
}

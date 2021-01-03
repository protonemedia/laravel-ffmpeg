<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Http\DynamicHLSPlaylist;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;

class HLSPlaylistGenerator implements PlaylistGenerator
{
    const PLAYLIST_START = '#EXTM3U';
    const PLAYLIST_END   = '#EXT-X-ENDLIST';

    private function getFrameRate(MediaOpener $media)
    {
        $mediaStream = $media->getVideoStream();

        $frameRate = trim(Str::before(optional($mediaStream)->get('avg_frame_rate'), "/1"));

        if (!$frameRate || Str::endsWith($frameRate, '/0')) {
            return null;
        }

        return $frameRate ? number_format($frameRate, 3, '.', '') : null;
    }

    private function getStreamInfoLine(Media $playlistMedia, string $key): string
    {
        $segmentPlaylist = $playlistMedia->getDisk()->get(
            $playlistMedia->getDirectory() . HLSExporter::generateTemporarySegmentPlaylistFilename($key, $playlistMedia)
        );

        $lines = DynamicHLSPlaylist::parseLines($segmentPlaylist)->filter();

        return $lines->get($lines->search($playlistMedia->getFilename()) - 1);
    }

    public function get(array $playlistMedia, PHPFFMpeg $driver): string
    {
        return Collection::make($playlistMedia)->map(function (Media $playlistMedia, $key) use ($driver) {
            $streamInfoLine = $this->getStreamInfoLine($playlistMedia, $key);

            $media = (new MediaOpener($playlistMedia->getDisk(), $driver))
                ->openWithInputOptions($playlistMedia->getPath(), ['-allowed_extensions', 'ALL']);

            if ($frameRate = $this->getFrameRate($media)) {
                $streamInfoLine .= ",FRAME-RATE={$frameRate}";
            }

            return [$streamInfoLine, $playlistMedia->getFilename()];
        })->collapse()
            ->prepend(static::PLAYLIST_START)
            ->push(static::PLAYLIST_END)
            ->implode(PHP_EOL);
    }
}

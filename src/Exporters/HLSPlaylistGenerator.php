<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;

class HLSPlaylistGenerator implements PlaylistGenerator
{
    const PLAYLIST_START = '#EXTM3U';
    const PLAYLIST_END   = '#EXT-X-ENDLIST';

    private static function getLinesOfPlaylist(Media $playlistMedia): Collection
    {
        $playlistContent = file_get_contents($playlistMedia->getLocalPath());

        $lines = preg_split('/\n|\r\n?/', $playlistContent);

        return Collection::make($lines);
    }

    private function getEncryptionInputArguments(Media $playlistMedia): array
    {
        $keyLine = static::getLinesOfPlaylist($playlistMedia)->first(function ($line) {
            return Str::startsWith($line, '#EXT-X-KEY:METHOD=AES-128');
        });

        if (!$keyLine) {
            return [];
        }

        $key = Str::before(Str::after($keyLine, '#EXT-X-KEY:METHOD=AES-128,URI="'), '",IV=');
        $iv  = Str::after($keyLine, '",IV=');

        return ['-key', $key, '-iv', $iv];
    }

    private function getPathOfFirstSegment(Media $playlistMedia): string
    {
        return static::getLinesOfPlaylist($playlistMedia)->first(function ($line) {
            return !Str::startsWith($line, '#') && Str::endsWith($line, '.ts');
        });
    }

    private function getBandwidth(MediaOpener $media)
    {
        dd($media->getVideoStream());
        return $media->getFormat()->get('bit_rate');
    }

    private function getResolution(MediaOpener $media)
    {
        try {
            $dimensions = optional($media->getVideoStream())->getDimensions();
        } catch (Exception $exception) {
            return null;
        }

        return "{$dimensions->getWidth()}x{$dimensions->getHeight()}";
    }

    private function getFrameRate(MediaOpener $media)
    {
        $mediaStream = $media->getVideoStream();

        $frameRate = trim(Str::before(optional($mediaStream)->get('avg_frame_rate'), "/1"));

        if (!$frameRate || Str::endsWith($frameRate, '/0')) {
            return null;
        }

        return $frameRate ? number_format($frameRate, 3, '.', '') : null;
    }

    public function get(array $playlistMedia, PHPFFMpeg $driver): string
    {
        return Collection::make($playlistMedia)->map(function (Media $playlistMedia) use ($driver) {
            $media = (new MediaOpener($playlistMedia->getDisk(), $driver))
                ->open($playlistMedia->getPath(), ['-allowed_extensions', 'ALL']);

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

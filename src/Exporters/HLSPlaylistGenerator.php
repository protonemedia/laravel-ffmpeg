<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Illuminate\Support\Collection;
use ProtoneMedia\LaravelFFMpeg\Drivers\PHPFFMpeg;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Http\DynamicHLSPlaylist;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;
use ProtoneMedia\LaravelFFMpeg\Support\StreamParser;

class HLSPlaylistGenerator implements PlaylistGenerator
{
    public const PLAYLIST_START = '#EXTM3U';

    public const PLAYLIST_END = '#EXT-X-ENDLIST';

    protected bool $withEndLine = true;

    /**
     * Adds the #EXT-X-ENDLIST tag to the end of the playlist.
     *
     * @return $this
     */
    public function withoutEndLine(): self
    {
        $this->withEndLine = false;

        return $this;
    }

    /**
     * Return the line from the master playlist that references the given segment playlist.
     *
     * @param  \ProtoneMedia\LaravelFFMpeg\Filesystem\Media  $playlistMedia
     */
    private function getStreamInfoLine(Media $segmentPlaylistMedia, string $key): string
    {
        $segmentPlaylist = $segmentPlaylistMedia->getDisk()->get(
            $segmentPlaylistMedia->getDirectory().HLSExporter::generateTemporarySegmentPlaylistFilename($key)
        );

        $lines = DynamicHLSPlaylist::parseLines($segmentPlaylist)->filter();

        return $lines->get($lines->search($segmentPlaylistMedia->getFilename()) - 1);
    }

    /**
     * Loops through all segment playlists and generates a main playlist. It finds
     * the relative paths to the segment playlists and adds the framerate when
     * to each playlist.
     */
    public function get(array $segmentPlaylists, PHPFFMpeg $driver): string
    {
        return Collection::make($segmentPlaylists)->map(function (Media $segmentPlaylist, $key) use ($driver) {
            $streamInfoLine = $this->getStreamInfoLine($segmentPlaylist, $key);

            $media = (new MediaOpener($segmentPlaylist->getDisk(), $driver))
                ->openWithInputOptions($segmentPlaylist->getPath(), ['-allowed_extensions', 'ALL']);

            if ($media->getVideoStream()) {
                if ($frameRate = StreamParser::new($media->getVideoStream())->getFrameRate()) {
                    $streamInfoLine .= ",FRAME-RATE={$frameRate}";
                }
            }

            return [$streamInfoLine, $segmentPlaylist->getFilename()];
        })->collapse()
            ->prepend(static::PLAYLIST_START)
            ->when($this->withEndLine, fn (Collection $lines) => $lines->push(static::PLAYLIST_END))
            ->implode(PHP_EOL);
    }
}

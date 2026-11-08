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

    private ?string $sharedPathPrefix = null;

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

        if ($segmentPlaylist === null) {
            throw new \Exception("Segment playlist not found: {$segmentPlaylistMedia->getDirectory()}".HLSExporter::generateTemporarySegmentPlaylistFilename($key));
        }

        $lines = DynamicHLSPlaylist::parseLines($segmentPlaylist)->filter();

        $index = $lines->search($segmentPlaylistMedia->getFilename());

        if ($index === false || $index === 0) {
            throw new \Exception("Could not find stream info line for: {$segmentPlaylistMedia->getFilename()}");
        }

        return $lines->get($index - 1);
    }

    private function resolveSharedPathPrefix(Collection $segmentPlaylists): ?string
    {
        $firstPath = $segmentPlaylists->first()?->getPath();

        if (! is_string($firstPath) || $firstPath === '') {
            return null;
        }

        $prefix = $firstPath;

        foreach ($segmentPlaylists as $segmentPlaylist) {
            $path = $segmentPlaylist->getPath();

            while (! str_starts_with($path, $prefix) && $prefix !== '') {
                $prefix = substr($prefix, 0, -1);
            }

            if ($prefix === '') {
                return null;
            }
        }

        $lastSlashPosition = strrpos($prefix, '/');

        return $lastSlashPosition === false ? null : substr($prefix, 0, $lastSlashPosition + 1);
    }

    private function getPlaylistPathForMaster(Media $segmentPlaylist): string
    {
        $path = $segmentPlaylist->getPath();

        if (! $this->sharedPathPrefix || ! str_starts_with($path, $this->sharedPathPrefix)) {
            return $segmentPlaylist->getFilename();
        }

        return ltrim(substr($path, strlen($this->sharedPathPrefix)), '/');
    }

    /**
     * Loops through all segment playlists and generates a main playlist. It finds
     * the relative paths to the segment playlists and adds the framerate when
     * to each playlist.
     */
    public function get(array $segmentPlaylists, PHPFFMpeg $driver): string
    {
        $segmentPlaylistsCollection = Collection::make($segmentPlaylists);
        $this->sharedPathPrefix = $this->resolveSharedPathPrefix($segmentPlaylistsCollection);

        return $segmentPlaylistsCollection->map(function (Media $segmentPlaylist, $key) use ($driver) {
            $streamInfoLine = $this->getStreamInfoLine($segmentPlaylist, $key);

            $media = (new MediaOpener($segmentPlaylist->getDisk(), $driver))
                ->openWithInputOptions($segmentPlaylist->getPath(), ['-allowed_extensions', 'ALL']);

            if ($media->getVideoStream()) {
                if ($frameRate = StreamParser::new($media->getVideoStream())->getFrameRate()) {
                    $streamInfoLine .= ",FRAME-RATE={$frameRate}";
                }
            }

            return [$streamInfoLine, $this->getPlaylistPathForMaster($segmentPlaylist)];
        })->collapse()
            ->prepend(static::PLAYLIST_START)
            ->when($this->withEndLine, fn (Collection $lines) => $lines->push(static::PLAYLIST_END))
            ->implode(PHP_EOL);
    }
}

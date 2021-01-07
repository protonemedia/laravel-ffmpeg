<?php

namespace ProtoneMedia\LaravelFFMpeg\Http;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;

class DynamicHLSPlaylist implements Responsable
{
    /**
     * @var \ProtoneMedia\LaravelFFMpeg\Filesystem\Disk
     */
    private $disk;

    /**
     * @var \ProtoneMedia\LaravelFFMpeg\Filesystem\Media
     */
    private $media;

    /**
     * Callable to retrieve the path to the given key.
     *
     * @var callable
     */
    private $keyResolver;

    /**
     * Callable to retrieve the path to the given media.
     *
     * @var callable
     */
    private $mediaResolver;

    /**
     * @var array
     */
    private $keyCache = [];

    /**
     * @var array
     */
    private $playlistCache = [];

    /**
     * @var array
     */
    private $mediaCache = [];

    /**
     * Uses the 'filesystems.default' disk as default.
     */
    public function __construct($disk = null)
    {
        $this->fromDisk($disk ?: config('filesystems.default'));
    }

    /**
     * Set the disk to open files from.
     */
    public function fromDisk($disk): self
    {
        $this->disk = Disk::make($disk);

        return $this;
    }

    /**
     * Instantiates a Media object for the given path and clears the cache.
     */
    public function open(string $path): self
    {
        $this->media = Media::make($this->disk, $path);

        $this->keyCache      = [];
        $this->playlistCache = [];
        $this->mediaCache    = [];

        return $this;
    }

    public function setMediaUrlResolver(callable $mediaResolver): self
    {
        $this->mediaResolver = $mediaResolver;

        return $this;
    }

    public function setPlaylistUrlResolver(callable $playlistResolver): self
    {
        $this->playlistResolver = $playlistResolver;

        return $this;
    }

    public function setKeyUrlResolver(callable $keyResolver): self
    {
        $this->keyResolver = $keyResolver;

        return $this;
    }

    /**
     * Returns the resolved key filename from the cache or resolves it.
     *
     * @param string $key
     * @return string
     */
    private function resolveKeyFilename(string $key): string
    {
        if (array_key_exists($key, $this->keyCache)) {
            return $this->keyCache[$key];
        }

        return $this->keyCache[$key] = call_user_func($this->keyResolver, $key);
    }

    /**
     * Returns the resolved media filename from the cache or resolves it.
     *
     * @param string $key
     * @return string
     */
    private function resolveMediaFilename(string $media): string
    {
        if (array_key_exists($media, $this->mediaCache)) {
            return $this->mediaCache[$media];
        }

        return $this->mediaCache[$media] = call_user_func($this->mediaResolver, $media);
    }

    /**
     * Returns the resolved playlist filename from the cache or resolves it.
     *
     * @param string $key
     * @return string
     */
    private function resolvePlaylistFilename(string $playlist): string
    {
        if (array_key_exists($playlist, $this->playlistCache)) {
            return $this->playlistCache[$playlist];
        }

        return $this->playlistCache[$playlist] = call_user_func($this->playlistResolver, $playlist);
    }

    /**
     * Parses the lines into a Collection
     *
     * @param string $lines
     * @return \Illuminate\Support\Collection
     */
    public static function parseLines(string $lines): Collection
    {
        return Collection::make(preg_split('/\n|\r\n?/', $lines));
    }

    /**
     * Returns a boolean wether the line contains a .M3U8 playlist filename
     * or a .TS segment filename.
     *
     * @param string $line
     * @return boolean
     */
    private static function lineHasMediaFilename(string $line): bool
    {
        return !Str::startsWith($line, '#') && Str::endsWith($line, ['.m3u8', '.ts']);
    }

    /**
     * Returns the filename of the encryption key.
     *
     * @param string $line
     * @return string|null
     */
    private static function extractKeyFromExtLine(string $line): ?string
    {
        preg_match_all('/#EXT-X-KEY:METHOD=AES-128,URI="([a-zA-Z0-9-_\/:]+.key)",IV=[a-z0-9]+/', $line, $matches);

        return $matches[1][0] ?? null;
    }

    /**
     * Returns the processed content of the playlist.
     *
     * @return string
     */
    public function get(): string
    {
        return $this->getProcessedPlaylist($this->media->getPath());
    }

    /**
     * Returns a collection of all processed segment playlists
     * and the processed main playlist.
     *
     * @return \Illuminate\Support\Collection
     */
    public function all(): Collection
    {
        return static::parseLines(
            $this->disk->get($this->media->getPath())
        )->filter(function ($line) {
            return static::lineHasMediaFilename($line);
        })->mapWithKeys(function ($segmentPlaylist) {
            return [$segmentPlaylist => $this->getProcessedPlaylist($segmentPlaylist)];
        })->prepend(
            $this->getProcessedPlaylist($this->media->getPath()),
            $this->media->getPath()
        );
    }

    /**
     * Processes the given playlist.
     *
     * @param string $playlistPath
     * @return string
     */
    public function getProcessedPlaylist(string $playlistPath): string
    {
        return static::parseLines($this->disk->get($playlistPath))->map(function (string $line) {
            if (static::lineHasMediaFilename($line)) {
                return Str::endsWith($line, '.m3u8')
                    ? $this->resolvePlaylistFilename($line)
                    : $this->resolveMediaFilename($line);
            }

            $key = static::extractKeyFromExtLine($line);

            if (!$key) {
                return $line;
            }

            return str_replace(
                '#EXT-X-KEY:METHOD=AES-128,URI="' . $key . '"',
                '#EXT-X-KEY:METHOD=AES-128,URI="' . $this->resolveKeyFilename($key) . '"',
                $line
            );
        })->implode(PHP_EOL);
    }

    public function toResponse($request)
    {
        return Response::make($this->get(), 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
        ]);
    }
}

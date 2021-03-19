<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Closure;
use FFMpeg\Format\FormatInterface;
use FFMpeg\Format\Video\DefaultVideo;
use FFMpeg\Format\VideoInterface;
use Illuminate\Support\Collection;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;

class HLSExporter extends MediaExporter
{
    use EncryptsHLSSegments;

    const HLS_KEY_INFO_FILENAME = 'hls_encryption.keyinfo';
    const ENCRYPTION_LISTENER   = "listen-encryption-key";

    /**
     * @var integer
     */
    private $segmentLength = 10;

    /**
    * @var integer
    */
    private $keyFrameInterval = 48;

    /**
     * @var \Illuminate\Support\Collection
     */
    private $pendingFormats;

    /**
     * @var \ProtoneMedia\LaravelFFMpeg\Exporters\PlaylistGenerator
     */
    private $playlistGenerator;

    /**
     * @var \Closure
     */
    private $segmentFilenameGenerator = null;

    /**
     * Setter for the segment length
     *
     * @param integer $length
     * @return self
     */
    public function setSegmentLength(int $length): self
    {
        $this->segmentLength = $length;

        return $this;
    }

    /**
     * Setter for the Key Frame interval
     *
     * @param integer $interval
     * @return self
     */
    public function setKeyFrameInterval(int $interval): self
    {
        $this->keyFrameInterval = $interval;

        return $this;
    }

    /**
     * Method to set a different playlist generator than
     * the default HLSPlaylistGenerator.
     *
     * @param \ProtoneMedia\LaravelFFMpeg\Exporters\PlaylistGenerator $playlistGenerator
     * @return self
     */
    public function withPlaylistGenerator(PlaylistGenerator $playlistGenerator): self
    {
        $this->playlistGenerator = $playlistGenerator;

        return $this;
    }

    private function getPlaylistGenerator(): PlaylistGenerator
    {
        return $this->playlistGenerator ?: new HLSPlaylistGenerator;
    }

    /**
     * Setter for a callback that generates a segment filename.
     *
     * @param Closure $callback
     * @return self
     */
    public function useSegmentFilenameGenerator(Closure $callback): self
    {
        $this->segmentFilenameGenerator = $callback;

        return $this;
    }

    /**
     * Returns a default generator if none is set.
     *
     * @return callable
     */
    private function getSegmentFilenameGenerator(): callable
    {
        return $this->segmentFilenameGenerator ?: function ($name, $format, $key, $segments, $playlist) {
            $segments("{$name}_{$key}_{$format->getKiloBitrate()}_%05d.ts");
            $playlist("{$name}_{$key}_{$format->getKiloBitrate()}.m3u8");
        };
    }

    /**
     * Calls the generator with the path (without extension), format and key.
     *
     * @param string $baseName
     * @param \FFMpeg\Format\VideoInterface $format
     * @param integer $key
     * @return array
     */
    private function getSegmentPatternAndFormatPlaylistPath(string $baseName, VideoInterface $format, int $key): array
    {
        $segmentsPattern    = null;
        $formatPlaylistPath = null;

        call_user_func(
            $this->getSegmentFilenameGenerator(),
            $baseName,
            $format,
            $key,
            function ($path) use (&$segmentsPattern) {
                $segmentsPattern = $path;
            },
            function ($path) use (&$formatPlaylistPath) {
                $formatPlaylistPath = $path;
            }
        );

        return [$segmentsPattern, $formatPlaylistPath];
    }

    /**
     * Merges the HLS parameters to the given format.
     *
     * @param \FFMpeg\Format\Video\DefaultVideo $format
     * @param string $segmentsPattern
     * @param \ProtoneMedia\LaravelFFMpeg\Filesystem\Disk $disk
     * @param integer $key
     * @return array
     */
    private function addHLSParametersToFormat(DefaultVideo $format, string $segmentsPattern, Disk $disk, int $key): array
    {
        $format->setAdditionalParameters(array_merge(
            $format->getAdditionalParameters() ?: [],
            $hlsParameters = [
                '-sc_threshold',
                '0',
                '-g',
                $this->keyFrameInterval,
                '-hls_playlist_type',
                'vod',
                '-hls_time',
                $this->segmentLength,
                '-hls_segment_filename',
                $disk->makeMedia($segmentsPattern)->getLocalPath(),
                '-master_pl_name',
                $this->generateTemporarySegmentPlaylistFilename($key),
            ],
            $this->getEncrypedHLSParameters()
        ));

        return $hlsParameters;
    }

    /**
     * Gives the callback an HLSVideoFilters object that provides addFilter(),
     * addLegacyFilter(), addWatermark() and resize() helper methods. It
     * returns a mapping for the video and (optional) audio stream.
     *
     * @param callable $filtersCallback
     * @param integer $formatKey
     * @return array
     */
    private function applyFiltersCallback(callable $filtersCallback, int $formatKey): array
    {
        $filtersCallback(
            $hlsVideoFilters = new HLSVideoFilters($this->driver, $formatKey)
        );

        $filterCount = $hlsVideoFilters->count();

        $outs = [$filterCount ? HLSVideoFilters::glue($formatKey, $filterCount) : '0:v'];

        if ($this->getAudioStream()) {
            $outs[] = '0:a';
        }

        return $outs;
    }

    /**
     * Returns the filename of a segment playlist by its key. We let FFmpeg generate a playlist
     * for each added format so we don't have to detect the bitrate and codec ourselves.
     * We use this as a reference so when can generate our own main playlist.
     *
     * @param int $key
     * @return string
     */
    public static function generateTemporarySegmentPlaylistFilename(int $key): string
    {
        return "temporary_segment_playlist_{$key}.m3u8";
    }

    /**
     * Loops through each added format and then deletes the temporary
     * segment playlist, which we generate manually using the
     * HLSPlaylistGenerator.
     *
     * @param \ProtoneMedia\LaravelFFMpeg\Filesystem\Media $media
     * @return self
     */
    private function cleanupSegmentPlaylistGuides(Media $media): self
    {
        $disk      = $media->getDisk();
        $directory = $media->getDirectory();

        $this->pendingFormats->map(function ($formatAndCallback, $key) use ($disk, $directory) {
            $disk->delete($directory . static::generateTemporarySegmentPlaylistFilename($key));
        });

        return $this;
    }

    /**
     * Adds a mapping for each added format and automatically handles the mapping
     * for filters. Adds a handler to rotate the encryption key (optional).
     * Returns a media collection of all segment playlists.
     *
     * @param string $path
     * @throws \ProtoneMedia\LaravelFFMpeg\Exporters\NoFormatException
     * @return \Illuminate\Support\Collection
     */
    private function prepareSaving(string $path = null): Collection
    {
        if (!$this->pendingFormats) {
            throw new NoFormatException;
        }

        $media = $this->getDisk()->makeMedia($path);

        $baseName = $media->getDirectory() . $media->getFilenameWithoutExtension();

        return $this->pendingFormats->map(function (array $formatAndCallback, $key) use ($baseName) {
            [$format, $filtersCallback] = $formatAndCallback;

            [$segmentsPattern, $formatPlaylistPath] = $this->getSegmentPatternAndFormatPlaylistPath(
                $baseName,
                $format,
                $key
            );

            $disk = $this->getDisk()->clone();

            $this->addHLSParametersToFormat($format, $segmentsPattern, $disk, $key);

            if ($filtersCallback) {
                $outs = $this->applyFiltersCallback($filtersCallback, $key);
            }
            $formatPlaylistOutput = $disk->makeMedia($formatPlaylistPath);
            $this->addFormatOutputMapping($format, $formatPlaylistOutput, $outs ?? ['0']);

            return $formatPlaylistOutput;
        })->tap(function () {
            $this->addHandlerToRotateEncryptionKey();
        });
    }

    /**
     * Prepares the saves command but returns the command instead.
     *
     * @param string $path
     * @return mixed
     */
    public function getCommand(string $path = null)
    {
        $this->prepareSaving($path);

        return parent::getCommand(null);
    }

    /**
     * Runs the export, generates the main playlist, and cleans up the
     * segment playlist guides and temporary HLS encryption keys.
     *
     * @param string $path
     * @return \ProtoneMedia\LaravelFFMpeg\MediaOpener
     */
    public function save(string $mainPlaylistPath = null): MediaOpener
    {
        return $this->prepareSaving($mainPlaylistPath)->pipe(function ($segmentPlaylists) use ($mainPlaylistPath) {
            $result = parent::save();

            $playlist = $this->getPlaylistGenerator()->get(
                $segmentPlaylists->all(),
                $this->driver->fresh()
            );

            $this->getDisk()->put($mainPlaylistPath, $playlist);

            $this->replaceAbsolutePathsHLSEncryption($segmentPlaylists)
                ->cleanupSegmentPlaylistGuides($segmentPlaylists->first())
                ->cleanupHLSEncryption()
                ->removeHandlerThatRotatesEncryptionKey();

            return $result;
        });
    }

    /**
     * Initializes the $pendingFormats property when needed and adds the format
     * with the optional callback to add filters.
     *
     * @param \FFMpeg\Format\FormatInterface $format
     * @param callable $filtersCallback
     * @return self
     */
    public function addFormat(FormatInterface $format, callable $filtersCallback = null): self
    {
        if (!$this->pendingFormats) {
            $this->pendingFormats = new Collection;
        }

        $this->pendingFormats->push([$format, $filtersCallback]);

        return $this;
    }
}

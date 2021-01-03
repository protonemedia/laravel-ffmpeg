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

    public function setSegmentLength(int $length): self
    {
        $this->segmentLength = $length;

        return $this;
    }

    public function setKeyFrameInterval(int $interval): self
    {
        $this->keyFrameInterval = $interval;

        return $this;
    }

    public function withPlaylistGenerator(PlaylistGenerator $playlistGenerator): self
    {
        $this->playlistGenerator = $playlistGenerator;

        return $this;
    }

    private function getPlaylistGenerator(): PlaylistGenerator
    {
        return $this->playlistGenerator ?: new HLSPlaylistGenerator;
    }

    public function useSegmentFilenameGenerator(Closure $callback): self
    {
        $this->segmentFilenameGenerator = $callback;

        return $this;
    }

    private function getSegmentFilenameGenerator(): callable
    {
        return $this->segmentFilenameGenerator ?: function ($name, $format, $key, $segments, $playlist) {
            $segments("{$name}_{$key}_{$format->getKiloBitrate()}_%05d.ts");
            $playlist("{$name}_{$key}_{$format->getKiloBitrate()}.m3u8");
        };
    }

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

    public static function generateTemporarySegmentPlaylistFilename($key): string
    {
        return "temporary_segment_playlist_{$key}.m3u8";
    }

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

        return $this->pendingFormats->map(function (array $formatAndCallback, $key) use ($baseName, $media) {
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

            $this->addFormatOutputMapping($format, $disk->makeMedia($formatPlaylistPath), $outs ?? ['0']);

            return $this->getDisk()->makeMedia($formatPlaylistPath);
        })->tap(function () {
            $this->addHandlerToRotateEncryptionKey();
        });
    }

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
                ->cleanupHLSEncryption();

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

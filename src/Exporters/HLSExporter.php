<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Closure;
use FFMpeg\Format\Audio\DefaultAudio;
use FFMpeg\Format\AudioInterface;
use FFMpeg\Format\FormatInterface;
use FFMpeg\Format\Video\DefaultVideo;
use Illuminate\Support\Collection;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;

class HLSExporter extends MediaExporter
{
    use EncryptsHLSSegments;

    public const HLS_KEY_INFO_FILENAME = 'hls_encryption.keyinfo';
    public const ENCRYPTION_LISTENER = 'listen-encryption-key';

    private int $segmentLength = 10;
    private int $keyFrameInterval = 48;
    private Collection $pendingFormats;
    private ?PlaylistGenerator $playlistGenerator = null;
    private ?Closure $segmentFilenameGenerator = null;

    public function setSegmentLength(int $length): self
    {
        $this->segmentLength = max(2, $length);
        return $this;
    }

    public function setKeyFrameInterval(int $interval): self
    {
        $this->keyFrameInterval = max(2, $interval);
        return $this;
    }

    public function withPlaylistGenerator(PlaylistGenerator $playlistGenerator): self
    {
        $this->playlistGenerator = $playlistGenerator;
        return $this;
    }

    private function getPlaylistGenerator(): PlaylistGenerator
    {
        return $this->playlistGenerator ??= new HLSPlaylistGenerator;
    }

    public function withoutPlaylistEndLine(): self
    {
        $playlistGenerator = $this->getPlaylistGenerator();

        if ($playlistGenerator instanceof HLSPlaylistGenerator) {
            $playlistGenerator->withoutEndLine();
        }

        return $this;
    }

    public function useSegmentFilenameGenerator(Closure $callback): self
    {
        $this->segmentFilenameGenerator = $callback;
        return $this;
    }

    private function getSegmentFilenameGenerator(): callable
    {
        return $this->segmentFilenameGenerator ?? function ($name, $format, $key, $segments, $playlist) {
            $bitrate = $this->driver->getVideoStream()
                ? $format->getKiloBitrate()
                : $format->getAudioKiloBitrate();

            $segments("{$name}_{$key}_{$bitrate}_%05d.ts");
            $playlist("{$name}_{$key}_{$bitrate}.m3u8");
        };
    }

    private function getSegmentPatternAndFormatPlaylistPath(string $baseName, AudioInterface $format, int $key): array
    {
        $segmentsPattern = null;
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

    private function addHLSParametersToFormat(DefaultAudio $format, string $segmentsPattern, Disk $disk, int $key): array
    {
        $format->setAdditionalParameters(array_merge(
            $format->getAdditionalParameters() ?: [],
            $hlsParameters = [
                '-sc_threshold', '0',
                '-g', $this->keyFrameInterval,
                '-hls_playlist_type', 'vod',
                '-hls_time', $this->segmentLength,
                '-hls_segment_filename', $disk->makeMedia($segmentsPattern)->getLocalPath(),
                '-master_pl_name', self::generateTemporarySegmentPlaylistFilename($key),
            ],
            $this->getEncrypedHLSParameters()
        ));

        return $hlsParameters;
    }

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

    public static function generateTemporarySegmentPlaylistFilename(int $key): string
    {
        return "temporary_segment_playlist_{$key}.m3u8";
    }

    private function cleanupSegmentPlaylistGuides(Media $media): self
    {
        $disk = $media->getDisk();
        $directory = $media->getDirectory();

        $this->pendingFormats->each(function ($formatAndCallback, $key) use ($disk, $directory) {
            $disk->delete($directory . self::generateTemporarySegmentPlaylistFilename($key));
        });

        return $this;
    }

    private function prepareSaving(?string $path = null): Collection
    {
        if (!$this->pendingFormats) {
            throw new NoFormatException;
        }

        $media = $this->getDisk()->makeMedia($path);
        $baseName = $media->getDirectory() . $media->getFilenameWithoutExtension();

        return $this->pendingFormats->map(function (array $formatAndCallback, $key) use ($baseName) {
            [$format, $filtersCallback] = $formatAndCallback;

            [$segmentsPattern, $formatPlaylistPath] = $this->getSegmentPatternAndFormatPlaylistPath(
                $baseName, $format, $key
            );

            $disk = $this->getDisk()->cloneDisk();
            $this->addHLSParametersToFormat($format, $segmentsPattern, $disk, $key);

            $outs = $filtersCallback ? $this->applyFiltersCallback($filtersCallback, $key) : ['0'];

            $formatPlaylistOutput = $disk->makeMedia($formatPlaylistPath);
            $this->addFormatOutputMapping($format, $formatPlaylistOutput, $outs);

            return $formatPlaylistOutput;
        })->tap(fn() => $this->addHandlerToRotateEncryptionKey());
    }

    public function getCommand(?string $path = null)
    {
        $this->prepareSaving($path);
        return parent::getCommand(null);
    }

    public function save(?string $mainPlaylistPath = null): MediaOpener
    {
        return $this->prepareSaving($mainPlaylistPath)->pipe(function ($segmentPlaylists) use ($mainPlaylistPath) {
            $result = parent::save();

            $playlist = $this->getPlaylistGenerator()->get(
                $segmentPlaylists->all(), $this->driver->fresh()
            );

            $this->getDisk()->put($mainPlaylistPath, $playlist);

            $this->replaceAbsolutePathsHLSEncryption($segmentPlaylists)
                ->cleanupSegmentPlaylistGuides($segmentPlaylists->first())
                ->cleanupHLSEncryption()
                ->removeHandlerThatRotatesEncryptionKey();

            return $result;
        });
    }

    public function addFormat(FormatInterface $format, ?callable $filtersCallback = null): self
    {
        $this->pendingFormats ??= new Collection;

        if (!$format instanceof DefaultVideo && $format instanceof DefaultAudio) {
            $originalFormat = clone $format;

            $format = new class extends DefaultVideo {
                private array $audioCodecs = [];

                public function setAvailableAudioCodecs(array $audioCodecs)
                {
                    $this->audioCodecs = $audioCodecs;
                }

                public function getAvailableAudioCodecs(): array
                {
                    return $this->audioCodecs;
                }

                public function supportBFrames()
                {
                    return false;
                }

                public function getAvailableVideoCodecs(): array
                {
                    return [];
                }
            };

            $format->setAvailableAudioCodecs($originalFormat->getAvailableAudioCodecs());
            $format->setAudioCodec($originalFormat->getAudioCodec());
            $format->setAudioKiloBitrate($originalFormat->getAudioKiloBitrate());

            if ($originalFormat->getAudioChannels()) {
                $format->setAudioChannels($originalFormat->getAudioChannels());
            }
        }

        $this->pendingFormats->push([$format, $filtersCallback]);

        return $this;
    }
}

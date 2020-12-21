<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Closure;
use FFMpeg\Format\FormatInterface;
use FFMpeg\Format\Video\DefaultVideo;
use FFMpeg\Format\VideoInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\FFMpeg\StdListener;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;
use ProtoneMedia\LaravelFFMpeg\MediaOpener;

class HLSExporter extends MediaExporter
{
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
     * The encryption key.
     *
     * @var string
     */
    private $encryptionKey;

    /**
     * @var \Closure
     */
    private $segmentFilenameGenerator = null;

    private $newEncryptionKeyCallback = null;

    private $encryptionKeyDisk = null;
    private $encryptionKeyName = null;
    private $encryptionIV      = null;

    private $rotatingEncryptiongKey = false;

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

    public function onEncryptionKey(Closure $callback): self
    {
        $this->newEncryptionKeyCallback = $callback;

        return $this;
    }

    private function getSegmentFilenameGenerator(): callable
    {
        return $this->segmentFilenameGenerator ?: function ($name, $format, $key, $segments, $playlist) {
            $segments("{$name}_{$key}_{$format->getKiloBitrate()}_%05d.ts");
            $playlist("{$name}_{$key}_{$format->getKiloBitrate()}.m3u8");
        };
    }

    /**
     * Creates a new encryption key.
     *
     * @return string
     */
    public static function generateEncryptionKey(): string
    {
        return random_bytes(16);
    }

    /**
     * Sets the encryption key with the given value or generates a new one.
     *
     * @param string $key
     * @return self
     */
    public function withEncryptionKey($key = null): self
    {
        $this->encryptionKey = $key ?: static::generateEncryptionKey();

        return $this;
    }

    public function withRotatingEncryptionKey(): self
    {
        $this->withEncryptionKey();

        $this->rotatingEncryptiongKey = true;

        return $this;
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

    private function rotateEncryptionKey()
    {
        $this->withEncryptionKey();

        if (!$this->encryptionKeyName) {
            $this->encryptionKeyName = Str::random(8);
        }

        if (!$this->encryptionIV) {
            $this->encryptionIV = bin2hex(static::generateEncryptionKey());
        }

        if (!$this->encryptionKeyDisk) {
            $this->encryptionKeyDisk = Disk::makeTemporaryDisk();
        }

        $name = $this->encryptionKeyName . "_" . Str::random(8);

        file_put_contents(
            $keyPath = $this->encryptionKeyDisk->makeMedia("{$name}.key")->getLocalPath(),
            $this->encryptionKey
        );

        file_put_contents(
            $keyInfoPath = $this->encryptionKeyDisk->makeMedia("{$this->encryptionKeyName}.keyinfo")->getLocalPath(),
            $keyPath . PHP_EOL . $keyPath . PHP_EOL . $this->encryptionIV
        );

        if ($this->newEncryptionKeyCallback) {
            call_user_func($this->newEncryptionKeyCallback, "{$name}.key", $this->encryptionKey);
        }

        return $keyInfoPath;
    }

    private function addHLSParametersToFormat(DefaultVideo $format, string $segmentsPattern, Disk $disk)
    {
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
        ];

        if ($this->encryptionKey) {
            $hlsParameters[] = '-hls_key_info_file';
            $hlsParameters[] = $this->rotateEncryptionKey();

            if ($this->rotatingEncryptiongKey) {
                $hlsParameters[] = '-hls_flags';
                $hlsParameters[] = 'periodic_rekey';
            }
        }

        $format->setAdditionalParameters(array_merge(
            $format->getAdditionalParameters() ?: [],
            $hlsParameters
        ));

        if ($this->rotatingEncryptiongKey) {
            $this->addListener(new StdListener)->onEvent('listen', function ($line) {
                if (!(Str::contains($line, "Opening 'crypto:/") && Str::contains($line, ".ts' for writing"))) {
                    return;
                }

                $this->rotateEncryptionKey();
            });
        }
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

    private function prepareSaving(string $path = null): Collection
    {
        $media = $this->getDisk()->makeMedia($path);

        $baseName = $media->getDirectory() . $media->getFilenameWithoutExtension();

        return $this->pendingFormats->map(function ($formatAndCallback, $key) use ($baseName) {
            $disk = $this->getDisk()->clone();

            [$format, $filtersCallback] = $formatAndCallback;

            [$segmentsPattern, $formatPlaylistPath] = $this->getSegmentPatternAndFormatPlaylistPath(
                $baseName,
                $format,
                $key
            );

            $this->addHLSParametersToFormat($format, $segmentsPattern, $disk);

            if ($filtersCallback) {
                $outs = $this->applyFiltersCallback($filtersCallback, $key);
            }

            $this->addFormatOutputMapping($format, $disk->makeMedia($formatPlaylistPath), $outs ?? ['0']);

            return $this->getDisk()->makeMedia($formatPlaylistPath);
        });
    }

    public function getCommand(string $path = null)
    {
        $this->prepareSaving($path);

        return parent::getCommand(null);
    }

    public function save(string $path = null): MediaOpener
    {
        return $this->prepareSaving($path)->pipe(function ($playlistMedia) use ($path) {
            $result = parent::save();

            $playlist = $this->getPlaylistGenerator()->get(
                $playlistMedia->all(),
                $this->driver->fresh()
            );

            $this->getDisk()->put($path, $playlist);

            return $result;
        });
    }

    public function addFormat(FormatInterface $format, callable $filtersCallback = null): self
    {
        if (!$this->pendingFormats) {
            $this->pendingFormats = new Collection;
        }

        $this->pendingFormats->push([$format, $filtersCallback]);

        return $this;
    }
}

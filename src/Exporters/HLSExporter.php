<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Closure;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Filters\AdvancedMedia\ComplexFilters;
use FFMpeg\Filters\Video\ResizeFilter;
use FFMpeg\Format\FormatInterface;
use FFMpeg\Format\Video\DefaultVideo;
use FFMpeg\Format\VideoInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Disk;
use ProtoneMedia\LaravelFFMpeg\Filters\WatermarkFactory;
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

    private function addHLSParametersToFormat(DefaultVideo $format, string $segmentsPattern, Disk $disk)
    {
        $format->setAdditionalParameters([
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
        ]);
    }

    private function applyFiltersCallback(callable $filtersCallback, $formatKey): array
    {
        $formatFilters = $this->formatFilters;

        $mediaMock = new class($this->driver, $formatKey, $formatFilters) {
            private $driver;
            private $formatKey;
            private $formatFilters;

            public function __construct($driver, $formatKey, $formatFilters)
            {
                $this->driver        = $driver;
                $this->formatKey     = $formatKey;
                $this->formatFilters = $formatFilters;
            }

            private function called(): self
            {
                if (!$this->formatFilters->offsetExists($this->formatKey)) {
                    $this->formatFilters[$this->formatKey] = 1;
                } else {
                    $this->formatFilters[$this->formatKey] = $this->formatFilters[$this->formatKey] + 1;
                }

                return $this;
            }

            private function input(): string
            {
                $filters = $this->formatFilters->get($this->formatKey, 0);

                if ($filters < 1) {
                    return '[0]';
                }

                return "[v{$this->formatKey}.{$filters}]";
            }

            private function output(): string
            {
                $filters = $this->formatFilters->get($this->formatKey, 0) + 1;

                return "[v{$this->formatKey}.{$filters}]";
            }

            public function addLegacyFilter(...$arguments): self
            {
                $this->driver->addFilterAsComplexFilter($this->input(), $this->output(), ...$arguments);

                return $this->called();
            }

            public function resize($width, $height, $mode = null): self
            {
                $dimension = new Dimension($width, $height);

                $filter = new ResizeFilter($dimension, $mode);

                return $this->addLegacyFilter($filter);
            }

            public function addWatermark(callable $withWatermarkFactory): self
            {
                $withWatermarkFactory($watermarkFactory = new WatermarkFactory);

                return $this->addLegacyFilter($watermarkFactory->get());
            }

            public function scale($width, $height): self
            {
                return $this->addFilter("scale={$width}:{$height}");
            }

            public function addFilter(...$arguments): self
            {
                if (count($arguments) === 1 && !is_callable($arguments[0])) {
                    $this->driver->addFilter($this->input(), $arguments[0], $this->output());
                } else {
                    $this->driver->addFilter(function (ComplexFilters $filters) use ($arguments) {
                        $arguments[0]($filters, $this->input(), $this->output());
                    });
                }

                return $this->called();
            }
        };

        $filtersCallback($mediaMock);

        $filters = $formatFilters->get($formatKey, 0);

        if ($filters) {
            $outs = ["[v{$formatKey}.{$filters}]"];
        } else {
            $outs = ['0:v'];
        }

        if ($this->getAudioStream()) {
            $outs[] = '0:a';
        }

        return $outs;
    }

    public function save(string $path = null): MediaOpener
    {
        $media = $this->getDisk()->makeMedia($path);

        $baseName = $media->getDirectory() . $media->getFilenameWithoutExtension();

        $this->formatFilters = new Fluent;

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
        })->pipe(function ($playlistMedia) use ($path) {
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

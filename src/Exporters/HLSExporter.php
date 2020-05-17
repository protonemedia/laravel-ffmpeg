<?php

namespace Pbmedia\LaravelFFMpeg\Exporters;

use Closure;
use FFMpeg\Filters\AdvancedMedia\ComplexFilters;
use FFMpeg\Format\FormatInterface;
use FFMpeg\Format\Video\DefaultVideo;
use FFMpeg\Format\VideoInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
use Pbmedia\LaravelFFMpeg\Filesystem\Disk;
use Pbmedia\LaravelFFMpeg\MediaOpener;

class HLSExporter extends MediaExporter
{
    private int $segmentLength                    = 10;
    private int $keyFrameInterval                 = 48;
    private ?Collection $pendingFormats           = null;
    private ?PlaylistGenerator $playlistGenerator = null;
    private ?Closure $segmentFilenameGenerator    = null;

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

    public function useSegmentFilenameGenerator(Closure $callback): self
    {
        $this->segmentFilenameGenerator = $callback;

        return $this;
    }

    private function getSegmentPatternAndFormatPlaylistPath(string $baseName, VideoInterface $format, int $key): array
    {
        $segmentsPattern    = null;
        $formatPlaylistPath = null;

        call_user_func(
            $this->segmentFilenameGenerator,
            $baseName,
            $format,
            $key,
            function ($path) use (&$segmentsPattern) {
                $segmentsPattern = $path;
            },
            function ($path) use (&$formatPlaylistPath) {
                $formatPlaylistPath = $path;
            },
        );

        return [$segmentsPattern, $formatPlaylistPath];
    }

    private function addHLSParametersToFormat(DefaultVideo $format, string $segmentsPattern, Disk $disk)
    {
        $parameters = [
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

        $format->setAdditionalParameters($parameters);
    }

    private function applyFiltersCallback(callable $filtersCallback, $key): bool
    {
        $called = new Fluent(['called' => false]);

        $mediaMock = new class($this->driver, $key, $called) {
            private $driver;
            private $key;
            private $called;

            public function __construct($driver, $key, $called)
            {
                $this->driver = $driver;
                $this->key    = $key;
                $this->called = $called;
            }

            public function addLegacyFilter(...$arguments)
            {
                $this->driver->addFilterAsComplexFilter('[0]', "[v{$this->key}]", ...$arguments);

                $this->called['called'] = true;
            }

            public function scale($width, $height)
            {
                $this->addFilter("scale={$width}:{$height}");
            }

            public function addFilter(...$arguments)
            {
                $in  = '[0]';
                $out = "[v{$this->key}]";

                if (count($arguments) === 1 && !is_callable($arguments[0])) {
                    $this->driver->addFilter($in, $arguments[0], $out);
                } else {
                    $this->driver->addFilter(function (ComplexFilters $filters) use ($arguments, $in,$out) {
                        $arguments[0]($filters, $in, $out);
                    });
                }

                $this->called['called'] = true;
            }
        };

        $filtersCallback($mediaMock);

        return $called['called'];
    }

    public function save(string $path = null): MediaOpener
    {
        $baseName = $this->getDisk()->makeMedia($path)->getFilenameWithoutExtension();

        if (!$this->segmentFilenameGenerator) {
            $this->segmentFilenameGenerator = function ($name, $format, $key, $segments, $playlist) {
                $pattern = "{$name}_{$key}_{$format->getKiloBitrate()}";
                $segments("{$pattern}_%05d.ts");
                $playlist("{$pattern}.m3u8");
            };
        }

        return $this->pendingFormats->map(function ($formatAndCallback, $key) use ($baseName) {
            $disk = $this->getDisk()->clone();

            [$format, $filtersCallback] = $formatAndCallback;

            [$segmentsPattern, $formatPlaylistPath] = $this->getSegmentPatternAndFormatPlaylistPath(
                $baseName,
                $format,
                $key,
            );

            $this->addHLSParametersToFormat($format, $segmentsPattern, $disk);

            $keysWithFilters = [];

            if ($filtersCallback) {
                if ($this->applyFiltersCallback($filtersCallback, $key)) {
                    $keysWithFilters[$key] = "[v{$key}]";
                }
            }

            $this->addFormatOutputMapping($format, $disk->makeMedia($formatPlaylistPath), [$keysWithFilters[$key] ?? '0']);

            return $this->getDisk()->makeMedia($formatPlaylistPath);
        })->pipe(function ($playlistMedia) use ($path) {
            $result = parent::save();

            $this->getDisk()->put($path, $this->makePlaylist($playlistMedia->all()));

            return $result;
        });
    }

    private function makePlaylist(array $playlistMedia): string
    {
        if (!$this->playlistGenerator) {
            $this->withPlaylistGenerator(new HLSPlaylistGenerator);
        }

        return $this->playlistGenerator->get($playlistMedia, $this->driver->fresh());
    }

    public function addFormat(FormatInterface $format, callable $filtersCallback = null)
    {
        if (!$this->pendingFormats) {
            $this->pendingFormats = new Collection;
        }

        $this->pendingFormats->push([$format, $filtersCallback]);

        return $this;
    }
}

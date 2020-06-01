<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Closure;
use FFMpeg\Filters\AdvancedMedia\ComplexFilters;
use FFMpeg\Format\FormatInterface;
use FFMpeg\Format\Video\DefaultVideo;
use FFMpeg\Format\VideoInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
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

    private function applyFiltersCallback(callable $filtersCallback, $key): array
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

        $outs = [$called['called'] ? "[v{$key}]" : '0:v'];

        if ($this->getAudioStream()) {
            $outs[] = '0:a';
        }

        return $outs;
    }

    public function save(string $path = null): MediaOpener
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

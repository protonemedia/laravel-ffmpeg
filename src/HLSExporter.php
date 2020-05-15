<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Filters\AdvancedMedia\ComplexFilters;
use FFMpeg\Format\FormatInterface;
use FFMpeg\Format\Video\DefaultVideo;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;

class HLSExporter extends MediaExporter
{
    private ?Collection $pendingFormats = null;
    private int $segmentLength          = 10;
    private int $keyFrameInterval       = 48;

    public function setSegmentLength(int $length)
    {
        $this->segmentLength = $length;

        return $this;
    }

    public function setKeyFrameInterval(int $interval)
    {
        $this->keyFrameInterval = $interval;

        return $this;
    }

    private function addHLSParametersToFormat(DefaultVideo $format, string $baseName, Media $playlistMedia, int $key)
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
            $this->getDisk()->makeMedia("{$baseName}_%05d.ts")->getLocalPath(),
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
        $disk = $this->getDisk();

        $playlistMedia = $disk->makeMedia($path);

        $baseName = $playlistMedia->getFilenameWithoutExtension();

        $playlist = $this->pendingFormats->map(function ($formatAndCallback, $key) use ($baseName, $disk, $playlistMedia) {
            [$format, $filtersCallback] = $formatAndCallback;

            $baseName = "{$baseName}_{$key}_{$format->getKiloBitrate()}";

            $this->addHLSParametersToFormat($format, $baseName, $playlistMedia, $key);

            $keysWithFilters = [];

            if ($filtersCallback) {
                if ($this->applyFiltersCallback($filtersCallback, $key)) {
                    $keysWithFilters[$key] = "[v{$key}]";
                }
            }

            $this->addFormatOutputMapping($format, $formatPlaylist = $disk->makeMedia("{$baseName}.m3u8"), [$keysWithFilters[$key] ?? '0']);

            return [
                '#EXT-X-STREAM-INF',
                $formatPlaylist->getPath(),
            ];
        });

        $result = parent::save();

        $this->generatePlaylist($playlist);

        return $result;
    }

    private function generatePlaylist(Collection $streams)
    {
        $playlist = $streams->map(function ($stream, $key) {
            $playlistContent = file_get_contents(
                $this->getDisk()->makeMedia($stream[1])->getLocalPath()
            );

            $file = Collection::make(explode(PHP_EOL, $playlistContent))->first(function ($line) {
                return substr($line, 0, 1) !== '#';
            });

            $mediaStream = $this->getEmptyMediaOpener($this->getDisk())->open($file)->getStreams()[0];
            $mediaFormat = $this->getEmptyMediaOpener($this->getDisk())->open($file)->getFormat();

            $frameRate = trim(Str::before($mediaStream->get('avg_frame_rate'), "/1"));

            $stream[0] .= ":BANDWIDTH={$mediaFormat->get('bit_rate')}";
            $stream[0] .= ",RESOLUTION={$mediaStream->get('width')}x{$mediaStream->get('height')}";

            if ($frameRate) {
                $frameRate = number_format($frameRate, 3, '.', '');
                $stream[0] .= ",FRAME-RATE={$frameRate}";
            }

            return $stream;
        })->collapse()->prepend('#EXTM3U')->implode(PHP_EOL);
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

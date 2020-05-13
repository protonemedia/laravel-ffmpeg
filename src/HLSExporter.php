<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Filters\AdvancedMedia\ComplexFilters;
use FFMpeg\Format\FormatInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;

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

    private function addHLSParametersToFormat($format, string $baseName)
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

        $baseName = $disk->makeMedia($path)->getFilenameWithoutExtension();

        $this->pendingFormats->each(function ($formatAndCallback, $key) use ($baseName, $disk) {
            [$format, $filtersCallback] = $formatAndCallback;

            $baseName = "{$baseName}_{$key}_{$format->getKiloBitrate()}";

            $this->addHLSParametersToFormat($format, $baseName);

            $keysWithFilters = [];

            if ($filtersCallback) {
                if ($this->applyFiltersCallback($filtersCallback, $key)) {
                    $keysWithFilters[$key] = "[v{$key}]";
                }
            }

            $this->addFormatOutputMapping($format, $disk->makeMedia("{$baseName}.m3u8"), [$keysWithFilters[$key] ?? '0']);
        });

        return parent::save();
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

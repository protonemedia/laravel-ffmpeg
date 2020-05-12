<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Format\FormatInterface;
use Illuminate\Support\Collection;

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

    public function save(string $path = null)
    {
        $disk = $this->getDisk();

        $baseName = $disk->makeMedia($path)->getFilenameWithoutExtension();

        $this->pendingFormats->each(function ($formatAndCallback, $key) use ($baseName, $disk) {
            [$format, $filtersCallback] = $formatAndCallback;

            $keysWithFilters = [];

            $baseName = "{$baseName}_{$key}_{$format->getKiloBitrate()}";

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
                $disk->makeMedia("{$baseName}_%05d.ts")->getLocalPath(),
            ]);

            if ($filtersCallback) {
                $filtersCallback(new class($this->driver, $key) {
                    private $driver;
                    private $key;

                    public function __construct($driver, $key)
                    {
                        $this->driver = $driver;
                        $this->key = $key;
                    }

                    public function addFilter(...$arguments)
                    {
                        $this->driver->addBasicFilter('[0]', "[v{$this->key}]", ...$arguments);
                    }
                });

                $keysWithFilters[$key] = "[v{$key}]";
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

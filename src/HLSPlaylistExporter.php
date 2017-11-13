<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Format\VideoInterface;
use Pbmedia\LaravelFFMpeg\SegmentedExporter;

class HLSPlaylistExporter extends MediaExporter
{
    protected $segmentedExporters = [];

    protected $playlistPath;

    protected $segmentLength = 10;

    protected $saveMethod = 'savePlaylist';

    protected $progressCallback;

    public function addFormat(VideoInterface $format, callable $callback = null): MediaExporter
    {
        $segmentedExporter = $this->getSegmentedExporterFromFormat($format);

        if ($callback) {
            $callback($segmentedExporter->getMedia());
        }

        $this->segmentedExporters[] = $segmentedExporter;

        return $this;
    }

    public function getFormatsSorted(): array
    {
        return array_map(function ($exporter) {
            return $exporter->getFormat();
        }, $this->getSegmentedExportersSorted());
    }

    public function getSegmentedExportersSorted(): array
    {
        usort($this->segmentedExporters, function ($exportedA, $exportedB) {
            return $exportedA->getFormat()->getKiloBitrate() <=> $exportedB->getFormat()->getKiloBitrate();
        });

        return $this->segmentedExporters;
    }

    public function setPlaylistPath(string $playlistPath): MediaExporter
    {
        $this->playlistPath = $playlistPath;

        return $this;
    }

    public function setSegmentLength(int $segmentLength): MediaExporter
    {
        $this->segmentLength = $segmentLength;

        foreach ($this->segmentedExporters as $segmentedExporter) {
            $segmentedExporter->setSegmentLength($segmentLength);
        }

        return $this;
    }

    protected function getSegmentedExporterFromFormat(VideoInterface $format): SegmentedExporter
    {
        $media = clone $this->media;

        return (new SegmentedExporter($media))
            ->inFormat($format);
    }

    public function getSegmentedExporters(): array
    {
        return $this->segmentedExporters;
    }

    public function onProgress(callable $callback)
    {
        $this->progressCallback = $callback;

        return $this;
    }

    private function getSegmentedProgressCallback($key): callable
    {
        return function ($video, $format, $percentage) use ($key) {
            $previousCompletedSegments = $key / count($this->segmentedExporters) * 100;

            call_user_func($this->progressCallback,
                $previousCompletedSegments + ($percentage / count($this->segmentedExporters))
            );
        };
    }

    public function prepareSegmentedExporters()
    {
        foreach ($this->segmentedExporters as $key => $segmentedExporter) {
            if ($this->progressCallback) {
                $segmentedExporter->getFormat()->on('progress', $this->getSegmentedProgressCallback($key));
            }

            $segmentedExporter->setSegmentLength($this->segmentLength);
        }

        return $this;
    }

    protected function exportStreams()
    {
        $this->prepareSegmentedExporters();

        foreach ($this->segmentedExporters as $key => $segmentedExporter) {
            $segmentedExporter->saveStream($this->playlistPath);
        }
    }

    protected function getMasterPlaylistContents(): string
    {
        $lines = ['#EXTM3U'];

        foreach ($this->getSegmentedExportersSorted() as $segmentedExporter) {
            $bitrate = $segmentedExporter->getFormat()->getKiloBitrate() * 1000;

            $lines[] = '#EXT-X-STREAM-INF:BANDWIDTH=' . $bitrate;
            $lines[] = $segmentedExporter->getPlaylistFilename();
        }

        return implode(PHP_EOL, $lines);
    }

    public function savePlaylist(string $playlistPath): MediaExporter
    {
        $this->setPlaylistPath($playlistPath);
        $this->exportStreams();

        file_put_contents(
            $playlistPath,
            $this->getMasterPlaylistContents()
        );

        return $this;
    }
}

<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Format\VideoInterface;
use Pbmedia\LaravelFFMpeg\SegmentedExporter;

class HLSPlaylistExporter extends MediaExporter
{
    protected $formats = [];

    protected $playlistPath;

    protected $segmentLength = 10;

    protected $saveMethod = 'savePlaylist';

    public function addFormat(VideoInterface $format): MediaExporter
    {
        $this->formats[] = $format;

        return $this;
    }

    public function getFormats(): array
    {
        usort($this->formats, function ($formatA, $formatB) {
            return $formatA->getKiloBitrate() <=> $formatB->getKiloBitrate();
        });

        return $this->formats;
    }

    public function setPlaylistPath(string $playlistPath)
    {
        $this->playlistPath = $playlistPath;

        return $this;
    }

    public function setSegmentLength(int $segmentLength)
    {
        $this->segmentLength = $segmentLength;

        return $this;
    }

    protected function getSegmentedExporterFromFormat(VideoInterface $format): SegmentedExporter
    {
        $media = clone $this->media;

        return (new SegmentedExporter($media))
            ->inFormat($format)
            ->setPlaylistPath($this->playlistPath)
            ->setSegmentLength($this->segmentLength);
    }

    public function getSegmentedExporters(): array
    {
        return array_map(function ($format) {
            return $this->getSegmentedExporterFromFormat($format);
        }, $this->getFormats());
    }

    public function savePlaylist(string $playlistPath): MediaExporter
    {
        $this->setPlaylistPath($playlistPath);

        $masterPlaylistSteams = array_map(function ($segmentedExporter) {
            $segmentedExporter->saveStream($this->playlistPath);

            return '#EXT-X-STREAM-INF:BANDWIDTH=' .
            $segmentedExporter->getFormat()->getKiloBitrate() * 1000 . PHP_EOL .
            $segmentedExporter->getPlaylistFilename();

        }, $this->getSegmentedExporters());

        file_put_contents($playlistPath, '#EXTM3U' . PHP_EOL . implode(PHP_EOL, $masterPlaylistSteams));

        return $this;
    }
}

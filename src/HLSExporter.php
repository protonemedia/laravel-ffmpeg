<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Format\FormatInterface;
use Illuminate\Support\Collection;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;

class HLSExporter extends MediaExporter
{
    private ?Collection $pendingFormats = null;
    private Media $playlistMedia;
    private int $segmentLength    = 3;
    private int $keyFrameInterval = 48;

    public function save(string $path = null)
    {
        $this->playlistMedia = Media::make($this->getDisk(), $path);

        $this->pendingFormats->each(function ($format, $key) {
            $baseName = pathinfo($this->playlistMedia->getPath())['filename'];

            $formatOutputMedia = Media::make($this->getDisk(), "{$baseName}_{$key}_{$format->getKiloBitrate()}_%05d.ts");
            $formatOutputPlaylist = Media::make($this->getDisk(), "{$baseName}_{$key}_{$format->getKiloBitrate()}.m3u8");

            $format->setAdditionalParameters(
                [
                    '-sc_threshold',
                    '0',
                    '-g',
                    $this->keyFrameInterval,
                    '-hls_playlist_type',
                    'vod',
                    '-hls_time',
                    $this->segmentLength,
                    '-hls_segment_filename',
                    $formatOutputMedia->getLocalPath(),
                ]
            );

            $this->addFormatOutputMapping($format, $formatOutputPlaylist, ['0']);
        });

        return parent::save();
    }

    public function addFormat(FormatInterface $format)
    {
        if (!$this->pendingFormats) {
            $this->pendingFormats = new Collection;
        }

        $this->pendingFormats->push($format);

        return $this;
    }
}

<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Filters\Video\VideoFilterInterface;
use FFMpeg\Format\VideoInterface;
use FFMpeg\Media\Video;

class SegmentedFilter implements VideoFilterInterface
{
    protected $playlistPath;

    protected $segmentLength;

    protected $priority;

    public function __construct(string $playlistPath, int $segmentLength = 10, $priority = 0)
    {
        $this->playlistPath  = $playlistPath;
        $this->segmentLength = $segmentLength;
        $this->priority      = $priority;
    }

    public function getPlaylistPath(): string
    {
        return $this->playlistPath;
    }

    public function getSegmentLength(): int
    {
        return $this->segmentLength;
    }

    public function getPriority()
    {
        return $this->priority;
    }

    public function apply(Video $video, VideoInterface $format)
    {
        return [
            '-map',
            '0',
            '-flags',
            '-global_header',
            '-f',
            'segment',
            '-segment_format',
            'mpeg_ts',
            '-segment_list',
            $this->getPlaylistPath(),
            '-segment_time',
            $this->getSegmentLength(),
        ];
    }
}

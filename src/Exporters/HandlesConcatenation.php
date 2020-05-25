<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;

trait HandlesConcatenation
{
    /**
     * @var boolean
     */
    protected $concatWithTranscoding = false;

    /**
     * @var boolean
     */
    protected $concatWithVideo = false;

    /**
     * @var boolean
     */
    protected $concatWithAudio = false;

    public function concatWithTranscoding(bool $hasVideo = true, bool $hasAudio = true): self
    {
        $this->concatWithTranscoding = true;
        $this->concatWithVideo       = $hasVideo;
        $this->concatWithAudio       = $hasAudio;

        return $this;
    }

    private function addConcatFilterAndMapping(Media $outputMedia)
    {
        $sources = $this->driver->getMediaCollection()->map(function ($media, $key) {
            return "[{$key}]";
        });

        $concatWithVideo = $this->concatWithVideo ? 1 : 0;
        $concatWithAudio = $this->concatWithAudio ? 1 : 0;

        $this->addFilter(
            $sources->implode(''),
            "concat=n={$sources->count()}:v={$concatWithVideo}:a={$concatWithAudio}",
            '[concat]'
        )->addFormatOutputMapping($this->format, $outputMedia, ['[concat]']);
    }
}

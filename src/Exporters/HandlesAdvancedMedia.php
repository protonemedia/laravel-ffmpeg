<?php

namespace Pbmedia\LaravelFFMpeg\Exporters;

use FFMpeg\Format\FormatInterface;
use Illuminate\Support\Collection;
use Pbmedia\LaravelFFMpeg\FFMpeg\AdvancedOutputMapping;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;

trait HandlesAdvancedMedia
{
    protected Collection $maps;

    public function addFormatOutputMapping(FormatInterface $format, Media $output, array $outs, $forceDisableAudio = false, $forceDisableVideo = false)
    {
        $this->maps->push(
            new AdvancedOutputMapping($outs, $format, $output, $forceDisableAudio, $forceDisableVideo)
        );

        return $this;
    }
}

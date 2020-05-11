<?php

namespace Pbmedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Format\FormatInterface;
use FFMpeg\Media\AdvancedMedia;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;

class AdvancedOutputMapping
{
    public array $outs;
    public FormatInterface $format;
    public Media $output;
    public bool $forceDisableAudio = false;
    public bool $forceDisableVideo = false;

    public function __construct(
        array $outs,
        FormatInterface $format,
        Media $output,
        $forceDisableAudio = false,
        $forceDisableVideo = false
    ) {
        $this->outs              = $outs;
        $this->format            = $format;
        $this->output            = $output;
        $this->forceDisableAudio = $forceDisableAudio;
        $this->forceDisableVideo = $forceDisableVideo;
    }

    public function apply(AdvancedMedia $advancedMedia): self
    {
        $advancedMedia->map($this->outs, $this->format, $this->output->getLocalPath(), $this->forceDisableAudio, $this->forceDisableVideo);

        return $this;
    }

    public function getOutputMedia(): Media
    {
        return $this->output;
    }
}

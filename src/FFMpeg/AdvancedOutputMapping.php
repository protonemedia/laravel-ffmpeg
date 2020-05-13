<?php

namespace Pbmedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Format\FormatInterface;
use FFMpeg\Format\VideoInterface;
use FFMpeg\Media\AdvancedMedia;
use Pbmedia\LaravelFFMpeg\Filesystem\Media;

class AdvancedOutputMapping
{
    private array $outs;
    private FormatInterface $format;
    private Media $output;
    private bool $forceDisableAudio = false;
    private bool $forceDisableVideo = false;

    public function __construct(array $outs, FormatInterface $format, Media $output, $forceDisableAudio = false, $forceDisableVideo = false)
    {
        $this->outs              = $outs;
        $this->format            = $format;
        $this->output            = $output;
        $this->forceDisableAudio = $forceDisableAudio;
        $this->forceDisableVideo = $forceDisableVideo;
    }

    /**
     * Applies the attributes to the format and specifies the video
     * bitrate if it's missing.
     */
    public function apply(AdvancedMedia $advancedMedia): void
    {
        if ($this->format instanceof VideoInterface) {
            $parameters = $this->format->getAdditionalParameters() ?: [];

            if (!in_array('-b:v', $parameters)) {
                $parameters = ['-b:v', $this->format->getKiloBitrate() . 'k'] + $parameters;
            }

            $this->format->setAdditionalParameters($parameters);
        }

        $advancedMedia->map($this->outs, $this->format, $this->output->getLocalPath(), $this->forceDisableAudio, $this->forceDisableVideo);
    }

    public function getFormat(): FormatInterface
    {
        return $this->format;
    }

    public function getOutputMedia(): Media
    {
        return $this->output;
    }

    public function hasOut(string $out): bool
    {
        return in_array($out, $this->outs);
    }
}

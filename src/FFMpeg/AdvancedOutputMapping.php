<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Format\FormatInterface;
use FFMpeg\Format\Video\DefaultVideo;
use FFMpeg\Media\AdvancedMedia;
use Illuminate\Support\Collection;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSVideoFilters;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;

/**
 * This class is basically a wrapper around the map() method
 * of a the \FFMpeg\Media\AdvancedMedia class.
 */
class AdvancedOutputMapping
{
    /**
     * @var array
     */
    private $outs;

    /**
     * @var \FFMpeg\Format\FormatInterface
     */
    private $format;

    /**
     * @var \ProtoneMedia\LaravelFFMpeg\Filesystem\Media
     */
    private $output;

    /**
     * @var boolean
     */
    private $forceDisableAudio = false;

    /**
     * @var boolean
     */
    private $forceDisableVideo = false;

    public function __construct(array $outs, FormatInterface $format, Media $output, bool $forceDisableAudio = false, bool $forceDisableVideo = false)
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
        if ($this->format instanceof DefaultVideo) {
            $parameters = $this->format->getAdditionalParameters() ?: [];

            if (!in_array('-b:v', $parameters) && $this->format->getKiloBitrate() !== 0) {
                $parameters = array_merge(['-b:v', $this->format->getKiloBitrate() . 'k'], $parameters);
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
        return Collection::make($this->outs)
            ->map(function ($out) {
                return HLSVideoFilters::beforeGlue($out);
            })
            ->contains(HLSVideoFilters::beforeGlue($out));
    }
}

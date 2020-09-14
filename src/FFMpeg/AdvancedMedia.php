<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Media\AdvancedMedia as MediaAdvancedMedia;
use Illuminate\Support\Arr;

class AdvancedMedia extends MediaAdvancedMedia
{
    use InteractsWithHttpHeaders;

    public static function make(MediaAdvancedMedia $media)
    {
        return new static($media->getInputs(), $media->getFFMpegDriver(), FFProbe::make($media->getFFProbe()));
    }

    /**
     * @return array
     */
    protected function buildCommand()
    {
        $command = parent::buildCommand();

        $inputKey = array_search(Arr::first($this->getInputs()), $command) - 1;

        foreach ($this->getInputs() as $key => $path) {
            $headers = $this->headers[$key];

            if (empty($headers)) {
                $inputKey += 2;
                continue;
            }

            $command = static::mergeBeforeKey($command, $inputKey, static::compileHeaders($headers));
            $inputKey += 4;
        }

        return $command;
    }
}

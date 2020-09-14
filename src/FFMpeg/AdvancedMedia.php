<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Media\AdvancedMedia as MediaAdvancedMedia;

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

        $inputKey = array_search($this->getPathfile(), $command) - 1;

        foreach ($this->getInputs() as $inputKey => $path) {
            $headers = $this->headers[$inputKey];
            $inputKey += 2;

            if (empty($headers)) {
                continue;
            }

            $command = static::mergeBeforePathInput($command, $path, static::compileHeaders($headers));
            $inputKey += 2;
        }

        return $command;
    }
}

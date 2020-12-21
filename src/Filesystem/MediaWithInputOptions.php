<?php

namespace ProtoneMedia\LaravelFFMpeg\Filesystem;

use ProtoneMedia\LaravelFFMpeg\FFMpeg\InteractsWithInputPath;

class MediaWithInputOptions extends Media
{
    use InteractsWithInputPath;

    /**
     * @var array
     */
    protected $inputOptions = [];

    public function getInputOptions(): array
    {
        return $this->inputOptions;
    }

    public function setInputOptions(array $options = []): self
    {
        $this->inputOptions = $options;

        return $this;
    }

    public function getLocalPath(): string
    {
        $path = parent::getLocalPath();

        if (in_array('-key', $this->inputOptions)) {
            return "crypto:{$path}";
        }

        return $path;
    }
}

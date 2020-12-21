<?php

namespace ProtoneMedia\LaravelFFMpeg\Filesystem;

trait HasInputOptions
{
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

    public function getCompiledInputOptions(): array
    {
        return $this->getInputOptions();
    }
}

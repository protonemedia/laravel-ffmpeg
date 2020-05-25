<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

trait HandlesFrames
{
    /**
     * @var boolean
     */
    protected $mustBeAccurate = false;

    /**
     * @var boolean
     */
    protected $returnFrameContents = false;

    public function accurate(): self
    {
        $this->mustBeAccurate = true;

        return $this;
    }

    public function unaccurate(): self
    {
        $this->mustBeAccurate = false;

        return $this;
    }

    public function getAccuracy(): bool
    {
        return $this->mustBeAccurate;
    }

    public function getFrameContents(): string
    {
        $this->returnFrameContents = true;

        return $this->save();
    }
}

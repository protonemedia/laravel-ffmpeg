<?php

namespace Pbmedia\LaravelFFMpeg;

class FrameExporter extends MediaExporter
{
    protected $mustBeAccurate = false;

    protected $saveMethod = 'saveFrame';

    public function accurate(): MediaExporter
    {
        $this->mustBeAccurate = true;

        return $this;
    }

    public function unaccurate(): MediaExporter
    {
        $this->mustBeAccurate = false;

        return $this;
    }

    public function getAccuracy(): bool
    {
        return $this->mustBeAccurate;
    }

    public function saveFrame(string $fullPath): MediaExporter
    {
        $this->media->save($fullPath, $this->getAccuracy());

        return $this;
    }
}

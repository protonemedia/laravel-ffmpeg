<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

trait HandlesFrames
{
    /**
     * @var bool
     */
    protected $mustBeAccurate = false;

    /**
     * @var bool
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

        $tempFile = sys_get_temp_dir() . '/laravel-ffmpeg-frame-' . uniqid() . '.png';

        return $this->save($tempFile);
    }
}

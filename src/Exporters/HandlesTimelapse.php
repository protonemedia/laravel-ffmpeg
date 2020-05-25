<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

trait HandlesTimelapse
{
    /**
     * @var float
     */
    protected $timelapseFramerate;

    public function asTimelapseWithFramerate(float $framerate): self
    {
        $this->timelapseFramerate = $framerate;

        return $this;
    }

    protected function addTimelapseParametersToFormat()
    {
        $this->format->setInitialParameters(array_merge(
            $this->format->getInitialParameters() ?: [],
            ['-framerate', $this->timelapseFramerate, '-f', 'image2']
        ));
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Closure;
use Evenement\EventEmitterInterface;

trait HasProgressListener
{
    /**
     * @var \Closure
     */
    protected $onProgressCallback;

    /**
     * @var float
     */
    protected $lastPercentage;

    public function onProgress(Closure $callback): self
    {
        $this->onProgressCallback = $callback;

        return $this;
    }

    private function applyProgressListenerToFormat(EventEmitterInterface $format)
    {
        $format->on('progress', function ($video, $format, $percentage) {
            if ($percentage !== $this->lastPercentage && $percentage < 100) {
                $this->lastPercentage = $percentage;
                call_user_func($this->onProgressCallback, $percentage);
            }
        });
    }
}

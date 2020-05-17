<?php

namespace Pbmedia\LaravelFFMpeg\Exporters;

use Closure;
use Evenement\EventEmitterInterface;

trait HasProgressListener
{
    protected ?Closure $onProgressCallback = null;
    protected ?float $lastPercentage       = null;

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

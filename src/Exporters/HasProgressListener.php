<?php

namespace ProtoneMedia\LaravelFFMpeg\Exporters;

use Closure;
use Evenement\EventEmitterInterface;
use ProtoneMedia\LaravelFFMpeg\Events\MediaProcessingProgress;

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

    /**
     * @var float
     */
    protected $lastRemaining = 0;

    /**
     * Setter for the callback.
     */
    public function onProgress(Closure $callback): self
    {
        $this->onProgressCallback = $callback;

        return $this;
    }

    /**
     * Only calls the callback if the percentage is below 100 and is different
     * from the previous emitted percentage.
     *
     * @return void
     */
    private function applyProgressListenerToFormat(EventEmitterInterface $format)
    {
        $format->removeAllListeners('progress');

        $format->on('progress', function ($media, $format, $percentage, $remaining = null, $rate = null) {
            if ($percentage !== $this->lastPercentage && $percentage < 100) {
                $this->lastPercentage = $percentage;
                $this->lastRemaining = $remaining ?: $this->lastRemaining;

                if ($this->onProgressCallback) {
                    call_user_func($this->onProgressCallback, $this->lastPercentage, $this->lastRemaining, $rate);
                }

                if (config('laravel-ffmpeg.enable_events', true)) {
                    MediaProcessingProgress::dispatch(
                        $this->driver->getMediaCollection(),
                        $this->lastPercentage,
                        $this->lastRemaining,
                        $rate,
                        $this->getOutputPath()
                    );
                }
            }
        });
    }
}

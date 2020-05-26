<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\FFProbe;
use FFMpeg\Format\AudioInterface;
use FFMpeg\Format\ProgressableInterface;
use FFMpeg\Format\VideoInterface;
use FFMpeg\Media\MediaTypeInterface;
use Illuminate\Support\Traits\ForwardsCalls;

/**
 * Use this decorator to get access to the AbstractProgressListeners0
 * with the `getListeners` method.
 */
class ProgressListenerDecorator implements ProgressableInterface, AudioInterface
{
    use ForwardsCalls;

    /**
     * @var \FFMpeg\Format\AudioInterface|\FFMpeg\Format\VideoInterface
     */
    protected $format;

    /**
     * @var array
     */
    protected $listeners = [];

    public function __construct($format)
    {
        $this->format = $format;
    }

    public static function decorate($format)
    {
        if ($format instanceof VideoInterface) {
            return new VideoProgressListenerDecorator($format);
        }

        return new static($format);
    }

    public function getListeners(): array
    {
        return $this->listeners;
    }

    public function createProgressListener(MediaTypeInterface $media, FFProbe $ffprobe, $pass, $total, $duration = 0)
    {
        return tap($this->format->createProgressListener(...func_get_args()), function (array $listeners) {
            $this->listeners = array_merge($this->listeners, $listeners);
        });
    }

    public function on($event, callable $listener)
    {
        return $this->format->on(...func_get_args());
    }

    public function once($event, callable $listener)
    {
        return $this->format->once(...func_get_args());
    }

    public function removeListener($event, callable $listener)
    {
        return $this->format->removeListener(...func_get_args());
    }

    public function removeAllListeners($event = null)
    {
        return $this->format->removeAllListeners(...func_get_args());
    }

    public function listeners($event = null)
    {
        return $this->format->listeners(...func_get_args());
    }

    public function emit($event, array $arguments = [])
    {
        return $this->format->emit(...func_get_args());
    }

    public function getPasses()
    {
        return $this->format->getPasses(...func_get_args());
    }

    public function getExtraParams()
    {
        return $this->format->getExtraParams(...func_get_args());
    }

    public function getAudioKiloBitrate()
    {
        return $this->format->getAudioKiloBitrate(...func_get_args());
    }

    public function getAudioChannels()
    {
        return $this->format->getAudioChannels(...func_get_args());
    }

    public function getAudioCodec()
    {
        return $this->format->getAudioCodec(...func_get_args());
    }

    public function getAvailableAudioCodecs()
    {
        return $this->format->getAvailableAudioCodecs(...func_get_args());
    }

    public function __get($key)
    {
        return $this->format->{$key};
    }

    public function __call($method, $arguments)
    {
        return $this->forwardCallTo($this->format, $method, $arguments);
    }
}

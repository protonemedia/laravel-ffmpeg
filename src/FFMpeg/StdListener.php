<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use Alchemy\BinaryDriver\Listeners\ListenerInterface;
use Symfony\Component\Process\Process;

class StdListener implements ListenerInterface
{
    private $data = [
        Process::ERR => [],
        Process::OUT => [],
    ];

    public function handle($type, $data)
    {
        $this->data[$type][] = trim($data);
    }

    public function get(): array
    {
        return $this->data;
    }

    public function forwardedEvents()
    {
        return [];
    }

    public function on($event, callable $listener)
    {
    }

    public function once($event, callable $listener)
    {
    }

    public function removeListener($event, callable $listener)
    {
    }

    public function removeAllListeners($event = null)
    {
    }

    public function listeners($event = null)
    {
    }

    public function emit($event, array $arguments = [])
    {
    }
}

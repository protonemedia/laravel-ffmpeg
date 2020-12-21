<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use Alchemy\BinaryDriver\Listeners\ListenerInterface;
use Evenement\EventEmitterTrait;
use Symfony\Component\Process\Process;

class StdListener implements ListenerInterface
{
    use EventEmitterTrait;

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
}

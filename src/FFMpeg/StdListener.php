<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use Alchemy\BinaryDriver\Listeners\ListenerInterface;
use Evenement\EventEmitter;
use Symfony\Component\Process\Process;

class StdListener extends EventEmitter implements ListenerInterface
{
    /**
     * Name of the emitted event.
     *
     * @var string
     */
    private $eventName;

    public function __construct(string $eventName = 'listen')
    {
        $this->eventName = $eventName;
    }

    private $data = [
        Process::ERR => [],
        Process::OUT => [],
    ];

    public function handle($type, $data)
    {
        $lines = preg_split('/\n|\r\n?/', $data);

        foreach ($lines as $line) {
            $line = $this->data[$type][] = trim($line);

            $this->emit($this->eventName, [$line, $type]);
        }
    }

    public function get(): array
    {
        return $this->data;
    }

    public function forwardedEvents()
    {
        return [$this->eventName];
    }
}

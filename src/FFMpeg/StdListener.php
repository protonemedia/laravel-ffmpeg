<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use Alchemy\BinaryDriver\Listeners\ListenerInterface;
use Evenement\EventEmitter;
use ProtoneMedia\LaravelFFMpeg\Support\ProcessOutput;
use Symfony\Component\Process\Process;

class StdListener extends EventEmitter implements ListenerInterface
{
    const TYPE_ALL = 'all';

    /**
     * Name of the emitted event.
     *
     * @var string
     */
    private $eventName;

    /**
     * Container for the outputted lines.
     *
     * @var array
     */
    private $data = [
        self::TYPE_ALL => [],
        Process::ERR   => [],
        Process::OUT   => [],
    ];

    public function __construct(string $eventName = 'listen')
    {
        $this->eventName = $eventName;
    }

    /**
     * Handler for a new line of data.
     *
     * @param string $type
     * @param string $data
     * @return void
     */
    public function handle($type, $data)
    {
        $lines = preg_split('/\n|\r\n?/', $data);

        foreach ($lines as $line) {
            $this->emit($this->eventName, [$line, $type]);

            $line = trim($line);

            $this->data[$type][] = $line;

            $this->data[static::TYPE_ALL][] = $line;
        }
    }

    /**
     * Returns the collected output lines.
     *
     * @return array
     */
    public function get(): ProcessOutput
    {
        return new ProcessOutput(
            $this->data[static::TYPE_ALL],
            $this->data[Process::ERR],
            $this->data[Process::OUT]
        );
    }

    public function forwardedEvents()
    {
        return [$this->eventName];
    }
}

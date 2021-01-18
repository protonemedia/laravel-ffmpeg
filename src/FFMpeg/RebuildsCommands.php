<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Format\FormatInterface;
use Illuminate\Support\Collection;

trait RebuildsCommands
{
    use InteractsWithBeforeSavingCallbacks;
    use InteractsWithHttpHeaders;

    /**
     * Builds the command using the underlying library and then
     * prepends the input with the headers.
     *
     * @param \FFMpeg\Format\FormatInterface $format
     * @param string $outputPathfile
     * @return array
     */
    protected function buildCommand(FormatInterface $format, $outputPathfile)
    {
        $command = parent::buildCommand($format, $outputPathfile);

        $command = $this->rebuildCommandWithHeaders($command);
        $command = $this->rebuildCommandWithCallbacks($command);

        return $command;
    }

    private function rebuildCommandWithHeaders($command)
    {
        if (empty($this->headers)) {
            return $command;
        }

        return Collection::make($command)->map(function ($command) {
            return static::mergeBeforePathInput(
                $command,
                $this->getPathfile(),
                static::compileHeaders($this->headers)
            );
        })->all();
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Format\FormatInterface;
use Illuminate\Support\Collection;

trait BuildsCommandsWithHttpHeaders
{
    use InteractsWithHttpHeaders;

    protected function buildCommand(FormatInterface $format, $outputPathfile)
    {
        $command = parent::buildCommand($format, $outputPathfile);

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

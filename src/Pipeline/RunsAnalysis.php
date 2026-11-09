<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

use Closure;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\ProcessResult;

/** @internal Runs analysis passes after probing and may replace compiled command tokens before encoding. */
interface RunsAnalysis
{
    /**
     * @param  Closure(list<string>, string): ProcessResult  $analyze  Runs one analysis pass with
     *                                                                 progress and throws the given message on failure.
     */
    public function analyze(Workspace $workspace, array $command, array $outputPaths, Closure $analyze): array;
}

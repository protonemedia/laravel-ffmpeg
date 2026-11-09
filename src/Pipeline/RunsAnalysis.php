<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

use Closure;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/** @internal Runs analysis passes after probing and may replace compiled command tokens before encoding. */
interface RunsAnalysis
{
    /**
     * @param  Closure(list<string>, string): \ProtoneMedia\LaravelFFMpeg\ProcessResult  $analyze  Runs one analysis pass with
     *                                                                                    progress and throws the given message on failure.
     */
    public function analyze(Workspace $workspace, array $command, array $outputPaths, Closure $analyze): array;
}

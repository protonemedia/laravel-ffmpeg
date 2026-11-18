<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/**
 * Limits two-pass encoding to some outputs and strips packaging options from the analysis pass.
 *
 * @internal
 */
interface SplitsTwoPass
{
    /**
     * Get the outputs that take part in two-pass encoding.
     */
    public function twoPassOutputs(array $default, array $outputs): array;

    /**
     * Get the command for the first pass.
     *
     * This runs before PendingExport redirects the outputs to private null targets.
     */
    public function analysisCommand(array $command): array;
}

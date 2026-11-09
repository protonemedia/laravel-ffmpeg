<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/** @internal Limits two-pass encoding to some outputs and strips packaging options from the analysis pass. */
interface SplitsTwoPass
{
    public function twoPassOutputs(array $default, array $outputs): array;

    /** Applied before PendingExport redirects outputs to private null targets. */
    public function analysisCommand(array $command): array;
}

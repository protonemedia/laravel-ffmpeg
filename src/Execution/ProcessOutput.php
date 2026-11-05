<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

/** @internal */
readonly class ProcessOutput
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $diagnostics,
    ) {}
}

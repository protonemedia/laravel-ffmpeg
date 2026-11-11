<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/**
 * Validates resolved stream types before any encoder starts.
 *
 * @internal
 */
interface ValidatesSelections
{
    /**
     * Ensure the resolved stream types are supported.
     *
     * @param  list<list<?string>>  $expected  Ordered stream types per output; null is a graph pad FFmpeg resolves.
     */
    public function selections(array $expected): void;
}

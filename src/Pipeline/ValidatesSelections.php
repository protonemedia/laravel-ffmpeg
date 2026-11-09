<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/** @internal Validates resolved stream types before any encoder starts. */
interface ValidatesSelections
{
    /** @param  list<list<?string>>  $expected  Ordered stream types per output; null is a graph pad FFmpeg resolves. */
    public function selections(array $expected): void;
}

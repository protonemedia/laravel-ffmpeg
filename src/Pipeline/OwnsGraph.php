<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/**
 * Supplies the complex filter graph for an operation that owns it.
 *
 * Callers cannot add their own graph to such an operation.
 *
 * @internal
 */
interface OwnsGraph
{
    /**
     * Get the complex filter graph of the operation.
     */
    public function graph(): ?string;
}

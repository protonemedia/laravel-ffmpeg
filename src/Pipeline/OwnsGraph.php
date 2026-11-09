<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/** @internal Supplies the complex graph for an operation that owns it; callers cannot add one. */
interface OwnsGraph
{
    public function graph(): ?string;
}

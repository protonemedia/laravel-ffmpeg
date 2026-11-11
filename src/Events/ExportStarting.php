<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

/**
 * The event dispatched before an export stages its inputs.
 */
class ExportStarting
{
    /**
     * Create a new event instance.
     */
    public function __construct(public readonly string $operationId, public readonly array $outputs) {}
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

/**
 * The event dispatched before an export stages its inputs.
 */
readonly class ExportStarting
{
    /**
     * Create a new event instance.
     */
    public function __construct(public string $operationId, public array $outputs) {}
}

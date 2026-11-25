<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

/**
 * The event dispatched when an export completes.
 */
class ExportCompleted
{
    /**
     * Create a new event instance.
     */
    public function __construct(public readonly string $operationId, public readonly array $files) {}
}

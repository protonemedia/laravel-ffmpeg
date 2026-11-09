<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

/**
 * The event dispatched when an export completes.
 */
readonly class ExportCompleted
{
    /**
     * Create a new event instance.
     */
    public function __construct(public string $operationId, public array $files) {}
}

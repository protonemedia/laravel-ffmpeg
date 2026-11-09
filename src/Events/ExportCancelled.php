<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

/**
 * The event dispatched when an export is cancelled.
 */
readonly class ExportCancelled
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $operationId,
        public string $phase,
        public array $outputs,
        public array $published,
    ) {}
}

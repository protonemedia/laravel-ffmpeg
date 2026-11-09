<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

/**
 * The event dispatched when an export fails.
 */
readonly class ExportFailed
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

<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

/**
 * The event dispatched when an export fails.
 */
class ExportFailed
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly string $operationId,
        public readonly string $phase,
        public readonly array $outputs,
        public readonly array $published,
    ) {}
}

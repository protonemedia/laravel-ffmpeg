<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

use ProtoneMedia\LaravelFFMpeg\Progress;

/**
 * The event dispatched when a running export reports progress.
 */
class ExportProgress
{
    /**
     * Create a new event instance.
     */
    public function __construct(public readonly string $operationId, public readonly Progress $progress, public readonly array $outputs) {}
}

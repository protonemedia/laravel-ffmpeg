<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

use ProtoneMedia\LaravelFFMpeg\Progress;

/**
 * The event dispatched when a running export reports progress.
 */
readonly class ExportProgress
{
    /**
     * Create a new event instance.
     */
    public function __construct(public string $operationId, public Progress $progress, public array $outputs) {}
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

/**
 * The event dispatched when a queued export completes.
 */
class QueuedExportCompleted
{
    /**
     * Create a new event instance.
     */
    public function __construct(public readonly string $exportId, public readonly array $files, public readonly ?array $playback = null) {}
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

/**
 * The event dispatched when a queued export completes.
 */
readonly class QueuedExportCompleted
{
    /**
     * Create a new event instance.
     */
    public function __construct(public string $exportId, public array $files, public ?array $playback = null) {}
}

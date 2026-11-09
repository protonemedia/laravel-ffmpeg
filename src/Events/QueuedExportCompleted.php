<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Events;

readonly class QueuedExportCompleted
{
    public function __construct(public string $exportId, public array $files, public ?array $playback = null) {}
}

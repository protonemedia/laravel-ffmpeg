<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Events;

use ProtoneMedia\LaravelFFMpeg\Progress;

readonly class ExportProgress
{
    public function __construct(public string $operationId, public Progress $progress, public array $outputs) {}
}

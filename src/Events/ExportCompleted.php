<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Events;

readonly class ExportCompleted
{
    public function __construct(public string $operationId, public array $files) {}
}

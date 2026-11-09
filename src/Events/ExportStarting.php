<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Events;

readonly class ExportStarting
{
    public function __construct(public string $operationId, public array $outputs) {}
}

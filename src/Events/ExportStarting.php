<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

readonly class ExportStarting
{
    public function __construct(public string $operationId, public array $outputs) {}
}

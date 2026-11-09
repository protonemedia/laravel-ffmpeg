<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

readonly class ExportCompleted
{
    public function __construct(public string $operationId, public array $files) {}
}

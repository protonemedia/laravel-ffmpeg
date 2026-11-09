<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

readonly class ExportCancelled
{
    public function __construct(public string $operationId, public string $phase, public array $outputs, public array $published) {}
}

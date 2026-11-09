<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

readonly class ExportFailed
{
    public function __construct(public string $operationId, public string $phase, public array $outputs, public array $published) {}
}

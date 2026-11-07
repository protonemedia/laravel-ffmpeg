<?php

namespace ProtoneMedia\LaravelFFMpeg\Exceptions;

use RuntimeException;

class MediaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $phase,
        public readonly string $category,
        public readonly string $diagnostics = '',
        public readonly array $published = [],
        public readonly array $destination = [],
    ) {
        // Native process and adapter exceptions may contain credentials or full commands.
        parent::__construct($message);
    }
}

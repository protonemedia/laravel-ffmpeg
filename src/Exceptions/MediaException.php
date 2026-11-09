<?php

namespace ProtoneMedia\LaravelFFMpeg\Exceptions;

use RuntimeException;

class MediaException extends RuntimeException
{
    /**
     * @param  list<array{disk: ?string, path: string}>  $published  Objects whose writes succeeded; null disk for filesystem objects.
     * @param  array{disk?: ?string, path?: string}  $destination  The destination that failed, when known.
     */
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

<?php

namespace ProtoneMedia\LaravelFFMpeg;

/**
 * A bounded snapshot of a live HLS stream.
 *
 * The segment paths are the current playlist's references, not the full history.
 */
class LiveHlsResult
{
    /**
     * Create a new live HLS result instance.
     */
    public function __construct(
        public readonly string $playlist,
        public readonly array $segments,
        public readonly string $status,
        public readonly int $attempts,
        public readonly int $publishedSegments,
        public readonly ?int $firstSequence,
        public readonly ?int $lastSequence,
    ) {}
}

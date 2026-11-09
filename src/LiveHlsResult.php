<?php

namespace ProtoneMedia\LaravelFFMpeg;

/**
 * A bounded snapshot of a live HLS stream.
 *
 * The segment paths are the current playlist's references, not the full history.
 */
readonly class LiveHlsResult
{
    /**
     * Create a new live HLS result instance.
     */
    public function __construct(
        public string $playlist,
        public array $segments,
        public string $status,
        public int $attempts,
        public int $publishedSegments,
        public ?int $firstSequence,
        public ?int $lastSequence,
    ) {}
}

<?php

namespace ProtoneMedia\LaravelFFMpeg;

/** A bounded snapshot. Segment paths are the current playlist's references, not all history. */
final readonly class LiveHlsResult
{
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

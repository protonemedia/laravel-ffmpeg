<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use ProtoneMedia\LaravelFFMpeg\Filesystem\MediaCollection;
use Throwable;

class MediaProcessingFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MediaCollection $inputMedia,
        public Throwable $exception,
        public ?string $outputPath = null,
        public array $metadata = []
    ) {
    }
}
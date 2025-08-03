<?php

namespace ProtoneMedia\LaravelFFMpeg\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use ProtoneMedia\LaravelFFMpeg\Filesystem\MediaCollection;

class MediaProcessingProgress
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MediaCollection $inputMedia,
        public float $percentage,
        public ?int $remainingSeconds = null,
        public ?float $rate = null,
        public ?string $outputPath = null
    ) {
    }
}
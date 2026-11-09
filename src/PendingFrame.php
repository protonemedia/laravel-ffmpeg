<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;

class PendingFrame extends PendingImages
{
    private float $at;

    public function __construct(Media $media, string|Filesystem $disk, float|string $at)
    {
        parent::__construct($media, $disk);

        $this->at = $this->clipSeconds($at);

        if (! is_finite($this->at) || $this->at < 0) {
            throw new InvalidArgumentException('A frame timestamp must be finite and nonnegative.');
        }
    }

    public function clip(float|string $start, float|string|null $duration = null, float|string|null $end = null, string $seek = 'accurate'): self
    {
        throw new InvalidArgumentException('Choose the frame timestamp with frame(at:) instead of clipping a single frame.');
    }

    /** Return JPEG bytes without publishing a file. */
    public function get(): string
    {
        $this->video('mjpeg');

        return $this->execute('frame.jpg', contents: true);
    }

    protected function command(array $outputs, array $paths, ?array $outputPaths = null): array
    {
        $command = parent::command($outputs, $paths, $outputPaths);
        array_splice($command, -1, 0, ['-ss', $this->clipTimestamp($this->at), '-frames:v', '1', '-update', '1', '-f', 'image2']);

        return $command;
    }

    protected function prepareEncoding(array $metadata): ?float
    {
        return null;
    }
}

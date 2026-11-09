<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;

class PendingStill extends PendingExport
{
    public function __construct(Media $image, string|Filesystem $disk, private readonly float $duration, int $frameRate)
    {
        if (! is_finite($duration) || $duration <= 0 || $frameRate < 1 || $frameRate > 240) {
            throw new InvalidArgumentException('Still video requires a finite positive duration and a frame rate between 1 and 240.');
        }
        parent::__construct(new Media($disk, new StillInput($image, $frameRate)), $disk);
        $this->video('libx264')->outputOptions(['-pix_fmt', 'yuv420p']);
    }

    public function output(string $path, callable $configure): self
    {
        throw new InvalidArgumentException('Still video supports one root-configured output.');
    }

    public function clip(float|string $start, float|string|null $duration = null, float|string|null $end = null, string $seek = 'accurate'): self
    {
        throw new InvalidArgumentException('Set the still duration with still() instead of clipping an infinite image input.');
    }

    protected function command(array $outputs, array $paths, ?array $outputPaths = null): array
    {
        $command = parent::command($outputs, $paths, $outputPaths);
        array_splice($command, -1, 0, ['-t', CommandCompiler::timestamp($this->duration)]);

        return $command;
    }

    protected function prepareEncoding(array $metadata): ?float
    {
        parent::prepareEncoding($metadata);

        return $this->duration;
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Support\ImageOutput;

/** @internal Shared image encoding and validation, not a separate operation. */
abstract class PendingImages extends PendingExport
{
    protected string $outputRole = 'image';

    public function __construct(Media $media, string|Filesystem $disk, string $map = '0:V:0')
    {
        parent::__construct($media, $disk);

        $this->map($map)->withoutMetadata(chapters: true)->video('mjpeg');
    }

    public function addInput(Media $media): int
    {
        throw new InvalidArgumentException('Image exports read one input; use export() for compositions.');
    }

    public function save(?string $path = null): ExportResult
    {
        $this->video(ImageOutput::codec($path));

        return parent::save($path);
    }

    protected function validateArtifact(string $path, string $role = 'image'): int
    {
        $size = parent::validateArtifact($path, $role);
        ImageOutput::validate($path, $role);

        return $size;
    }

    protected function unclippedOutputs(?string $path): array
    {
        return ImageOutput::unclipped($this->outputConfiguration(), $path);
    }

    protected function assertNoTrackVideoFilters(ExportOutput $output): void
    {
        ImageOutput::assertNoTrackVideoFilters($output);
    }

    protected function sequenceDestination(string $path): string
    {
        return ImageOutput::sequenceDestination($path);
    }

    protected function sequenceArtifacts(ExportOutput $output, string $path, ?int $expected = null): array
    {
        return ImageOutput::sequenceArtifacts($output, $path, $expected);
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

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
        $codec = match (strtolower(pathinfo($path ?? '', PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'mjpeg',
            'png' => 'png',
            default => throw new InvalidArgumentException('An image destination must have a jpg, jpeg, or png extension.'),
        };
        $this->video($codec);

        return parent::save($path);
    }

    protected function validateArtifact(string $path, string $role = 'image'): int
    {
        $size = parent::validateArtifact($path, $role);
        if ($role !== 'image') {
            return $size;
        }

        $image = @getimagesize($path);
        $type = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;

        if ($image === false || $image[0] <= 0 || $image[1] <= 0 || $image[2] !== $type) {
            throw new MediaException('FFmpeg did not produce the requested image format.', Progress::VALIDATION, 'artifact');
        }

        return $size;
    }

    /** For operations that trim inside their own filter, as GIF does: the output itself carries no seek or duration. */
    protected function unclippedOutputs(?string $path): array
    {
        $settings = $this->outputConfiguration();
        $settings['clip'] = null;

        return [(new ExportOutput($path ?? 'inspection.mp4', $settings['disk']))->configure($settings)];
    }

    /** A per-track -filter:v:N would replace, or be replaced by, the sampling filter appended to -filter:v. */
    protected function assertNoTrackVideoFilters(ExportOutput $output): void
    {
        if (array_any($output->outputConfiguration()['videoStreams'], fn ($track) => isset($track['filters']))) {
            throw new InvalidArgumentException('Sampled image operations do not support per-track video filters; add filters to the operation itself.');
        }
    }

    protected function sequenceDestination(string $path): string
    {
        $path = $this->configuredOutputs($path)[0]->destination()['path'];

        if (! preg_match('/\A[^%]*%(?:0[1-9])?d[^%]*\z/', $path)
            || str_contains(dirname($path), '%')) {
            throw new InvalidArgumentException('A sequence destination requires exactly one %d or %01d to %09d placeholder in its filename.');
        }

        return $path;
    }

    protected function sequenceArtifacts(ExportOutput $output, string $path, ?int $expected = null): array
    {
        $files = glob(str_replace('%08d', '*', $path)) ?: [];
        sort($files, SORT_NATURAL);

        if ($files === [] || ($expected !== null && count($files) !== $expected)) {
            throw new MediaException('FFmpeg did not produce the expected image sequence.', Progress::VALIDATION, 'artifact');
        }

        $destination = $output->destination();
        $artifacts = [];

        foreach ($files as $index => $file) {
            if ($file !== sprintf($path, $index + 1)) {
                throw new MediaException('The generated image sequence is not contiguous.', Progress::VALIDATION, 'artifact');
            }

            $artifacts[] = ['path' => $file, 'output' => 0, 'destination' => [
                'disk' => $destination['disk'], 'path' => sprintf($destination['path'], $index + 1),
            ]];
        }

        return $artifacts;
    }
}

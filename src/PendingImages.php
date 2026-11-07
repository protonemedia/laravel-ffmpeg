<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

/** @internal Shared image encoding and validation, not a separate operation. */
abstract class PendingImages extends PendingExport
{
    protected string $outputRole = 'image';

    public function __construct(Media $media, string|Filesystem $disk)
    {
        parent::__construct($media, $disk);

        $this->map('0:V:0')->withoutMetadata(chapters: true)->video('mjpeg');
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

    protected function validateArtifact(string $path): int
    {
        $size = parent::validateArtifact($path);
        $image = @getimagesize($path);
        $type = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;

        if ($image === false || $image[0] <= 0 || $image[1] <= 0 || $image[2] !== $type) {
            throw new MediaException('FFmpeg did not produce the requested image format.', Progress::VALIDATION, 'artifact');
        }

        return $size;
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

class PendingFrame extends PendingExport
{
    protected string $outputRole = 'image';

    private float $at;

    public function __construct(Media $media, string|Filesystem $disk, float|string $at)
    {
        parent::__construct($media, $disk);

        $this->at = $this->clipSeconds($at);

        if (! is_finite($this->at) || $this->at < 0) {
            throw new InvalidArgumentException('A frame timestamp must be finite and nonnegative.');
        }

        $this->map('0:V:0')->withoutMetadata(chapters: true)->video('mjpeg');
    }

    public function save(?string $path = null): ExportResult
    {
        $codec = match (strtolower(pathinfo($path ?? '', PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'mjpeg',
            'png' => 'png',
            default => throw new InvalidArgumentException('A frame destination must have a jpg, jpeg, or png extension.'),
        };
        $this->video($codec);

        return parent::save($path);
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

    protected function encodingDuration(array $metadata): ?float
    {
        return null;
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

<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\ExportOutput;
use ProtoneMedia\LaravelFFMpeg\Progress;

/** @internal Stateless image-output rules shared by frame, frames, storyboard and waveform builders. */
final class ImageOutput
{
    /** The encoder for a destination's image extension. */
    public static function codec(?string $path): string
    {
        return match (strtolower(pathinfo($path ?? '', PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'mjpeg',
            'png' => 'png',
            default => throw new InvalidArgumentException('An image destination must have a jpg, jpeg, or png extension.'),
        };
    }

    /** Require image artifacts to decode as the format their extension names. Other roles pass unchanged. */
    public static function validate(string $path, string $role): void
    {
        if ($role !== 'image') {
            return;
        }

        $image = @getimagesize($path);
        $type = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;

        if ($image === false || $image[0] <= 0 || $image[1] <= 0 || $image[2] !== $type) {
            throw new MediaException('FFmpeg did not produce the requested image format.', Progress::VALIDATION, 'artifact');
        }
    }

    /** For operations that trim inside their own filter: the output itself carries no seek or duration. */
    public static function unclipped(array $settings, ?string $path): array
    {
        $settings['clip'] = null;

        return [new ExportOutput($path ?? 'inspection.mp4', $settings['disk'])->configure($settings)];
    }

    /** A per-track -filter:v:N would replace, or be replaced by, the sampling filter appended to -filter:v. */
    public static function assertNoTrackVideoFilters(ExportOutput $output): void
    {
        if (array_any($output->outputConfiguration()['videoStreams'], fn ($track): bool => isset($track['filters']))) {
            throw new InvalidArgumentException('Sampled image operations do not support per-track video filters; add filters to the operation itself.');
        }
    }

    /** Normalize a destination pattern and require exactly one frame-number placeholder in its filename. */
    public static function sequenceDestination(string $path): string
    {
        $path = new ExportOutput($path, 'local')->destination()['path'];

        if (! preg_match('/\A[^%]*%(?:0[1-9])?d[^%]*\z/', $path)
            || str_contains(dirname($path), '%')) {
            throw new InvalidArgumentException('A sequence destination requires exactly one %d or %01d to %09d placeholder in its filename.');
        }

        return $path;
    }

    /** Turn a contiguous %08d sequence in the workspace into artifacts published at the output's pattern. */
    public static function sequenceArtifacts(ExportOutput $output, string $path, ?int $expected = null): array
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

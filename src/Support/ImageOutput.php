<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\ExportOutput;

/**
 * The image output rules shared by the frame, frames, storyboard and waveform builders.
 *
 * @internal
 */
class ImageOutput
{
    /**
     * Get the encoder for the image extension of the given destination.
     */
    public static function codec(?string $path): string
    {
        return match (strtolower(pathinfo($path ?? '', PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'mjpeg',
            'png' => 'png',
            default => throw new InvalidArgumentException(
                'An image destination must have a jpg, jpeg, or png extension.'
            ),
        };
    }

    /**
     * Ensure an image artifact decodes as the format its extension names.
     *
     * Artifacts with any other role pass unchanged.
     */
    public static function validate(string $path, string $role): void
    {
        if ($role !== 'image') {
            return;
        }

        $image = @getimagesize($path);
        $type = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;

        if ($image === false || $image[0] <= 0 || $image[1] <= 0 || $image[2] !== $type) {
            throw MediaException::validation('FFmpeg did not produce the requested image format.', 'artifact');
        }
    }

    /**
     * Get the outputs for an operation that trims inside its own filter.
     *
     * The output itself carries no seek or duration.
     */
    public static function unclipped(array $settings, ?string $path): array
    {
        $settings['clip'] = null;

        return [new ExportOutput($path ?? 'inspection.mp4', $settings['disk'])->configure($settings)];
    }

    /**
     * Ensure the output has no per-track video filters.
     *
     * A per-track -filter:v:N would replace, or be replaced by, the sampling filter appended to -filter:v.
     */
    public static function assertNoTrackVideoFilters(ExportOutput $output): void
    {
        if (array_any($output->outputConfiguration()['videoStreams'], fn ($track): bool => isset($track['filters']))) {
            throw new InvalidArgumentException(
                'Sampled image operations do not support per-track video filters; add filters to the operation itself.'
            );
        }
    }

    /**
     * Normalize a destination pattern with exactly one frame number placeholder in its filename.
     */
    public static function sequenceDestination(string $path): string
    {
        $path = new ExportOutput($path, 'local')->destination()['path'];

        if (! preg_match('/\A[^%]*%(?:0[1-9])?d[^%]*\z/', $path)
            || str_contains(dirname($path), '%')) {
            throw new InvalidArgumentException(
                'A sequence destination requires exactly one %d or %01d to %09d placeholder in its filename.'
            );
        }

        return $path;
    }

    /**
     * Get the artifacts for a contiguous %08d sequence, published at the output's pattern.
     */
    public static function sequenceArtifacts(ExportOutput $output, string $path, ?int $expected = null): array
    {
        $files = glob(str_replace('%08d', '*', $path)) ?: [];

        sort($files, SORT_NATURAL);

        if ($files === [] || ($expected !== null && count($files) !== $expected)) {
            throw MediaException::validation('FFmpeg did not produce the expected image sequence.', 'artifact');
        }

        $destination = $output->destination();
        $artifacts = [];

        foreach ($files as $index => $file) {
            if ($file !== sprintf($path, $index + 1)) {
                throw MediaException::validation('The generated image sequence is not contiguous.', 'artifact');
            }

            $artifacts[] = ['path' => $file, 'output' => 0, 'destination' => [
                'disk' => $destination['disk'], 'path' => sprintf($destination['path'], $index + 1),
            ]];
        }

        return $artifacts;
    }
}

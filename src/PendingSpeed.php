<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

/**
 * Pitch-preserving playback speed. Clips and input timing options are unsupported.
 * Sources require a zero container/video origin and nonnegative audio origins.
 * Trusted simple filters run first; their timing changes make progress indeterminate.
 */
class PendingSpeed extends PendingExport
{
    public function __construct(private readonly Media $source, string|Filesystem $disk, private readonly float $factor)
    {
        if (! is_finite($factor) || $factor <= 0) {
            throw new InvalidArgumentException('Speed requires a finite positive factor.');
        }

        parent::__construct($source, $disk);
    }

    public function addInput(Media $media): int
    {
        throw new InvalidArgumentException('Speed supports one input.');
    }

    public function output(string $path, callable $configure): self
    {
        throw new InvalidArgumentException('Speed supports one root-configured output.');
    }

    public function filterComplex(string $expression): self
    {
        throw new InvalidArgumentException('Speed owns its timing filters; custom graphs are unsupported.');
    }

    public function watermark(Media $image, string $position = 'bottom-right', int $x = 0, int $y = 0, ?int $width = null, ?int $height = null, float $opacity = 1): self
    {
        throw new InvalidArgumentException('Speed does not support additional watermark inputs.');
    }

    protected function configuredOutputs(?string $path = null): array
    {
        $settings = $this->outputConfiguration();

        if ($settings['clip'] !== null || $this->source->inputOptions() !== []) {
            throw new InvalidArgumentException('Speed requires an unclipped source without input options.');
        }

        $factor = self::number($this->factor);
        if (! $settings['omitVideo']) {
            $settings['videoFilters'][] = 'setpts=PTS/'.$factor;
        }
        if (! $settings['omitAudio']) {
            // atempo preserves its first timestamp, then generates timestamps at
            // the new sample rate. Scale only that initial offset beforehand.
            $settings['audioFilters'][] = 'asetpts=PTS-STARTPTS+STARTPTS/'.$factor;
            $remaining = $this->factor;
            while ($remaining > 2) {
                $settings['audioFilters'][] = 'atempo=2';
                $remaining /= 2;
            }
            while ($remaining < 0.5) {
                $settings['audioFilters'][] = 'atempo=0.5';
                $remaining *= 2;
            }
            $settings['audioFilters'][] = 'atempo='.self::number($remaining);
        }

        // Source chapter timestamps cannot describe a retimed presentation.
        $settings['chapterSource'] ??= -1;

        return [(new ExportOutput($path ?? 'inspection.mp4', $settings['disk']))->configure($settings)];
    }

    protected function validateStreamSelections(array $expected): void
    {
        if ($expected[0] === [] || array_any($expected[0], fn ($type) => ! in_array($type, ['audio', 'video'], true))) {
            throw new MediaException('Speed requires selected audio or video streams only.', 'probing', 'mapping');
        }
    }

    protected function prepareEncoding(array $metadata): ?float
    {
        $item = $metadata[0];
        // The audio filter scales an initial offset, so audio-only sources may start later.
        PendingExport::assertZeroOrigins($item, 'Speed', audioOnlyOffset: true);

        // Conservatively validate every source audio track, including unselected
        // tracks, rather than guessing how arbitrary stream selectors resolve.
        foreach ($item->audioStreams() as $stream) {
            $start = $stream->get('start_time');
            if (! is_numeric($start) || ! is_finite((float) $start) || (float) $start < 0) {
                throw new MediaException('Speed requires nonnegative audio origins on all source tracks.', 'probing', 'metadata');
            }
        }

        $settings = $this->outputConfiguration();
        // Maps can select a shorter track; raw filters/options can alter timing.
        if ($settings['maps'] !== [] || $settings['omitVideo'] || $settings['omitAudio']
            || $settings['videoFilters'] !== [] || $settings['audioFilters'] !== [] || $settings['outputOptions'] !== []) {
            return null;
        }

        if ($item->videoStreams()->count() > 1 || $item->audioStreams()->count() > 1
            || $item->streams()->contains(fn ($stream) => ! in_array($stream->type(), ['audio', 'video'], true) || $stream->isCoverArt())) {
            return null;
        }

        $duration = $item->duration();
        $adjusted = $duration === null ? null : $duration / $this->factor;

        return $adjusted !== null && is_finite($adjusted) ? $adjusted : null;
    }

    private static function number(float $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}

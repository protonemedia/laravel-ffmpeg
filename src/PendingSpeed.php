<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\MeasuresDuration;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesOutputs;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ValidatesSelections;

/**
 * A pitch-preserving change of playback speed.
 *
 * Clips and input timing options are unsupported. Sources require a zero container and video origin
 * and nonnegative audio origins. Trusted simple filters run first, and their timing changes make
 * progress indeterminate.
 */
class PendingSpeed implements Exportable, MeasuresDuration, ShapesOutputs, ValidatesSelections
{
    use Concerns\AudioCodec;
    use Concerns\AudioFilters;
    use Concerns\Fades;
    use Concerns\GlobalOptions;
    use Concerns\Mapping;
    use Concerns\Metadata;
    use Concerns\OutputOptions;
    use Concerns\ResourceFilters;
    use Concerns\Resources;
    use Concerns\RunsExport;
    use Concerns\VideoCodec;
    use Concerns\VideoFilters;
    use Conditionable, Macroable;

    /**
     * The export pipeline this builder configures.
     */
    protected readonly PendingExport $export;

    /**
     * Create a new pending speed instance.
     */
    public function __construct(
        protected readonly Media $source,
        string|Filesystem $disk,
        protected readonly float $factor,
    ) {
        if (! is_finite($factor) || $factor <= 0) {
            throw new InvalidArgumentException('Speed requires a finite positive factor.');
        }

        $this->export = new PendingExport($source, $disk, $this);
    }

    /**
     * Encode the output in two passes.
     */
    public function twoPass(): static
    {
        $this->export->twoPass();

        return $this;
    }

    /**
     * Save the retimed media to the given path.
     */
    public function save(?string $path = null): ExportResult
    {
        return $this->export->save($path);
    }

    /**
     * Append the filters that retime the video and audio by the speed factor.
     *
     * @internal
     */
    public function outputs(array $default, ?string $path): array
    {
        $settings = $this->export->outputConfiguration();

        if ($this->source->inputOptions() !== []) {
            throw new InvalidArgumentException('Speed requires a source without input options.');
        }

        $factor = $this->number($this->factor);
        if (! $settings['omitVideo']) {
            // Dividing timestamps in a coarse source time base, such as 1/25, would give two
            // frames the same timestamp. We will retime in microseconds and let the encoder
            // inherit that time base instead of the source frame rate.
            $settings['videoFilters'][] = "settb=AVTB,setpts=PTS/{$factor}";
            $settings['outputOptions'][] = '-enc_time_base:v';
            $settings['outputOptions'][] = 'filter';
        }

        if (! $settings['omitAudio']) {
            // The atempo filter keeps its first timestamp and then generates timestamps at the
            // new sample rate, so we will scale only that initial offset beforehand.
            $settings['audioFilters'][] = "asetpts=PTS-STARTPTS+STARTPTS/{$factor}";
            $remaining = $this->factor;
            while ($remaining > 2) {
                $settings['audioFilters'][] = 'atempo=2';
                $remaining /= 2;
            }

            while ($remaining < 0.5) {
                $settings['audioFilters'][] = 'atempo=0.5';
                $remaining *= 2;
            }

            $settings['audioFilters'][] = "atempo={$this->number($remaining)}";
        }

        // Source chapter timestamps cannot describe a retimed presentation.
        $settings['chapterSource'] ??= -1;

        return [new ExportOutput($path ?? 'inspection.mp4', $settings['disk'])->configure($settings)];
    }

    /**
     * Ensure the output selects at least one stream and only audio and video streams.
     *
     * @internal
     */
    public function selections(array $expected): void
    {
        if ($expected[0] === []) {
            throw MediaException::probing('Speed requires at least one selected stream.', 'mapping');
        }

        if (array_any($expected[0], fn ($type): bool => ! in_array($type, ['audio', 'video'], true))) {
            throw MediaException::probing('Speed supports only audio and video streams.', 'mapping');
        }
    }

    /**
     * Get the retimed duration when nothing else can change the timing.
     *
     * @internal
     */
    public function duration(array $metadata, ?float $default): ?float
    {
        $item = $metadata[0];
        // The audio filter scales an initial offset, so audio-only sources may start later.
        PendingExport::assertZeroOrigins($item, 'Speed', audioOnlyOffset: true);

        // We conservatively validate every source audio track, including unselected tracks,
        // rather than guessing how arbitrary stream selectors resolve.
        if ($item->audioStreams()->contains(function ($stream): bool {
            $start = $stream->get('start_time');

            return ! is_numeric($start) || ! is_finite((float) $start) || (float) $start < 0;
        })) {
            throw MediaException::probing(
                'Speed requires nonnegative audio origins on all source tracks.',
                'metadata',
            );
        }

        $settings = $this->export->outputConfiguration();
        // Maps can select a shorter track, and raw filters or options can alter the timing.
        if ($settings['maps'] !== []
            || $settings['omitVideo']
            || $settings['omitAudio']
            || $settings['videoFilters'] !== []
            || $settings['audioFilters'] !== []
            || $settings['outputOptions'] !== []) {
            return null;
        }

        if ($item->videoStreams()->count() > 1 || $item->audioStreams()->count() > 1
            || $item->streams()->contains(
                fn ($stream): bool => ! in_array($stream->type(), ['audio', 'video'], true) || $stream->isCoverArt(),
            )) {
            return null;
        }

        $duration = $item->duration();
        $adjusted = $duration === null ? null : $duration / $this->factor;

        return $adjusted !== null && is_finite($adjusted) ? $adjusted : null;
    }

    /**
     * Format the given number for a filter argument.
     */
    protected function number(float $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}

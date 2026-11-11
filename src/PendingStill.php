<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\MeasuresDuration;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesCommand;

/**
 * A silent video that holds one image for a fixed duration.
 */
class PendingStill implements Exportable, MeasuresDuration, ShapesCommand
{
    use Concerns\Fades;
    use Concerns\GlobalOptions;
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
     * Create a new pending still instance.
     */
    public function __construct(
        Media $image,
        string|Filesystem $disk,
        protected readonly float $duration,
        int $frameRate,
    ) {
        if (! is_finite($duration) || $duration <= 0) {
            throw new InvalidArgumentException('The still duration must be finite and positive.');
        }

        if ($frameRate < 1 || $frameRate > 240) {
            throw new InvalidArgumentException("The still frame rate [{$frameRate}] must be between 1 and 240.");
        }

        $this->export = new PendingExport(new Media($disk, new StillInput($image, $frameRate)), $disk, $this);

        $this->export->video('libx264')->outputOptions(['-pix_fmt', 'yuv420p']);
    }

    /**
     * Map the given input stream specifier or graph label to the output.
     */
    public function map(string $specifier): static
    {
        $this->export->map($specifier);

        return $this;
    }

    /**
     * Map a video stream from the given input to the output.
     */
    public function mapVideo(
        Stream|int|null $stream = null,
        ?int $input = null,
        ?int $track = null,
        bool $optional = false,
    ): static {
        $this->export->mapVideo($stream, $input, $track, $optional);

        return $this;
    }

    /**
     * Save the video to the given path.
     */
    public function save(?string $path = null): ExportResult
    {
        return $this->export->save($path);
    }

    /**
     * Limit the output to the duration of the still.
     *
     * @internal
     */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        array_splice($default, -1, 0, ['-t', CommandCompiler::timestamp($this->duration)]);

        return $default;
    }

    /**
     * Get the duration of the still in seconds.
     *
     * @internal
     */
    public function duration(array $metadata, ?float $default): float
    {
        return $this->duration;
    }
}

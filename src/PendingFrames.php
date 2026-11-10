<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\MeasuresDuration;
use ProtoneMedia\LaravelFFMpeg\Pipeline\PackagesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesCommand;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ValidatesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Support\ImageOutput;

/**
 * A numbered JPEG or PNG sequence sampled at an interval or as a fixed count.
 */
class PendingFrames implements Exportable, MeasuresDuration, PackagesArtifacts, ShapesCommand, ValidatesArtifacts
{
    use Concerns\Clips;
    use Concerns\Fades;
    use Concerns\GlobalOptions;
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
     * The clipped media duration in seconds, once the source has been probed.
     */
    protected ?float $duration = null;

    /**
     * Create a new pending frames instance.
     */
    public function __construct(
        Media $media,
        string|Filesystem $disk,
        protected readonly ?float $every,
        protected readonly ?int $count,
    ) {
        if (($every === null) === ($count === null)) {
            throw new InvalidArgumentException('Frame extraction requires exactly one of an interval or a count.');
        }

        if ($every !== null && (! is_finite($every) || $every < 0.000001)) {
            throw new InvalidArgumentException(
                'The frame interval must be finite and at least one microsecond.'
            );
        }

        if ($count !== null && $count < 1) {
            throw new InvalidArgumentException("The frame count [{$count}] must be positive.");
        }

        $this->export = new PendingExport($media, $disk, $this, 'image');

        $this->export->map('0:V:0')->withoutMetadata(chapters: true)->video('mjpeg');
    }

    /**
     * Save the frames using the given destination pattern.
     */
    public function save(?string $path = null): ExportResult
    {
        if ($path === null) {
            throw new InvalidArgumentException('An image sequence requires a destination pattern.');
        }

        $path = ImageOutput::sequenceDestination($path);

        $this->export->video(ImageOutput::codec($path));

        return $this->export->save($path);
    }

    /**
     * Record the clipped duration that count-based sampling spreads frames over.
     * Progress still uses the source duration.
     *
     * @internal
     */
    public function duration(array $metadata, ?float $default): ?float
    {
        $this->duration = $metadata[0]->duration();
        $clip = $this->export->clipSettings();

        if ($this->duration !== null && $clip !== null) {
            $this->duration = max(0, min($clip['duration'], $this->duration - $clip['start']));
        }

        if ($this->count !== null
            && ($this->duration === null || CommandCompiler::timestamp($this->duration) === '0')) {
            throw MediaException::probing(
                'Count-based frame extraction requires a known, positive media duration.',
                'metadata',
            );
        }

        return $metadata[0]->duration();
    }

    /**
     * Sample the frames with the fps filter and write them as a numbered image sequence.
     *
     * @internal
     */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        ImageOutput::assertNoTrackVideoFilters($outputs[0]);

        $rate = match (true) {
            $this->every !== null => '1/'.CommandCompiler::timestamp($this->every),
            $this->duration === null => "{$this->count}/[duration]",
            default => "{$this->count}/".CommandCompiler::timestamp($this->duration),
        };
        $start = $this->export->inputSeekOptions() === [] ? ($this->export->clipSettings()['start'] ?? 0) : 0;
        $filter = "fps=fps={$rate}:start_time=".CommandCompiler::timestamp($start).':round=near:eof_action=pass';

        if ($this->count !== null) {
            $filter .= ",tpad=stop_mode=clone:stop={$this->count}";
        }

        $index = array_search('-filter:v', $default, true);

        if ($index === false) {
            array_splice($default, -1, 0, ['-filter:v', $filter]);
        } else {
            $default[$index + 1] .= ",{$filter}";
        }

        $options = ['-f', 'image2', '-start_number', '1'];

        if ($this->count !== null) {
            $options[] = '-frames:v';
            $options[] = (string) $this->count;
        }

        array_splice($default, -1, 0, $options);

        return $default;
    }

    /**
     * Get the private path pattern that FFmpeg writes the frames to.
     *
     * @internal
     */
    public function outputPaths(array $default, array $outputs, Workspace $workspace): array
    {
        $extension = pathinfo($outputs[0]->destination()['path'], PATHINFO_EXTENSION);

        return ["{$workspace->path}/frame-%08d.{$extension}"];
    }

    /**
     * Get the numbered frames to validate and publish.
     *
     * @internal
     */
    public function artifacts(array $default, array $outputs, array $paths): array
    {
        return ImageOutput::sequenceArtifacts($outputs[0], $paths[0], $this->count);
    }

    /**
     * Ensure each encoded frame is a valid image.
     *
     * @internal
     */
    public function artifact(string $path, string $role, int $size): void
    {
        ImageOutput::validate($path, $role);
    }
}

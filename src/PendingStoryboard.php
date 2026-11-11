<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
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
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesOutputs;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ValidatesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Support\ImageOutput;
use Throwable;

/**
 * Tiled thumbnail sheets sampled at a fixed interval, with optional WebVTT cues.
 */
class PendingStoryboard implements Exportable, MeasuresDuration, PackagesArtifacts, ShapesCommand, ShapesOutputs, ValidatesArtifacts
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
     * The sampling interval in milliseconds.
     */
    protected readonly int $interval;

    /**
     * The clipped media duration in microseconds, once the source has been probed.
     */
    protected ?int $duration = null;

    /**
     * The number of thumbnails, once the source has been probed.
     */
    protected ?int $frames = null;

    /**
     * The destination path of the WebVTT cues.
     */
    protected ?string $vttPath = null;

    /**
     * The callback that resolves the URL of each sheet for the cues.
     */
    protected ?Closure $imageUrl = null;

    /**
     * Create a new pending storyboard instance.
     */
    public function __construct(
        Media $media,
        string|Filesystem $disk,
        float $every,
        protected readonly int $columns,
        protected readonly int $rows,
        protected readonly int $margin,
        protected readonly int $padding,
    ) {
        if (! is_finite($every) || $every < 0.001 || $every > intdiv(PHP_INT_MAX, 1000000)
            || abs($every * 1000 - round($every * 1000)) > 0.000001) {
            throw new InvalidArgumentException(
                'The storyboard interval must be a positive whole number of milliseconds.'
            );
        }

        if ($columns < 1 || $rows < 1 || $columns > intdiv(PHP_INT_MAX, $rows)) {
            throw new InvalidArgumentException(
                "The storyboard grid [{$columns}x{$rows}] must have a positive, representable number of cells."
            );
        }

        if ($margin < 0) {
            throw new InvalidArgumentException("The storyboard margin [{$margin}] must not be negative.");
        }

        if ($padding < 0) {
            throw new InvalidArgumentException("The storyboard padding [{$padding}] must not be negative.");
        }

        $this->interval = (int) round($every * 1000);
        $this->export = new PendingExport($media, $disk, $this, 'image');

        $this->export
            ->map('0:V:0')
            ->withoutMetadata(chapters: true)
            ->video('mjpeg')
            ->videoFilter(ImageOutput::SQUARE_PIXELS);
    }

    /**
     * Write WebVTT cues for the sheets to the given path, optionally resolving sheet URLs with a callback.
     */
    public function vtt(string $path, ?callable $imageUrl = null): static
    {
        $this->export->assertOutputMutable();

        // We will validate the cue path with the same rules as any output destination.
        $path = new ExportOutput($path, 'local')->destination()['path'];

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'vtt') {
            throw new InvalidArgumentException('The cue destination must have a vtt extension.');
        }

        $this->vttPath = $path;
        $this->imageUrl = $imageUrl === null ? null : Closure::fromCallable($imageUrl);

        return $this;
    }

    /**
     * Save the sheets using the given destination pattern.
     */
    public function save(?string $path = null): ExportResult
    {
        if ($path === null) {
            throw new InvalidArgumentException('A storyboard requires an image destination pattern.');
        }

        $path = ImageOutput::sequenceDestination($path);

        $this->export->video(ImageOutput::codec($path));

        return $this->export->save($path);
    }

    /**
     * Record the clipped duration and thumbnail count, and get the duration for progress.
     *
     * @internal
     */
    public function duration(array $metadata, ?float $default): float
    {
        $duration = $metadata[0]->duration();
        $clip = $this->export->clipSettings();

        if ($duration !== null && $clip !== null) {
            $duration = max(0, min($clip['duration'], $duration - $clip['start']));
        }

        if ($duration === null || $duration <= 0 || $duration > intdiv(PHP_INT_MAX, 1000000)
            || ($this->duration = (int) round($duration * 1000000)) < 1) {
            throw MediaException::probing('Storyboards require a known, positive media duration.', 'metadata');
        }

        $this->frames = intdiv($this->duration - 1, $this->interval * 1000) + 1;

        return $duration;
    }

    /**
     * Get the outputs without an output-level clip, so thumbnails are bounded before tiling.
     * An output-level -t would bound sheet timestamps and allow thumbnails beyond a clip's end.
     *
     * @internal
     */
    public function outputs(array $default, ?string $path): array
    {
        return ImageOutput::unclipped($this->export->outputConfiguration(), $path);
    }

    /**
     * Trim, sample and tile the thumbnails into numbered sheets.
     *
     * @internal
     */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        $command = $default;

        if ($this->export->inputSeekOptions() !== []) {
            throw new InvalidArgumentException('Storyboards require accurate clipping, not keyframe seek.');
        }

        ImageOutput::assertNoTrackVideoFilters($outputs[0]);

        $start = $this->export->clipSettings()['start'] ?? 0;
        $end = $this->duration === null ? '[end]' : CommandCompiler::timestamp($start + $this->duration / 1000000);
        $filter = 'trim=start='.CommandCompiler::timestamp($start).":end={$end},setpts=PTS-STARTPTS,"
            .'fps=fps=1/'.CommandCompiler::timestamp($this->interval / 1000).':start_time=0:round=near:eof_action=pass,'
            ."tile=layout={$this->columns}x{$this->rows}:nb_frames=".($this->columns * $this->rows)
            .":margin={$this->margin}:padding={$this->padding}:color=black";
        $index = array_search('-filter:v', $command, true);

        if ($index === false) {
            array_splice($command, -1, 0, ['-filter:v', $filter]);
        } else {
            $command[$index + 1] .= ",{$filter}";
        }

        $sheets = $this->frames === null
            ? '[sheets]'
            : (string) (intdiv($this->frames - 1, $this->columns * $this->rows) + 1);

        array_splice($command, -1, 0, ['-f', 'image2', '-start_number', '1', '-frames:v', $sheets]);

        return $command;
    }

    /**
     * Get the private path pattern that FFmpeg writes the sheets to.
     *
     * @internal
     */
    public function outputPaths(array $default, array $outputs, Workspace $workspace): array
    {
        return ["{$workspace->path}/sheet-%08d.".pathinfo($outputs[0]->destination()['path'], PATHINFO_EXTENSION)];
    }

    /**
     * Validate the sheets and write the WebVTT cues that describe them.
     * Every sheet must tile one consistent thumbnail size before the cues can describe it.
     *
     * @internal
     */
    public function artifacts(array $default, array $outputs, array $paths): array
    {
        $artifacts = ImageOutput::sequenceArtifacts(
            $outputs[0],
            $paths[0],
            intdiv($this->frames - 1, $this->columns * $this->rows) + 1,
        );
        $dimensions = null;
        $urls = [];

        foreach ($artifacts as $index => $artifact) {
            $dimensions = $this->thumbnailSize($artifact['path'], $dimensions);

            if ($this->vttPath !== null) {
                $urls[] = $this->sheetUrl($artifact['destination']['path'], $index + 1);
            }
        }

        if ($this->vttPath === null) {
            return $artifacts;
        }

        $path = dirname($paths[0]).'/thumbnails.vtt';

        $this->writeCues($path, $dimensions, $urls);

        $artifacts[] = ['path' => $path, 'output' => 0, 'role' => 'vtt', 'destination' => [
            'disk' => $outputs[0]->destination()['disk'], 'path' => $this->vttPath,
        ]];

        return $artifacts;
    }

    /**
     * Validate the given sheet and get the size of one of its thumbnails.
     *
     * @param  array{0: int, 1: int}|null  $expected
     * @return array{0: int, 1: int}
     */
    protected function thumbnailSize(string $path, ?array $expected): array
    {
        ImageOutput::validate($path, 'image');

        [$width, $height] = getimagesize($path);

        // We will remove the margins and padding to recover the size of one thumbnail,
        // which must divide the grid evenly and match the size on every other sheet.
        $width -= 2 * $this->margin + ($this->columns - 1) * $this->padding;
        $height -= 2 * $this->margin + ($this->rows - 1) * $this->padding;

        if ($width <= 0 || $height <= 0 || $width % $this->columns !== 0 || $height % $this->rows !== 0
            || ($expected !== null
                && $expected !== [intdiv($width, $this->columns), intdiv($height, $this->rows)])) {
            throw MediaException::validation(
                'The storyboard sheets have inconsistent grid dimensions.',
                'artifact',
            );
        }

        return [intdiv($width, $this->columns), intdiv($height, $this->rows)];
    }

    /**
     * Resolve the URL the cues use for the given sheet.
     */
    protected function sheetUrl(string $key, int $sheet): string
    {
        try {
            $url = $this->imageUrl instanceof Closure
                ? ($this->imageUrl)($key, $sheet)
                : $this->relativeUrl($key);
        } catch (Throwable) {
            throw MediaException::validation('Cannot resolve storyboard image URLs.', 'artifact');
        }

        // Each URL is written into the cue text ahead of an #xywh fragment, so it must
        // not contain whitespace or a fragment of its own.
        if (! is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7f#]/', $url)) {
            throw MediaException::validation(
                'Storyboard image URLs require a nonempty URI without whitespace or a fragment.',
                'artifact',
            );
        }

        return $url;
    }

    /**
     * Write a WebVTT cue for every thumbnail to the given private path.
     *
     * @param  array{0: int, 1: int}  $dimensions
     */
    protected function writeCues(string $path, array $dimensions, array $urls): void
    {
        $file = @fopen($path, 'xb');

        if ($file === false) {
            throw MediaException::validation('Cannot create storyboard cues.', 'filesystem');
        }

        try {
            if (! chmod($path, 0600) || fwrite($file, "WEBVTT\n\n") !== 8) {
                throw MediaException::validation('Cannot write storyboard cues.', 'filesystem');
            }

            for ($index = 0; $index < $this->frames; $index++) {
                $cue = $this->cue($index, $dimensions, $urls);

                if (fwrite($file, $cue) !== strlen($cue)) {
                    throw MediaException::validation('Cannot write storyboard cues.', 'filesystem');
                }
            }

            if (! fflush($file)) {
                throw MediaException::validation('Cannot flush storyboard cues.', 'filesystem');
            }
        } finally {
            fclose($file);
        }
    }

    /**
     * Get the WebVTT cue that frames the given thumbnail within its sheet.
     *
     * @param  array{0: int, 1: int}  $dimensions
     */
    protected function cue(int $index, array $dimensions, array $urls): string
    {
        [$width, $height] = $dimensions;
        $end = intdiv($this->duration - 1, 1000) + 1;
        $cells = $this->columns * $this->rows;
        $cell = $index % $cells;
        $x = $this->margin + ($cell % $this->columns) * ($width + $this->padding);
        $y = $this->margin + intdiv($cell, $this->columns) * ($height + $this->padding);
        $start = $index * $this->interval;

        return $this->timestamp($start).' --> '.$this->timestamp(min($start + $this->interval, $end))."\n"
            .$urls[intdiv($index, $cells)]."#xywh={$x},{$y},{$width},{$height}\n\n";
    }

    /**
     * Ensure the encoded file is a valid image.
     *
     * @internal
     */
    public function artifact(string $path, string $role, int $size): void
    {
        ImageOutput::validate($path, $role);
    }

    /**
     * Get the URL of the given sheet relative to the cue file.
     */
    protected function relativeUrl(string $key): string
    {
        $from = dirname($this->vttPath) === '.' ? [] : explode('/', dirname($this->vttPath));
        $to = explode('/', $key);

        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return implode('/', [...array_fill(0, count($from), '..'), ...array_map(rawurlencode(...), $to)]);
    }

    /**
     * Format the given milliseconds as a WebVTT timestamp.
     */
    protected function timestamp(int $milliseconds): string
    {
        return sprintf('%02d:%02d:%02d.%03d', intdiv($milliseconds, 3600000), intdiv($milliseconds, 60000) % 60,
            intdiv($milliseconds, 1000) % 60, $milliseconds % 1000);
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\OwnsGraph;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesCommand;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesOutputs;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ValidatesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Support\ImageOutput;

/**
 * A JPEG or PNG picture of the first audio track's waveform.
 */
class PendingWaveform implements Exportable, OwnsGraph, ShapesCommand, ShapesOutputs, ValidatesArtifacts
{
    use Concerns\AudioFilters;
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
     * The graph that draws the waveform.
     */
    protected readonly string $graph;

    /**
     * Create a new pending waveform instance.
     */
    public function __construct(Media $media, string|Filesystem $disk, int $width, int $height, string|array $colors)
    {
        $colors = is_string($colors) ? [$colors] : $colors;

        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException("The waveform dimensions [{$width}x{$height}] must be positive.");
        }

        if ($colors === []
            || ! array_is_list($colors)
            || array_any($colors, fn ($color): bool => ! is_string($color))) {
            throw new InvalidArgumentException('A waveform requires a color or a list of colors.');
        }

        // We will validate every color now, so a bad color fails before the export runs.
        array_map(CommandCompiler::color(...), $colors);

        $this->graph = "[0:a:0]showwavespic=s={$width}x{$height}:colors=".implode('|', $colors).'[waveform]';
        $this->export = new PendingExport($media, $disk, $this, 'image');
        $this->export->map('[waveform]')->withoutMetadata(chapters: true)->video('mjpeg');
    }

    /**
     * Save the picture to the given path, as a JPEG or PNG image.
     */
    public function save(?string $path = null): ExportResult
    {
        $this->export->video(ImageOutput::codec($path));

        return $this->export->save($path);
    }

    /**
     * Get the graph that draws the waveform.
     *
     * @internal
     */
    public function graph(): string
    {
        return $this->graph;
    }

    /**
     * Get the outputs without an output-level clip.
     * The clip trims the audio samples before the picture is drawn.
     *
     * @internal
     */
    public function outputs(array $default, ?string $path): array
    {
        return ImageOutput::unclipped($this->export->outputConfiguration(), $path);
    }

    /**
     * Move the caller's filters and the clip into the waveform graph.
     *
     * @internal
     */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        ImageOutput::assertNoTrackVideoFilters($outputs[0]);
        if ($this->export->inputSeekOptions() !== []) {
            throw new InvalidArgumentException('Waveform clips require accurate audio trimming.');
        }

        $command = $default;
        $filters = [];

        // Audio filters run before the picture is drawn, and geometry and other video filters
        // run after it. We will move both into the graph this operation owns.
        foreach (['-filter:a', '-filter:v'] as $flag) {
            $index = array_search($flag, $command, true);

            if ($index !== false) {
                $filters[$flag] = $command[$index + 1];
                array_splice($command, $index, 2);
            }
        }

        $audio = $filters['-filter:a'] ?? '';
        $clip = $this->export->clipSettings();

        if ($clip !== null) {
            $audio .= ($audio === '' ? '' : ',').'atrim=start='.CommandCompiler::timestamp($clip['start'])
                .':duration='.CommandCompiler::timestamp($clip['duration']).',asetpts=PTS-STARTPTS';
        }

        $index = array_search('-filter_complex', $command, true) + 1;
        $command[$index] = str_replace('[0:a:0]', '[0:a:0]'.($audio === '' ? '' : "{$audio},"), $command[$index]);

        if (isset($filters['-filter:v'])) {
            $command[$index] = Str::beforeLast($command[$index], '[waveform]')
                .",{$filters['-filter:v']}[waveform]";
        }

        array_splice($command, -1, 0, ['-frames:v', '1', '-update', '1', '-f', 'image2']);

        return $command;
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
}

<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\OwnsGraph;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesCommand;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesOutputs;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ValidatesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Support\ImageOutput;

/** A JPEG or PNG picture of the first audio track's waveform. */
final readonly class PendingWaveform implements Exportable, OwnsGraph, ShapesCommand, ShapesOutputs, ValidatesArtifacts
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
    use Conditionable;

    private PendingExport $export;

    private string $graph;

    public function __construct(Media $media, string|Filesystem $disk, int $width, int $height, string|array $colors)
    {
        $colors = is_string($colors) ? [$colors] : $colors;

        if ($width < 1 || $height < 1 || $colors === [] || ! array_is_list($colors)
            || array_any($colors, fn ($color): bool => ! is_string($color)
                || ! preg_match('/\A(?:[a-zA-Z]+|(?:#|0x)[a-fA-F0-9]{6}(?:[a-fA-F0-9]{2})?)\z/', $color))) {
            throw new InvalidArgumentException('A waveform requires positive dimensions and a list of color names or RGB/RGBA hexadecimal colors.');
        }

        $this->graph = '[0:a:0]showwavespic=s='.$width.'x'.$height.':colors='.implode('|', $colors).'[waveform]';
        $this->export = new PendingExport($media, $disk, $this, 'image');
        $this->export->map('[waveform]')->withoutMetadata(chapters: true)->video('mjpeg');
    }

    public function save(?string $path = null): ExportResult
    {
        $this->export->video(ImageOutput::codec($path));

        return $this->export->save($path);
    }

    /** @internal */
    public function graph(): string
    {
        return $this->graph;
    }

    /** @internal The clip trims audio samples before the picture is drawn. */
    public function outputs(array $default, ?string $path): array
    {
        return ImageOutput::unclipped($this->export->outputConfiguration(), $path);
    }

    /** @internal */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        ImageOutput::assertNoTrackVideoFilters($outputs[0]);
        if ($this->export->inputSeekOptions() !== []) {
            throw new InvalidArgumentException('Waveform clips require accurate audio trimming.');
        }

        $command = $default;
        $filters = [];

        // Audio filters run before the picture; geometry and other video filters
        // run after it. Both belong in the operation-owned conversion graph.
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
        $command[$index] = str_replace('[0:a:0]', '[0:a:0]'.($audio === '' ? '' : $audio.','), $command[$index]);

        if (isset($filters['-filter:v'])) {
            $command[$index] = substr($command[$index], 0, -strlen('[waveform]')).','.$filters['-filter:v'].'[waveform]';
        }

        array_splice($command, -1, 0, ['-frames:v', '1', '-update', '1', '-f', 'image2']);

        return $command;
    }

    /** @internal */
    public function artifact(string $path, string $role, int $size): void
    {
        ImageOutput::validate($path, $role);
    }
}

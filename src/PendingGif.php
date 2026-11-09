<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\OwnsGraph;
use ProtoneMedia\LaravelFFMpeg\Pipeline\RunsAnalysis;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesOutputs;
use ProtoneMedia\LaravelFFMpeg\Support\ImageOutput;

/** An animated GIF encoded with a palette generated from the clip it shows. */
final readonly class PendingGif implements Exportable, OwnsGraph, RunsAnalysis, ShapesOutputs
{
    use Concerns\Clips;
    use Concerns\GlobalOptions;
    use Concerns\Metadata;
    use Concerns\Resources;
    use Concerns\RunsExport;
    use Concerns\VideoFilters;
    use Conditionable;

    private PendingExport $export;

    public function __construct(Media $media, string|Filesystem $disk, int $width = 480, int|float $frameRate = 12, private int $loop = 0)
    {
        if ($width <= 0 || $width > 65535 || ! is_finite((float) $frameRate) || $frameRate <= 0 || $loop < -1 || $loop > 65535) {
            throw new InvalidArgumentException('GIF requires a width between 1 and 65535, a finite positive frame rate, and a loop count between -1 and 65535.');
        }

        $this->export = new PendingExport($media, $disk, $this);
        $this->export->resize($width)->frameRate($frameRate);
    }

    public function save(?string $path = null): ExportResult
    {
        return $this->export->save($path);
    }

    /** @internal */
    public function outputs(array $default, ?string $path): array
    {
        $settings = $this->export->outputConfiguration();

        if (($settings['clip']['seek'] ?? 'accurate') !== 'accurate') {
            throw new InvalidArgumentException('GIF clips require accurate seeking.');
        }

        $settings['maps'] = ['[gif_out]'];
        $settings['video'] = ['codec' => 'gif'];
        $settings['videoFilters'] = [];
        $settings['chapterSource'] ??= $settings['clip'] === null ? 0 : -1;
        $settings['clip'] = null; // Trim before palette generation, not after it.
        $settings['outputOptions'] = ['-f', 'gif', '-loop', (string) $this->loop];

        return [new ExportOutput($path ?? 'inspection.gif', $settings['disk'])->configure($settings)];
    }

    /** @internal Inspection describes encoding; analyze() creates the palette and spool it references. */
    public function graph(): string
    {
        return '[0:V:0]'.$this->preprocessing().'[gif_frames];'
            .'movie=gif-palette.png[gif_palette];[gif_frames][gif_palette]paletteuse[gif_out]';
    }

    private function preprocessing(): string
    {
        $settings = $this->export->outputConfiguration();
        $filters = ['setpts=PTS-STARTPTS'];

        if (($clip = $settings['clip']) !== null) {
            $filters[] = 'trim=start='.CommandCompiler::timestamp($clip['start']).':duration='.CommandCompiler::timestamp($clip['duration']);
            $filters[] = 'setpts=PTS-STARTPTS';
        }

        return implode(',', [...$filters, ...$settings['videoFilters']]);
    }

    /** @internal */
    public function analyze(Workspace $workspace, array $command, array $outputPaths, Closure $analyze): array
    {
        $graphIndex = array_search('-filter_complex', $command, true);
        $inputEnd = array_search('-i', $command, true) + 2;
        $prefix = array_slice($command, 0, $inputEnd);
        $prefix[$graphIndex + 1] = substr($command[$graphIndex + 1], 0, strpos($command[$graphIndex + 1], '[gif_frames]')).',format=rgb24[gif_prepared]';
        $prepared = 'gif-prepared.nut';
        $palette = 'gif-palette.png';

        // A lossless disk spool avoids buffering every decoded frame behind
        // palettegen, and applies caller filters only once to a stable source.
        $analyze([...$prefix, '-map', '[gif_prepared]', '-c:v', 'ffv1', '-level', '3',
            '-pix_fmt', 'bgr0', '-fps_mode:v', 'passthrough', '-threads', '1', '-f', 'nut', $prepared], 'GIF palette preparation failed.');
        $analyze([$command[0], '-hide_banner', '-nostdin', '-y', '-xerror', '-progress', 'pipe:1',
            '-i', $prepared, '-vf', 'palettegen', '-frames:v', '1', '-threads', '1', '-update', '1', $palette], 'GIF palette preparation failed.');
        ImageOutput::validate($workspace->path.'/'.$palette, 'image');

        $prefix = array_slice($prefix, 0, array_search('-protocol_whitelist', $prefix, true));
        $prefix[$graphIndex + 1] = '[0:v:0][1:v:0]paletteuse[gif_out]';

        return [...$prefix, '-protocol_whitelist', 'file', '-i', $prepared, '-protocol_whitelist', 'file', '-i', $palette, ...array_slice($command, $inputEnd)];
    }
}

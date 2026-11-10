<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\OwnsGraph;
use ProtoneMedia\LaravelFFMpeg\Pipeline\RunsAnalysis;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesOutputs;
use ProtoneMedia\LaravelFFMpeg\Support\ImageOutput;

/**
 * An animated GIF encoded with a palette generated from the clip it shows.
 */
class PendingGif implements Exportable, OwnsGraph, RunsAnalysis, ShapesOutputs
{
    use Concerns\Clips;
    use Concerns\GlobalOptions;
    use Concerns\Metadata;
    use Concerns\Resources;
    use Concerns\RunsExport;
    use Concerns\VideoFilters;
    use Conditionable, Macroable;

    /**
     * The export pipeline this builder configures.
     */
    protected readonly PendingExport $export;

    /**
     * The loop count FFmpeg writes to the GIF.
     * A value of -1 plays once and 0 loops forever.
     */
    protected readonly int $loopCount;

    /**
     * Create a new pending GIF instance.
     */
    public function __construct(
        Media $media,
        string|Filesystem $disk,
        int $width = 480,
        int|float $frameRate = 12,
        bool $loop = true,
        ?int $loops = null,
    ) {
        if ($width <= 0 || $width > 65535) {
            throw new InvalidArgumentException("The GIF width [{$width}] must be between 1 and 65535.");
        }

        if (! is_finite((float) $frameRate) || $frameRate <= 0) {
            throw new InvalidArgumentException('The GIF frame rate must be finite and positive.');
        }

        if ($loops !== null && ! $loop) {
            throw new InvalidArgumentException('A GIF loop count requires looping.');
        }

        if ($loops !== null && ($loops < 1 || $loops > 65535)) {
            throw new InvalidArgumentException("The GIF loop count [{$loops}] must be between 1 and 65535.");
        }

        $this->loopCount = $loop ? $loops ?? 0 : -1;

        $this->export = new PendingExport($media, $disk, $this);

        $this->export->resize($width)->frameRate($frameRate);
    }

    /**
     * Save the GIF to the given path.
     */
    public function save(?string $path = null): ExportResult
    {
        return $this->export->save($path);
    }

    /**
     * Encode the palette graph's output as the only GIF stream.
     *
     * @internal
     */
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

        // The graph trims the clip before palette generation, so the palette only sees the
        // frames the GIF shows. The output itself must not trim again afterwards.
        $settings['clip'] = null;
        $settings['outputOptions'] = ['-f', 'gif', '-loop', (string) $this->loopCount];

        return [new ExportOutput($path ?? 'inspection.gif', $settings['disk'])->configure($settings)];
    }

    /**
     * Get the graph of the encoding pass, as inspection reports it.
     * The analyze() method creates the palette and spool this graph references.
     *
     * @internal
     */
    public function graph(): string
    {
        return "[0:V:0]{$this->preprocessing()}[gif_frames];"
            .'movie=gif-palette.png[gif_palette];[gif_frames][gif_palette]paletteuse[gif_out]';
    }

    /**
     * Get the filters that prepare the frames before palette generation.
     */
    protected function preprocessing(): string
    {
        $settings = $this->export->outputConfiguration();
        $filters = ['setpts=PTS-STARTPTS'];

        if (($clip = $settings['clip']) !== null) {
            $filters[] = 'trim=start='.CommandCompiler::timestamp($clip['start'])
                .':duration='.CommandCompiler::timestamp($clip['duration']);
            $filters[] = 'setpts=PTS-STARTPTS';
        }

        return implode(',', [...$filters, ...$settings['videoFilters']]);
    }

    /**
     * Generate the palette from a lossless spool and encode the GIF from both files.
     *
     * @internal
     */
    public function analyze(Workspace $workspace, array $command, array $outputPaths, Closure $analyze): array
    {
        $graphIndex = array_search('-filter_complex', $command, true);
        $inputEnd = array_search('-i', $command, true) + 2;
        $prefix = array_slice($command, 0, $inputEnd);
        $prefix[$graphIndex + 1] = Str::before($command[$graphIndex + 1], '[gif_frames]').',format=rgb24[gif_prepared]';
        $prepared = 'gif-prepared.nut';
        $palette = 'gif-palette.png';

        // We will spool the prepared frames to a lossless file on disk first. That avoids
        // buffering every decoded frame behind palettegen, and it applies the caller's
        // filters only once, producing a stable source for both remaining passes.
        $analyze([
            ...$prefix, '-map', '[gif_prepared]', '-c:v', 'ffv1', '-level', '3',
            '-pix_fmt', 'bgr0', '-fps_mode:v', 'passthrough', '-threads', '1', '-f', 'nut', $prepared,
        ], 'GIF palette preparation failed.');

        $analyze([
            $command[0], '-hide_banner', '-nostdin', '-y', '-xerror', '-progress', 'pipe:1',
            '-i', $prepared, '-vf', 'palettegen', '-frames:v', '1', '-threads', '1', '-update', '1', $palette,
        ], 'GIF palette preparation failed.');
        ImageOutput::validate("{$workspace->path}/{$palette}", 'image');

        $prefix = array_slice($prefix, 0, array_search('-protocol_whitelist', $prefix, true));
        $prefix[$graphIndex + 1] = '[0:v:0][1:v:0]paletteuse[gif_out]';

        return [
            ...$prefix,
            '-protocol_whitelist', 'file', '-i', $prepared,
            '-protocol_whitelist', 'file', '-i', $palette,
            ...array_slice($command, $inputEnd),
        ];
    }
}

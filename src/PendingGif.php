<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

class PendingGif extends PendingExport
{
    public function __construct(Media $media, string|Filesystem $disk, int $width = 480, int|float $frameRate = 12, private readonly int $loop = 0)
    {
        if ($width <= 0 || $width > 65535 || ! is_finite((float) $frameRate) || $frameRate <= 0 || $loop < -1 || $loop > 65535) {
            throw new InvalidArgumentException('GIF requires a width between 1 and 65535, a finite positive frame rate, and a loop count between -1 and 65535.');
        }

        parent::__construct($media, $disk);
        $this->resize($width)->frameRate($frameRate);
    }

    public function addInput(Media $media): int
    {
        throw new InvalidArgumentException('GIF supports one video input.');
    }

    public function output(string $path, callable $configure): self
    {
        throw new InvalidArgumentException('GIF supports one root-configured output.');
    }

    public function filterComplex(string $expression): self
    {
        throw new InvalidArgumentException('GIF owns its palette graph; use export()->filterComplex() for custom graphs.');
    }

    public function watermark(Media $image, string $position = 'bottom-right', int $x = 0, int $y = 0, ?int $width = null, ?int $height = null, float $opacity = 1): self
    {
        throw new InvalidArgumentException('GIF owns its palette graph and does not support watermark inputs.');
    }

    public function twoPass(): self
    {
        throw new InvalidArgumentException('GIF owns its palette analysis, not two-pass video encoding.');
    }

    protected function configuredOutputs(?string $path = null): array
    {
        $settings = $this->outputConfiguration();

        if ($settings['omitVideo'] || $settings['maps'] !== [] || $settings['audio'] !== [] || $settings['audioFilters'] !== []
            || $settings['audioStreams'] !== [] || $settings['videoStreams'] !== []
            || ($settings['video'] !== [] && $settings['video'] !== ['codec' => 'gif'])
            || $settings['outputOptions'] !== [] || ($settings['clip']['seek'] ?? 'accurate') !== 'accurate'
            || array_any($settings['videoFilters'], fn ($filter) => ! is_string($filter))) {
            throw new InvalidArgumentException('GIF requires video, automatic maps, accurate clipping, string video filters, and no audio, encoder overrides, or raw output options.');
        }

        $settings['maps'] = ['[gif_out]'];
        $settings['video'] = ['codec' => 'gif'];
        $settings['omitAudio'] = $settings['omitVideo'] = false;
        $settings['videoFilters'] = [];
        $settings['chapterSource'] ??= $settings['clip'] === null ? 0 : -1;
        $settings['clip'] = null; // Trim before palette generation, not after it.
        $settings['outputOptions'] = ['-f', 'gif', '-loop', (string) $this->loop];

        return [(new ExportOutput($path ?? 'inspection.gif', $settings['disk']))->configure($settings)];
    }

    protected function filterGraph(): ?string
    {
        // Inspection describes encoding; prepareCommand creates these private files.
        return '[0:V:0]'.$this->preprocessing().'[gif_frames];'
            .'movie=gif-palette.png[gif_palette];[gif_frames][gif_palette]paletteuse[gif_out]';
    }

    private function preprocessing(): string
    {
        $settings = $this->outputConfiguration();
        $filters = ['setpts=PTS-STARTPTS'];

        if (($clip = $settings['clip']) !== null) {
            $filters[] = 'trim=start='.CommandCompiler::timestamp($clip['start']).':duration='.CommandCompiler::timestamp($clip['duration']);
            $filters[] = 'setpts=PTS-STARTPTS';
        }

        return implode(',', [...$filters, ...$settings['videoFilters']]);
    }

    protected function prepareCommand(Workspace $workspace, array $command, array $outputPaths, Closure $analyze): array
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
        $this->validateArtifact($workspace->path.'/'.$palette, 'image');

        $prefix = array_slice($prefix, 0, array_search('-protocol_whitelist', $prefix, true));
        $prefix[$graphIndex + 1] = '[0:v:0][1:v:0]paletteuse[gif_out]';

        return [...$prefix, '-protocol_whitelist', 'file', '-i', $prepared, '-protocol_whitelist', 'file', '-i', $palette, ...array_slice($command, $inputEnd)];
    }
}

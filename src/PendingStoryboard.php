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

/** Tiled thumbnail sheets sampled at a fixed interval, with optional WebVTT cues. */
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

    protected readonly PendingExport $export;

    protected readonly int $interval;

    protected ?int $duration = null;

    protected ?int $frames = null;

    protected ?string $vttPath = null;

    protected ?Closure $imageUrl = null;

    public function __construct(Media $media, string|Filesystem $disk, float $every, protected readonly int $columns, protected readonly int $rows, protected readonly int $margin, protected readonly int $padding)
    {
        if (! is_finite($every) || $every < 0.001 || $every > intdiv(PHP_INT_MAX, 1000000)
            || abs($every * 1000 - round($every * 1000)) > 0.000001) {
            throw new InvalidArgumentException("The storyboard interval [{$every}] must be a positive whole number of milliseconds.");
        }

        if ($columns < 1 || $rows < 1 || $columns > intdiv(PHP_INT_MAX, $rows)) {
            throw new InvalidArgumentException("The storyboard grid [{$columns}x{$rows}] must have a positive, representable number of cells.");
        }

        if ($margin < 0) {
            throw new InvalidArgumentException("The storyboard margin [{$margin}] must not be negative.");
        }

        if ($padding < 0) {
            throw new InvalidArgumentException("The storyboard padding [{$padding}] must not be negative.");
        }

        $this->interval = (int) round($every * 1000);
        $this->export = new PendingExport($media, $disk, $this, 'image');
        $this->export->map('0:V:0')->withoutMetadata(chapters: true)->video('mjpeg');
    }

    public function vtt(string $path, ?callable $imageUrl = null): self
    {
        $this->export->assertOutputMutable();
        $path = new ExportOutput($path, 'local')->destination()['path'];
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'vtt') {
            throw new InvalidArgumentException('The cue destination must have a vtt extension.');
        }

        $this->vttPath = $path;
        $this->imageUrl = $imageUrl === null ? null : Closure::fromCallable($imageUrl);

        return $this;
    }

    public function save(?string $path = null): ExportResult
    {
        if ($path === null) {
            throw new InvalidArgumentException('A storyboard requires an image destination pattern.');
        }

        $path = ImageOutput::sequenceDestination($path);
        $this->export->video(ImageOutput::codec($path));

        return $this->export->save($path);
    }

    /** @internal */
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

    /** @internal Bound individual thumbnails before tiling. Output-level -t would bound sheet timestamps, allowing thumbnails beyond a clip's end. */
    public function outputs(array $default, ?string $path): array
    {
        return ImageOutput::unclipped($this->export->outputConfiguration(), $path);
    }

    /** @internal */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        $command = $default;
        if ($this->export->inputSeekOptions() !== []) {
            throw new InvalidArgumentException('Storyboards require accurate clipping, not keyframe seek.');
        }

        ImageOutput::assertNoTrackVideoFilters($outputs[0]);
        $start = $this->export->clipSettings()['start'] ?? 0;
        $end = $this->duration === null ? '[end]' : CommandCompiler::timestamp($start + $this->duration / 1000000);
        $filter = 'trim=start='.CommandCompiler::timestamp($start).':end='.$end.',setpts=PTS-STARTPTS,'
            .'fps=fps=1/'.CommandCompiler::timestamp($this->interval / 1000).':start_time=0:round=near:eof_action=pass,'
            .'tile=layout='.$this->columns.'x'.$this->rows.':nb_frames='.($this->columns * $this->rows)
            .':margin='.$this->margin.':padding='.$this->padding.':color=black';
        $index = array_search('-filter:v', $command, true);
        if ($index === false) {
            array_splice($command, -1, 0, ['-filter:v', $filter]);
        } else {
            $command[$index + 1] .= ','.$filter;
        }

        $sheets = $this->frames === null ? '[sheets]' : (string) (intdiv($this->frames - 1, $this->columns * $this->rows) + 1);
        array_splice($command, -1, 0, ['-f', 'image2', '-start_number', '1', '-frames:v', $sheets]);

        return $command;
    }

    /** @internal */
    public function outputPaths(array $default, array $outputs, Workspace $workspace): array
    {
        return [$workspace->path.'/sheet-%08d.'.pathinfo($outputs[0]->destination()['path'], PATHINFO_EXTENSION)];
    }

    /** @internal Sheets must tile one consistent thumbnail size before the cues describe them. */
    public function artifacts(array $default, array $outputs, array $paths): array
    {
        $artifacts = ImageOutput::sequenceArtifacts($outputs[0], $paths[0], intdiv($this->frames - 1, $this->columns * $this->rows) + 1);
        $dimensions = null;
        $urls = [];
        foreach ($artifacts as $index => $artifact) {
            ImageOutput::validate($artifact['path'], 'image');
            [$width, $height] = getimagesize($artifact['path']);
            $width -= 2 * $this->margin + ($this->columns - 1) * $this->padding;
            $height -= 2 * $this->margin + ($this->rows - 1) * $this->padding;
            if ($width <= 0 || $height <= 0 || $width % $this->columns !== 0 || $height % $this->rows !== 0
                || ($dimensions !== null && $dimensions !== [intdiv($width, $this->columns), intdiv($height, $this->rows)])) {
                throw MediaException::validation('The storyboard sheets have inconsistent grid dimensions.', 'artifact');
            }

            $dimensions = [intdiv($width, $this->columns), intdiv($height, $this->rows)];
            if ($this->vttPath !== null) {
                $key = $artifact['destination']['path'];
                try {
                    $url = $this->imageUrl instanceof Closure ? ($this->imageUrl)($key, $index + 1) : $this->relativeUrl($key);
                } catch (Throwable) {
                    throw MediaException::validation('Cannot resolve storyboard image URLs.', 'artifact');
                }

                if (! is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7f#]/', $url)) {
                    throw MediaException::validation('Storyboard image URLs require a nonempty URI without whitespace or a fragment.', 'artifact');
                }

                $urls[] = $url;
            }
        }

        if ($this->vttPath === null) {
            return $artifacts;
        }

        $path = dirname($paths[0]).'/thumbnails.vtt';
        $file = @fopen($path, 'xb');
        if ($file === false) {
            throw MediaException::validation('Cannot create storyboard cues.', 'filesystem');
        }

        try {
            if (! chmod($path, 0600) || fwrite($file, "WEBVTT\n\n") !== 8) {
                throw MediaException::validation('Cannot write storyboard cues.', 'filesystem');
            }

            [$width, $height] = $dimensions;
            $end = intdiv($this->duration - 1, 1000) + 1;
            $cells = $this->columns * $this->rows;
            for ($index = 0; $index < $this->frames; $index++) {
                $cell = $index % $cells;
                $x = $this->margin + ($cell % $this->columns) * ($width + $this->padding);
                $y = $this->margin + intdiv($cell, $this->columns) * ($height + $this->padding);
                $start = $index * $this->interval;
                $cue = $this->timestamp($start).' --> '.$this->timestamp(min($start + $this->interval, $end))."\n"
                    .$urls[intdiv($index, $cells)]."#xywh=$x,$y,$width,$height\n\n";
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

        $artifacts[] = ['path' => $path, 'output' => 0, 'role' => 'vtt', 'destination' => [
            'disk' => $outputs[0]->destination()['disk'], 'path' => $this->vttPath,
        ]];

        return $artifacts;
    }

    /** @internal */
    public function artifact(string $path, string $role, int $size): void
    {
        ImageOutput::validate($path, $role);
    }

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

    protected function timestamp(int $milliseconds): string
    {
        return sprintf('%02d:%02d:%02d.%03d', intdiv($milliseconds, 3600000), intdiv($milliseconds, 60000) % 60,
            intdiv($milliseconds, 1000) % 60, $milliseconds % 1000);
    }
}

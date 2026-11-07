<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use Throwable;

class PendingStoryboard extends PendingImages
{
    private readonly int $interval;

    private ?int $duration = null;

    private ?int $frames = null;

    private ?string $vttPath = null;

    private ?Closure $imageUrl = null;

    public function __construct(Media $media, string|Filesystem $disk, float $every, private readonly int $columns, private readonly int $rows, private readonly int $margin, private readonly int $padding)
    {
        parent::__construct($media, $disk);

        if (! is_finite($every) || $every < 0.001 || $every > intdiv(PHP_INT_MAX, 1000000)
            || abs($every * 1000 - round($every * 1000)) > 0.000001
            || $columns < 1 || $rows < 1 || $columns > intdiv(PHP_INT_MAX, $rows) || $margin < 0 || $padding < 0) {
            throw new InvalidArgumentException('Storyboards require a positive whole-millisecond interval, a positive integer grid, and nonnegative spacing.');
        }

        $this->interval = (int) round($every * 1000);
    }

    public function vtt(string $path, ?callable $imageUrl = null): self
    {
        $this->assertOutputMutable();
        $path = $this->configuredOutputs($path)[0]->destination()['path'];
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

        return parent::save($this->sequenceDestination($path));
    }

    protected function prepareEncoding(array $metadata): ?float
    {
        $duration = $metadata[0]->duration();
        $clip = $this->clipSettings();
        if ($duration !== null && $clip !== null) {
            $duration = max(0, min($clip['duration'], $duration - $clip['start']));
        }
        if ($duration === null || $duration <= 0 || $duration > intdiv(PHP_INT_MAX, 1000000)
            || ($this->duration = (int) round($duration * 1000000)) < 1) {
            throw new MediaException('Storyboards require a known, positive media duration.', Progress::PROBING, 'metadata');
        }
        $this->frames = intdiv($this->duration - 1, $this->interval * 1000) + 1;

        return $metadata[0]->duration();
    }

    protected function command(array $outputs, array $paths, ?array $outputPaths = null): array
    {
        $command = parent::command($outputs, $paths, $outputPaths);
        if (count($paths) !== 1 || in_array('-filter_complex', $command, true) || $this->inputSeekOptions() !== []) {
            throw new InvalidArgumentException('Storyboards require one input without a complex graph or keyframe seek.');
        }

        // Bound individual thumbnails before tiling. Output-level -t would
        // bound sheet timestamps, allowing thumbnails beyond a clip's end.
        foreach (['-ss', '-t'] as $flag) {
            if (($index = array_search($flag, $command, true)) !== false) {
                array_splice($command, $index, 2);
            }
        }
        $start = $this->clipSettings()['start'] ?? 0;
        $end = $this->duration === null ? '[end]' : $this->clipTimestamp($start + $this->duration / 1000000);
        $filter = 'trim=start='.$this->clipTimestamp($start).':end='.$end.',setpts=PTS-STARTPTS,'
            .'fps=fps=1/'.$this->clipTimestamp($this->interval / 1000).':start_time=0:round=near:eof_action=pass,'
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

    protected function outputPaths(array $outputs, Workspace $workspace): array
    {
        return [$workspace->path.'/sheet-%08d.'.pathinfo($outputs[0]->destination()['path'], PATHINFO_EXTENSION)];
    }

    protected function artifacts(array $outputs, array $paths): array
    {
        $artifacts = $this->sequenceArtifacts($outputs[0], $paths[0], intdiv($this->frames - 1, $this->columns * $this->rows) + 1);
        $dimensions = null;
        $urls = [];
        foreach ($artifacts as $index => $artifact) {
            $this->validateArtifact($artifact['path']);
            [$width, $height] = getimagesize($artifact['path']);
            $width -= 2 * $this->margin + ($this->columns - 1) * $this->padding;
            $height -= 2 * $this->margin + ($this->rows - 1) * $this->padding;
            if ($width <= 0 || $height <= 0 || $width % $this->columns !== 0 || $height % $this->rows !== 0
                || ($dimensions !== null && $dimensions !== [intdiv($width, $this->columns), intdiv($height, $this->rows)])) {
                throw new MediaException('The storyboard sheets have inconsistent grid dimensions.', Progress::VALIDATION, 'artifact');
            }
            $dimensions = [intdiv($width, $this->columns), intdiv($height, $this->rows)];
            if ($this->vttPath !== null) {
                $key = $artifact['destination']['path'];
                try {
                    $url = $this->imageUrl === null ? $this->relativeUrl($key) : ($this->imageUrl)($key, $index + 1);
                } catch (Throwable) {
                    throw new MediaException('Cannot resolve storyboard image URLs.', Progress::VALIDATION, 'artifact');
                }
                if (! is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7f#]/', $url)) {
                    throw new MediaException('Storyboard image URLs require a nonempty URI without whitespace or a fragment.', Progress::VALIDATION, 'artifact');
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
            throw new MediaException('Cannot create storyboard cues.', Progress::VALIDATION, 'filesystem');
        }
        try {
            if (! chmod($path, 0600) || fwrite($file, "WEBVTT\n\n") !== 8) {
                throw new MediaException('Cannot write storyboard cues.', Progress::VALIDATION, 'filesystem');
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
                    throw new MediaException('Cannot write storyboard cues.', Progress::VALIDATION, 'filesystem');
                }
            }
            if (! fflush($file)) {
                throw new MediaException('Cannot flush storyboard cues.', Progress::VALIDATION, 'filesystem');
            }
        } finally {
            fclose($file);
        }
        $artifacts[] = ['path' => $path, 'output' => 0, 'role' => 'vtt', 'destination' => [
            'disk' => $outputs[0]->destination()['disk'], 'path' => $this->vttPath,
        ]];

        return $artifacts;
    }

    private function relativeUrl(string $key): string
    {
        $from = dirname($this->vttPath) === '.' ? [] : explode('/', dirname($this->vttPath));
        $to = explode('/', $key);
        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return implode('/', [...array_fill(0, count($from), '..'), ...array_map('rawurlencode', $to)]);
    }

    private function timestamp(int $milliseconds): string
    {
        return sprintf('%02d:%02d:%02d.%03d', intdiv($milliseconds, 3600000), intdiv($milliseconds, 60000) % 60,
            intdiv($milliseconds, 1000) % 60, $milliseconds % 1000);
    }
}

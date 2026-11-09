<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

class PendingFrames extends PendingImages
{
    private ?float $duration = null;

    public function __construct(Media $media, string|Filesystem $disk, private readonly ?float $every, private readonly ?int $count)
    {
        parent::__construct($media, $disk);

        if (($every === null) === ($count === null)
            || ($every !== null && (! is_finite($every) || $every < 0.000001))
            || ($count !== null && $count < 1)) {
            throw new InvalidArgumentException('Specify exactly one positive frame count or a finite interval of at least one microsecond.');
        }
    }

    public function save(?string $path = null): ExportResult
    {
        if ($path === null) {
            throw new InvalidArgumentException('An image sequence requires a destination pattern.');
        }

        return parent::save($this->sequenceDestination($path));
    }

    protected function prepareEncoding(array $metadata): ?float
    {
        $this->duration = $metadata[0]->duration();
        $clip = $this->clipSettings();

        if ($this->duration !== null && $clip !== null) {
            $this->duration = max(0, min($clip['duration'], $this->duration - $clip['start']));
        }

        if ($this->count !== null && ($this->duration === null || $this->clipTimestamp($this->duration) === '0')) {
            throw new MediaException('Count-based frame extraction requires a known, positive media duration.', Progress::PROBING, 'metadata');
        }

        return $metadata[0]->duration();
    }

    protected function command(array $outputs, array $paths, ?array $outputPaths = null): array
    {
        $this->assertNoTrackVideoFilters($outputs[0]);
        $command = parent::command($outputs, $paths, $outputPaths);
        $rate = $this->every !== null ? '1/'.$this->clipTimestamp($this->every)
            : $this->count.'/'.($this->duration === null ? '[duration]' : $this->clipTimestamp($this->duration));
        $start = $this->inputSeekOptions() === [] ? ($this->clipSettings()['start'] ?? 0) : 0;
        $filter = 'fps=fps='.$rate.':start_time='.$this->clipTimestamp($start).':round=near:eof_action=pass';

        if ($this->count !== null) {
            $filter .= ',tpad=stop_mode=clone:stop='.$this->count;
        }

        $index = array_search('-filter:v', $command, true);

        if ($index === false) {
            array_splice($command, -1, 0, ['-filter:v', $filter]);
        } else {
            $command[$index + 1] .= ','.$filter;
        }

        $options = ['-f', 'image2', '-start_number', '1'];

        if ($this->count !== null) {
            array_push($options, '-frames:v', (string) $this->count);
        }

        array_splice($command, -1, 0, $options);

        return $command;
    }

    protected function outputPaths(array $outputs, Workspace $workspace): array
    {
        $extension = pathinfo($outputs[0]->destination()['path'], PATHINFO_EXTENSION);

        return [$workspace->path.'/frame-%08d.'.$extension];
    }

    protected function artifacts(array $outputs, array $paths): array
    {
        return $this->sequenceArtifacts($outputs[0], $paths[0], $this->count);
    }
}

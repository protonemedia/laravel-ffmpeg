<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
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

/** A numbered JPEG or PNG sequence sampled at an interval or as a fixed count. */
final class PendingFrames implements Exportable, MeasuresDuration, PackagesArtifacts, ShapesCommand, ValidatesArtifacts
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
    use Conditionable;

    private readonly PendingExport $export;

    private ?float $duration = null;

    public function __construct(Media $media, string|Filesystem $disk, private readonly ?float $every, private readonly ?int $count)
    {
        if (($every === null) === ($count === null)
            || ($every !== null && (! is_finite($every) || $every < 0.000001))
            || ($count !== null && $count < 1)) {
            throw new InvalidArgumentException('Specify exactly one positive frame count or a finite interval of at least one microsecond.');
        }

        $this->export = new PendingExport($media, $disk, $this, 'image');
        $this->export->map('0:V:0')->withoutMetadata(chapters: true)->video('mjpeg');
    }

    public function save(?string $path = null): ExportResult
    {
        if ($path === null) {
            throw new InvalidArgumentException('An image sequence requires a destination pattern.');
        }

        $path = ImageOutput::sequenceDestination($path);
        $this->export->video(ImageOutput::codec($path));

        return $this->export->save($path);
    }

    /** @internal Count-based sampling spreads frames over the clipped duration; progress uses the source duration. */
    public function duration(array $metadata, ?float $default): ?float
    {
        $this->duration = $metadata[0]->duration();
        $clip = $this->export->clipSettings();

        if ($this->duration !== null && $clip !== null) {
            $this->duration = max(0, min($clip['duration'], $this->duration - $clip['start']));
        }

        if ($this->count !== null && ($this->duration === null || CommandCompiler::timestamp($this->duration) === '0')) {
            throw new MediaException('Count-based frame extraction requires a known, positive media duration.', Progress::PROBING, 'metadata');
        }

        return $metadata[0]->duration();
    }

    /** @internal */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        ImageOutput::assertNoTrackVideoFilters($outputs[0]);
        $rate = $this->every !== null ? '1/'.CommandCompiler::timestamp($this->every)
            : $this->count.'/'.($this->duration === null ? '[duration]' : CommandCompiler::timestamp($this->duration));
        $start = $this->export->inputSeekOptions() === [] ? ($this->export->clipSettings()['start'] ?? 0) : 0;
        $filter = 'fps=fps='.$rate.':start_time='.CommandCompiler::timestamp($start).':round=near:eof_action=pass';

        if ($this->count !== null) {
            $filter .= ',tpad=stop_mode=clone:stop='.$this->count;
        }

        $index = array_search('-filter:v', $default, true);

        if ($index === false) {
            array_splice($default, -1, 0, ['-filter:v', $filter]);
        } else {
            $default[$index + 1] .= ','.$filter;
        }

        $options = ['-f', 'image2', '-start_number', '1'];

        if ($this->count !== null) {
            array_push($options, '-frames:v', (string) $this->count);
        }

        array_splice($default, -1, 0, $options);

        return $default;
    }

    /** @internal */
    public function outputPaths(array $default, array $outputs, Workspace $workspace): array
    {
        $extension = pathinfo($outputs[0]->destination()['path'], PATHINFO_EXTENSION);

        return [$workspace->path.'/frame-%08d.'.$extension];
    }

    /** @internal */
    public function artifacts(array $default, array $outputs, array $paths): array
    {
        return ImageOutput::sequenceArtifacts($outputs[0], $paths[0], $this->count);
    }

    /** @internal */
    public function artifact(string $path, string $role, int $size): void
    {
        ImageOutput::validate($path, $role);
    }
}

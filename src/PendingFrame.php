<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\MeasuresDuration;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesCommand;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ValidatesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Support\ImageOutput;

/** One JPEG or PNG frame at a timestamp, returned as bytes or published. */
class PendingFrame implements Exportable, MeasuresDuration, ShapesCommand, ValidatesArtifacts
{
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

    protected readonly float $at;

    public function __construct(Media $media, string|Filesystem $disk, float|string $at)
    {
        $this->at = CommandCompiler::seconds($at);

        if (! is_finite($this->at) || $this->at < 0) {
            throw new InvalidArgumentException('A frame timestamp must be finite and nonnegative.');
        }

        $this->export = new PendingExport($media, $disk, $this, 'image');
        $this->export->map('0:V:0')->withoutMetadata(chapters: true)->video('mjpeg');
    }

    /** Return JPEG bytes without publishing a file. */
    public function get(): string
    {
        $this->export->video('mjpeg');

        return $this->export->execute('frame.jpg', contents: true);
    }

    public function save(?string $path = null): ExportResult
    {
        $this->export->video(ImageOutput::codec($path));

        return $this->export->save($path);
    }

    /** @internal */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        array_splice($default, -1, 0, ['-ss', CommandCompiler::timestamp($this->at), '-frames:v', '1', '-update', '1', '-f', 'image2']);

        return $default;
    }

    /** @internal Seeking makes encoded time unrelated to the source duration. */
    public function duration(array $metadata, ?float $default): ?float
    {
        return null;
    }

    /** @internal */
    public function artifact(string $path, string $role, int $size): void
    {
        ImageOutput::validate($path, $role);
    }
}

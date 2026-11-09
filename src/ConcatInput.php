<?php

namespace ProtoneMedia\LaravelFFMpeg;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\InputSource;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/** @internal */
readonly class ConcatInput implements InputSource
{
    public function __construct(protected array $inputs)
    {
        if (count($inputs) < 2) {
            throw new InvalidArgumentException('Concatenation requires at least two inputs.');
        }

        foreach ($inputs as $input) {
            if ($input->protocolWhitelist() !== 'file') {
                throw new InvalidArgumentException('Concat inputs must be local or downloaded files; use download: true for URLs.');
            }

            if ($input->inputOptions() !== [] || $input->transportOptions() !== []) {
                throw new InvalidArgumentException('Concat inputs must not have input options.');
            }
        }
    }

    public function identity(): string
    {
        return hash('sha256', serialize(array_map(fn ($media) => $media->identity(), $this->inputs)));
    }

    public function extension(): string
    {
        return 'ffconcat';
    }

    public function label(): string
    {
        return '[concat]';
    }

    public function protocols(): string
    {
        return 'file';
    }

    public function options(): array
    {
        return ['-f', 'concat', '-safe', '1'];
    }

    public function redactions(): array
    {
        $redactions = [];

        foreach ($this->inputs as $input) {
            $redactions += $input->redactions();
        }

        return $redactions;
    }

    public function stage(Workspace $workspace, string $name, bool $resource): string
    {
        if ($resource) {
            throw MediaException::staging('Concatenated media cannot be a filter resource.', 'resource');
        }

        $manifest = "ffconcat version 1.0\n";
        $layout = null;
        $fields = ['codec_type', 'codec_name', 'codec_tag_string', 'profile', 'level', 'time_base',
            'width', 'height', 'pix_fmt', 'sample_aspect_ratio', 'r_frame_rate',
            'sample_rate', 'channels', 'channel_layout', 'extradata_size'];

        foreach ($this->inputs as $index => $input) {
            $metadata = $workspace->probe($input);
            $duration = $metadata->duration();
            $streams = $metadata->streams()->map(fn ($stream): array => array_map(fn (string $field) => $stream->get($field), $fields))->all();

            if ($duration === null || $duration <= 0) {
                throw MediaException::probing("Concat input [{$index}] must have a finite positive duration.", 'metadata');
            }

            if ($streams === []) {
                throw MediaException::probing("Concat input [{$index}] must contain streams.", 'metadata');
            }

            if (in_array($metadata->get('format.format_name'), ['concat', 'hls', 'dash'], true)) {
                throw MediaException::probing("Concat input [{$index}] must be standalone media, not a playlist or manifest.", 'metadata');
            }

            $start = $metadata->get('format.start_time', '0');

            if (! is_numeric($start) || (float) $start !== 0.0) {
                throw MediaException::probing("Concat input [{$index}] must start at zero; use an explicit normalization graph.", 'metadata');
            }

            if ($layout !== null && $layout !== $streams) {
                throw MediaException::probing("Concat input [{$index}] stream layout or codec parameters differ from the first input; use an explicit normalization graph.", 'concat');
            }

            $layout = $streams;
            $source = $workspace->stage($input);
            $alias = $name.'-part-'.$index.($input->extension() === '' ? '' : '.'.$input->extension());
            $target = $workspace->path.'/'.$alias;

            // Safe relative manifest names avoid FFconcat's quoting rules for
            // caller filenames. Cleanup deletes links, never their source files.
            if (! @symlink($source, $target) && (! @copy($source, $target) || ! chmod($target, 0600))) {
                throw MediaException::staging('Cannot prepare concat input '.$index.'.', 'filesystem');
            }

            $manifest .= "file '$alias'\nduration ".sprintf('%.6F', $duration)."\n";
        }

        $path = $workspace->path.'/'.$name;

        if (@file_put_contents($path, $manifest) !== strlen($manifest) || ! chmod($path, 0600)) {
            throw MediaException::staging('Cannot write the concat manifest.', 'filesystem');
        }

        return $path;
    }
}

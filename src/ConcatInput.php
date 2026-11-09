<?php

namespace ProtoneMedia\LaravelFFMpeg;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\InputSource;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/** @internal */
readonly class ConcatInput implements InputSource
{
    public function __construct(private array $inputs)
    {
        if (count($inputs) < 2) {
            throw new InvalidArgumentException('Concatenation requires at least two inputs.');
        }

        foreach ($inputs as $input) {
            if ($input->protocolWhitelist() !== 'file' || $input->inputOptions() !== [] || $input->transportOptions() !== []) {
                throw new InvalidArgumentException('Concat inputs require standalone local or staged files without input options. Use download: true for URLs.');
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
            throw new MediaException('Concatenated media cannot be a filter resource.', 'staging', 'resource');
        }

        $manifest = "ffconcat version 1.0\n";
        $layout = null;
        $fields = ['codec_type', 'codec_name', 'codec_tag_string', 'profile', 'level', 'time_base',
            'width', 'height', 'pix_fmt', 'sample_aspect_ratio', 'r_frame_rate',
            'sample_rate', 'channels', 'channel_layout', 'extradata_size'];

        foreach ($this->inputs as $index => $input) {
            $metadata = $workspace->probe($input);
            $duration = $metadata->duration();
            $streams = $metadata->streams()->map(fn ($stream) => array_map(fn ($field) => $stream->get($field), $fields))->all();

            if ($duration === null || $duration <= 0 || $streams === []
                || in_array($metadata->get('format.format_name'), ['concat', 'hls', 'dash'], true)) {
                throw new MediaException('Concat input '.$index.' requires finite standalone media with streams.', 'probing', 'metadata');
            }

            $start = $metadata->get('format.start_time', '0');

            if (! is_numeric($start) || (float) $start !== 0.0) {
                throw new MediaException('Concat input '.$index.' requires a zero presentation origin. Use an explicit normalization graph.', 'probing', 'metadata');
            }

            if ($layout !== null && $layout !== $streams) {
                throw new MediaException('Concat input '.$index.' has a different stream layout or codec parameters. Use an explicit normalization graph.', 'probing', 'concat');
            }

            $layout = $streams;
            $source = $workspace->stage($input);
            $alias = $name.'-part-'.$index.($input->extension() === '' ? '' : '.'.$input->extension());
            $target = $workspace->path.'/'.$alias;

            // Safe relative manifest names avoid FFconcat's quoting rules for
            // caller filenames. Cleanup deletes links, never their source files.
            if (! @symlink($source, $target) && (! @copy($source, $target) || ! chmod($target, 0600))) {
                throw new MediaException('Cannot prepare concat input '.$index.'.', 'staging', 'filesystem');
            }

            $manifest .= "file '$alias'\nduration ".sprintf('%.6F', $duration)."\n";
        }

        $path = $workspace->path.'/'.$name;

        if (@file_put_contents($path, $manifest) !== strlen($manifest) || ! chmod($path, 0600)) {
            throw new MediaException('Cannot write the concat manifest.', 'staging', 'filesystem');
        }

        return $path;
    }
}

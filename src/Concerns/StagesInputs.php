<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\ProcessRunner;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\ExportOutput;
use ProtoneMedia\LaravelFFMpeg\Metadata;
use ProtoneMedia\LaravelFFMpeg\Stream;

/**
 * Stages, probes and validates the inputs and resources of an export.
 */
trait StagesInputs
{
    /**
     * Stage every input in the workspace and redact the staged paths.
     *
     * @return array{0: array<int, string>, 1: array<string, string>} The staged input paths and the redactions.
     */
    protected function stageInputs(Workspace $workspace): array
    {
        $paths = array_map(fn ($media): string => $workspace->stage($media), $this->inputs);
        $redactions = [];

        foreach ($paths as $index => $path) {
            $redactions[$path] ??= "[input:{$index}]";
            $redactions += $this->inputs[$index]->redactions();
        }

        return [$paths, $redactions];
    }

    /**
     * Write every filter resource to its private path in the workspace.
     */
    protected function stageResources(Workspace $workspace, array $resources): void
    {
        $staged = [];

        foreach ($resources as $resource) {
            $source = $resource['source'];
            $target = "{$workspace->path}/{$resource['path']}";

            if (isset($staged[$target])) {
                continue;
            }

            try {
                $written = $resource['type'] === 'text'
                    ? @file_put_contents($target, $source) === strlen($source)
                    : @copy($workspace->stage($source, resource: true), $target);
            } catch (MediaException $exception) {
                throw MediaException::staging("Cannot stage {$resource['context']}.", $exception->category);
            }

            if (! $written || ! chmod($target, 0600)) {
                throw MediaException::staging("Cannot stage {$resource['context']}.", 'transfer');
            }

            $staged[$target] = true;
        }
    }

    /**
     * Check the binaries, input protocols, and resources, then probe every input.
     *
     * @return array<int, Metadata>
     */
    protected function probeInputs(Workspace $workspace, array $resources): array
    {
        $runner = $workspace->runner;
        $runner->pairVersion(
            config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'),
            config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'),
            $workspace->path,
        );

        foreach ($this->inputs as $media) {
            $workspace->requireProtocols(
                config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'),
                $media->protocolWhitelist(),
            );
        }

        $this->validateResources($workspace, $runner, $resources);

        return array_map(fn ($media): Metadata => $workspace->probe($media), $this->inputs);
    }

    /**
     * Resolve the stream types each output selects and validate them before encoding.
     */
    protected function expectedStreams(Workspace $workspace, array $outputs, array $metadata): array
    {
        $expected = array_map(fn ($output): array => $this->selectedStreams($output, $workspace, $metadata), $outputs);
        $this->ensureTrackSettingsExist($outputs, $expected);
        $this->validateStreamSelections($expected);

        if ($this->twoPass && array_any(
            $this->twoPassOutputIndexes($outputs),
            fn ($index): bool => ! in_array('video', $expected[$index], true)
                && ! in_array(null, $expected[$index], true),
        )) {
            throw new MediaException('Every two-pass output must select video.', $this->phase, 'mapping');
        }

        return $expected;
    }

    /**
     * Ensure every per-track setting targets a track the output selects.
     */
    protected function ensureTrackSettingsExist(array $outputs, array $expected): void
    {
        foreach ($outputs as $index => $output) {
            foreach (['video' => 'videoStreams', 'audio' => 'audioStreams'] as $type => $key) {
                $tracks = $output->outputConfiguration()[$key];

                if ($tracks !== []
                    && ! in_array(null, $expected[$index], true)
                    && max(array_keys($tracks)) >= collect($expected[$index])
                        ->filter(fn ($selected): bool => $selected === $type)
                        ->count()) {
                    throw MediaException::probing(
                        ucfirst($type)." track settings require existing output {$type} tracks.",
                        'mapping',
                    );
                }
            }
        }
    }

    /**
     * Concatenate an initialization file and its first fragment into a private, probeable MP4.
     *
     * @internal
     */
    public static function fragmentProbe(string $init, string $segment, string $target): string
    {
        $stream = @fopen($target, 'wb');

        if ($stream === false) {
            throw MediaException::validation('Cannot prepare fragment validation.', 'filesystem');
        }

        try {
            foreach ([$init, $segment] as $source) {
                $input = @fopen($source, 'rb');

                if ($input === false) {
                    throw MediaException::validation('Cannot read fragment validation input.', 'filesystem');
                }

                try {
                    if (stream_copy_to_stream($input, $stream) !== filesize($source)) {
                        throw MediaException::validation('Cannot prepare fragment validation.', 'filesystem');
                    }
                } finally {
                    fclose($input);
                }
            }
        } finally {
            fclose($stream);
        }

        if (! chmod($target, 0600)) {
            throw MediaException::validation('Cannot secure fragment validation.', 'filesystem');
        }

        return $target;
    }

    /**
     * Ensure the container and every motion video track start at a zero origin.
     *
     * With $audioOnlyOffset, a source without motion video may start later, and its filters keep that offset.
     *
     * @internal
     */
    public static function assertZeroOrigins(Metadata $item, string $operation, bool $audioOnlyOffset = false): void
    {
        $origin = $item->get('format.start_time');

        if ((! $audioOnlyOffset || $item->hasVideo())
            && (! is_numeric($origin) || ! is_finite((float) $origin) || abs((float) $origin) > 0.000001)) {
            throw MediaException::probing("{$operation} requires a zero presentation origin.", 'metadata');
        }

        if ($item->videoStreams()->contains(function (Stream $stream): bool {
            $start = $stream->get('start_time');

            return ! is_numeric($start) || abs((float) $start) > 0.000001;
        })) {
            throw MediaException::probing("{$operation} requires zero origins on every video stream.", 'metadata');
        }
    }

    /**
     * Ensure the FFmpeg build has the filters the resources need and every subtitle resource decodes.
     */
    protected function validateResources(Workspace $workspace, ProcessRunner $runner, array $resources): void
    {
        $this->ensureResourceFiltersExist($workspace, $runner, $resources);

        foreach ($resources as $resource) {
            if ($resource['type'] === 'subtitle') {
                $this->validateSubtitleResource($workspace, $runner, $resource);
            }
        }
    }

    /**
     * Ensure the FFmpeg build has the filters that render the given text and subtitle resources.
     */
    protected function ensureResourceFiltersExist(Workspace $workspace, ProcessRunner $runner, array $resources): void
    {
        $required = collect($resources)
            ->reject(fn (array $resource): bool => $resource['type'] === 'file')
            ->map(fn (array $resource): string => $resource['type'] === 'text' ? 'drawtext' : 'subtitles')
            ->unique()
            ->all();

        if ($required !== []) {
            $filters = $runner->run(
                [config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'), '-hide_banner', '-filters'],
                $workspace->path,
            );

            foreach ($required as $filter) {
                if ($filters->exitCode !== 0
                    || ! preg_match('/^\s*\S+\s+'.preg_quote($filter, '/').'\s/m', $filters->output)) {
                    throw MediaException::probing("The FFmpeg build requires the {$filter} filter.", 'capability');
                }
            }
        }
    }

    /**
     * Ensure the given subtitle resource is a standalone UTF-8 subtitle file FFmpeg can decode.
     */
    protected function validateSubtitleResource(Workspace $workspace, ProcessRunner $runner, array $resource): void
    {
        if (! mb_check_encoding(file_get_contents("{$workspace->path}/{$resource['path']}"), 'UTF-8')) {
            throw MediaException::probing(
                "Subtitle resources require UTF-8 for {$resource['context']}.",
                'resource',
            );
        }

        // We will probe the exact filename and bytes the subtitles filter reopens, without
        // the caller's input options, so they cannot force a different demuxer or seek.
        $probe = $runner->run([
            config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'), '-v', 'error', '-of', 'json',
            '-show_entries', 'format=format_name:stream=codec_type', '-protocol_whitelist', 'file',
            '-format_whitelist', 'srt,ass,webvtt', $resource['path'],
        ], $workspace->path);
        $data = json_decode($probe->output, true);

        if ($probe->exitCode !== 0
            || ! in_array($data['format']['format_name'] ?? null, ['srt', 'ass', 'webvtt'], true)
            || ($data['streams'] ?? null) !== [['codec_type' => 'subtitle']]) {
            throw MediaException::probing(
                "A subtitle resource must be a standalone SRT, ASS, or WebVTT file for {$resource['context']}.",
                'resource',
            );
        }

        // The encoding binary must also accept and decode these unchanged
        // bytes under the whitelist before its filter reopens the file.
        $decode = $runner->run([
            config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'), '-v', 'error', '-nostdin',
            '-protocol_whitelist', 'file', '-format_whitelist', 'srt,ass,webvtt', '-i', $resource['path'],
            '-map', '0:s:0', '-c:s', 'ass', '-f', 'null', '-',
        ], $workspace->path);

        if ($decode->exitCode !== 0) {
            throw MediaException::probing("Cannot decode {$resource['context']}.", 'resource');
        }
    }

    /**
     * Resolve the output's maps to FFmpeg map specifiers, matching probed streams to their inputs.
     */
    protected function resolveMaps(ExportOutput $output): array
    {
        if ($this->coverInput !== null) {
            return ['0:V?', '0:a?', "{$this->coverInput}:v:0"];
        }

        return array_map(function (string|array $map): string {
            if (is_string($map)) {
                return $map;
            }

            $source = $map['stream']->sourceIdentity();
            $input = $map['input'];

            if ($input === null) {
                $matches = collect($this->inputs)
                    ->filter(fn ($media): bool => $media->identity() === $source)
                    ->keys()
                    ->all();

                if (count($matches) !== 1) {
                    throw new InvalidArgumentException(
                        'A probed stream must match exactly one input. Add its media or specify input explicitly for repeated media.',
                    );
                }

                $input = $matches[0];
            }

            if (! isset($this->inputs[$input]) || $this->inputs[$input]->identity() !== $source) {
                throw new InvalidArgumentException('The probed stream does not belong to the selected input.');
            }

            return "{$input}:{$map['stream']->index()}".($map['optional'] ? '?' : '');
        }, $output->maps());
    }

    /**
     * Get the stream types the output selects, in output order.
     *
     * A null type is a graph pad whose type FFmpeg resolves.
     */
    protected function selectedStreams(ExportOutput $output, Workspace $workspace, array $metadata): array
    {
        $selected = [];

        foreach ($this->resolveMaps($output) as $map) {
            if (str_starts_with($map, '[')) {
                $selected[] = ['identity' => $map, 'type' => null];

                continue;
            }

            preg_match('/^(-?)(\d+)(?::(.*?))?(\?)?$/D', $map, $match);
            $exclude = $match[1] === '-';
            $input = (int) $match[2];
            $selector = $match[3] ?? '';

            if (str_ends_with($map, ':?')) {
                $selector = substr($selector, 0, -1);
            }

            $streams = $selector === ''
                ? $metadata[$input]->streams()
                : $workspace->probe($this->inputs[$input], $selector)->streams();

            if ($streams->isEmpty() && ! $exclude && ! str_ends_with($map, '?')) {
                throw MediaException::probing('A required stream map matched no streams.', 'mapping');
            }

            foreach ($streams as $stream) {
                $identity = "{$input}:{$stream->get('index')}";

                if ($exclude) {
                    $selected = collect($selected)
                        ->filter(fn (array $item): bool => $item['identity'] !== $identity)
                        ->values()
                        ->all();
                } else {
                    $selected[] = ['identity' => $identity, 'type' => $stream->get('codec_type')];
                }
            }
        }

        if ($output->hasMaps() && $selected === []) {
            throw MediaException::probing('Explicit stream maps selected no output streams.', 'mapping');
        }

        return array_column($selected, 'type');
    }
}

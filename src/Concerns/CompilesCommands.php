<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\FilterGraph;
use ProtoneMedia\LaravelFFMpeg\Execution\ResourceFilter;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\ExportOutput;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\Pipeline\OwnsGraph;
use ProtoneMedia\LaravelFFMpeg\Pipeline\PackagesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesCommand;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesOutputs;
use ProtoneMedia\LaravelFFMpeg\Pipeline\SplitsTwoPass;
use ProtoneMedia\LaravelFFMpeg\Pipeline\WritesManifest;

/**
 * Compiles the FFmpeg command of an export.
 */
trait CompilesCommands
{
    /**
     * Get the outputs to compile, letting the builder shape them when it implements the hook.
     */
    protected function configuredOutputs(?string $path = null): array
    {
        if ($this->outputs !== []) {
            if ($path !== null) {
                throw new InvalidArgumentException('Use save() without a path after output().');
            }

            $outputs = $this->outputs;
        } else {
            $outputs = [
                new ExportOutput($path ?? 'inspection.mp4', $this->disk)->configure($this->outputConfiguration()),
            ];
        }

        return $this->operation instanceof ShapesOutputs ? $this->operation->outputs($outputs, $path) : $outputs;
    }

    /**
     * Assign every declared and helper resource a private path in the workspace.
     */
    protected function resourceNames(array $outputs): array
    {
        $declared = $this->resources;

        foreach ($declared as $name => $resource) {
            $declared[$name]['context'] = "filter resource {$name}";
        }

        foreach ($outputs as $index => $output) {
            foreach ($output->resourceFilters() as $filter) {
                foreach ($filter->resources as $role => $resource) {
                    $declared['helper:'.spl_object_id($filter).':'.$role] = $resource + [
                        'context' => "output {$index} {$filter->name}",
                    ];
                }
            }
        }

        $names = [];
        $paths = [];

        foreach ($declared as $name => $resource) {
            $extension = $resource['type'] === 'text' ? 'txt' : $resource['source']->extension();
            $identity = $resource['type'].':'
                .($resource['source'] instanceof Media ? $resource['source']->identity() : $name);
            $paths[$identity] ??= 'resource-'.count($paths).($extension === '' ? '' : ".{$extension}");
            $names[$name] = $resource + ['path' => $paths[$identity]];
        }

        return $names;
    }

    /**
     * Get the complex filter graph, preferring the one the builder owns.
     */
    protected function filterGraph(): ?string
    {
        return $this->operation instanceof OwnsGraph ? $this->operation->graph() : $this->graph;
    }

    /**
     * Compile the FFmpeg command for the given outputs and input paths.
     */
    protected function command(array $outputs, array $paths, ?array $outputPaths = null): array
    {
        if ($this->operation instanceof WritesManifest) {
            $outputs = array_slice($outputs, 0, -1);
        }

        $graph = $this->filterGraph();
        $this->ensureCoverArtCanCompile($graph, $outputs);
        $this->ensureWatermarksCanCompile($graph);
        $resources = $this->resourceNames($outputs);

        if (array_any($resources, fn ($resource): bool => $resource['type'] === 'subtitle')
            && array_any($outputs, fn ($output): bool => $output->inputSeekOptions() !== [])) {
            throw new InvalidArgumentException(
                'Subtitle resources require an unseeked input. Use an accurate output clip.',
            );
        }

        // Watermarks add their overlays to the graph one output at a time, so compiling
        // the outputs also returns the final graph, the graph labels the outputs map,
        // and the resources their filters use, which the global options then check.
        [$compiledOutputs, $graph, $mappedLabels, $used] = $this->compileOutputs(
            $outputs,
            $graph,
            $resources,
            $outputPaths,
        );
        $globals = $this->compileGlobals($graph, $mappedLabels, $resources, $used);
        $command = CommandCompiler::compile(
            config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'),
            $this->compileInputs($outputs, $paths),
            $compiledOutputs,
            $globals,
        );

        return $this->operation instanceof ShapesCommand
            ? $this->operation->command($command, $outputs, $paths, $outputPaths)
            : $command;
    }

    /**
     * Compile the path and options of every output, adding watermark overlays to the graph.
     *
     * @return array{
     *     0: list<array{path: string, options: list<string>}>,
     *     1: string|null,
     *     2: list<string>,
     *     3: array<string, true>
     * }
     */
    protected function compileOutputs(array $outputs, ?string $graph, array $resources, ?array $outputPaths): array
    {
        $covering = $this->coverInput !== null;
        $watermarking = $this->watermarks !== [];
        $composition = ! $covering && ! $watermarking && (count($this->inputs) > 1 || $graph !== null);
        $mappedLabels = [];
        $used = [];
        $compiledOutputs = [];

        foreach ($outputs as $index => $output) {
            $output = $this->prepareOutput($output);
            $this->ensureOutputMatchesInputs($output, $outputs, $graph, $composition);
            $maps = $this->resolveMaps($output);
            array_push($mappedLabels, ...$this->graphLabels($maps, $graph));

            $options = $output->outputArguments(
                function (string|ResourceFilter $filter) use ($resources, &$used): string {
                    if ($filter instanceof ResourceFilter) {
                        return $filter->toFilter(
                            fn ($role) => $resources['helper:'.spl_object_id($filter).':'.$role]['path'],
                        );
                    }

                    return FilterGraph::resources($filter, $resources, $used);
                },
                $maps,
            );

            if ($covering) {
                // Pure inspection does not probe the inputs, so like the input paths,
                // the cover video track stays a placeholder until execution.
                $options[] = '-disposition:v:'.($this->coverVideoTrack ?? '[cover-video-track]');
                $options[] = 'attached_pic';
            }

            if ($watermarking) {
                [$options, $branches] = $this->compileWatermarks($options, $index);
                $graph = ($graph === null ? '' : $graph.';').implode(';', $branches);
                $mappedLabels[] = "watermark_out{$index}";
            }

            if ($this->twoPass && in_array($index, $this->twoPassOutputIndexes($outputs), true)) {
                array_push($options, ...$this->secondPassOptions($output, $index));
            }

            $compiledOutputs[] = ['path' => $outputPaths[$index] ?? "[output:{$index}]", 'options' => $options];
        }

        return [$compiledOutputs, $graph, $mappedLabels, $used];
    }

    /**
     * Ensure the output's maps, metadata sources, and keyframe clip fit the export's inputs.
     */
    protected function ensureOutputMatchesInputs(
        ExportOutput $output,
        array $outputs,
        ?string $graph,
        bool $composition,
    ): void {
        if ($composition && ! $output->hasMaps()) {
            throw new InvalidArgumentException('Every composition output requires explicit maps.');
        }

        if (count($this->inputs) > 1 && $output->metadataSource() === null) {
            throw new InvalidArgumentException(
                'Exports with multiple inputs require metadataFrom() or withoutMetadata().',
            );
        }

        if (count($this->inputs) > 1 && $output->chapterSource() === null) {
            throw new InvalidArgumentException(
                'Exports with multiple inputs require chaptersFrom() or withoutMetadata(chapters: true).',
            );
        }

        foreach ([$output->metadataSource(), $output->chapterSource()] as $source) {
            if ($source !== null && $source >= count($this->inputs)) {
                throw new InvalidArgumentException("The metadata or chapter input [{$source}] does not exist.");
            }
        }

        if ($output->inputSeekOptions() !== []) {
            if (count($this->inputs) > 1) {
                throw new InvalidArgumentException('Keyframe clips require a single input.');
            }

            if (count($outputs) > 1) {
                throw new InvalidArgumentException('Keyframe clips require a single output.');
            }

            if ($graph !== null) {
                throw new InvalidArgumentException('Keyframe clips do not support a complex filter graph.');
            }
        }
    }

    /**
     * Validate the resolved maps and return the graph labels they select.
     *
     * @return list<string>
     */
    protected function graphLabels(array $maps, ?string $graph): array
    {
        $labels = [];

        foreach ($maps as $map) {
            if (str_starts_with($map, '[')) {
                if ($graph === null) {
                    throw new InvalidArgumentException('A graph map requires filterComplex().');
                }

                $labels[] = substr($map, 1, -1);
            } elseif ((int) ltrim($map, '-') >= count($this->inputs)) {
                throw new InvalidArgumentException('Map references an invalid input.');
            }
        }

        return $labels;
    }

    /**
     * Validate the two-pass output's video encoding and return its second-pass options.
     *
     * @return list<string>
     */
    protected function secondPassOptions(ExportOutput $output, int|string $index): array
    {
        $settings = $output->outputConfiguration();
        $video = $settings['video'];

        if ($settings['omitVideo']) {
            throw new InvalidArgumentException('Two-pass outputs must keep their video.');
        }

        if (! isset($video['codec'])) {
            throw new InvalidArgumentException('Two-pass outputs require an explicit video encoder.');
        }

        if (! isset($video['bitrate'])) {
            throw new InvalidArgumentException('Two-pass outputs require a target video bitrate.');
        }

        if ($video['codec'] === 'copy') {
            throw new InvalidArgumentException('Two-pass outputs must transcode video, not copy it.');
        }

        if (isset($video['crf'])) {
            throw new InvalidArgumentException('Two-pass outputs use a target bitrate, not CRF.');
        }

        return ['-pass:v', '2', '-passlogfile:v', "pass-{$index}"];
    }

    /**
     * Compile the global options, including the validated complex graph.
     *
     * @param  array<string, true>  $used
     * @return list<string>
     */
    protected function compileGlobals(?string $graph, array $mappedLabels, array $resources, array $used): array
    {
        $globals = $this->globalOptions;

        if ($graph !== null) {
            FilterGraph::validate($graph, count($this->inputs), $mappedLabels);
            $globals[] = '-filter_complex';
            $globals[] = FilterGraph::resources($graph, $resources, $used);
        }

        if (count($used) !== count($this->resources)) {
            throw new InvalidArgumentException('Every declared resource must be used by a filter.');
        }

        return $globals;
    }

    /**
     * Compile the path, protocols, and options of every input.
     *
     * @return list<array{path: string, protocols: mixed, options: list<string>}>
     */
    protected function compileInputs(array $outputs, array $paths): array
    {
        $compiledInputs = [];
        // The demuxer repeats a looping mark, so a GIF loops for the whole video whatever loop count it embeds.
        $looping = collect($this->watermarks)
            ->filter(fn (array $mark): bool => $mark['loop'])
            ->pluck('loop', 'input')
            ->all();

        foreach ($this->inputs as $index => $input) {
            $compiledInputs[] = [
                'path' => $paths[$index], 'protocols' => $input->protocolWhitelist(),
                'options' => [
                    ...$input->transportOptions(),
                    ...$input->inputOptions(),
                    ...(isset($looping[$index]) ? ['-stream_loop', '-1'] : []),
                    ...($index === 0 ? $outputs[0]->inputSeekOptions() : []),
                ],
            ];
        }

        return $compiledInputs;
    }

    /**
     * Compile the first-pass command, which writes its analysis inside the private workspace.
     *
     * The analysis output never goes to a publication path.
     */
    protected function firstPassCommand(
        array $command,
        array $outputPaths,
        Workspace $workspace,
        array &$redactions,
    ): array {
        if ($this->operation instanceof SplitsTwoPass) {
            $command = $this->operation->analysisCommand($command);
        }

        $result = [];

        foreach ($command as $index => $token) {
            $output = array_search($token, $outputPaths, true);

            if ($output !== false) {
                $target = "{$workspace->path}/analysis-{$output}.null";
                $redactions[$target] = "[analysis:{$output}]";
                $result[] = '-f';
                $result[] = 'null';
                $result[] = $target;
            } else {
                $result[] = ($command[$index - 1] ?? null) === '-pass:v' ? '1' : $token;
            }
        }

        return $result;
    }

    /**
     * Get the private workspace paths the outputs are encoded to.
     */
    protected function outputPaths(array $outputs, Workspace $workspace): array
    {
        $paths = collect($outputs)->keys()->map(function (int|string $index) use ($outputs, $workspace): string {
            $extension = pathinfo($outputs[$index]->destination()['path'], PATHINFO_EXTENSION);

            return "{$workspace->path}/output-{$index}".($extension === '' ? '' : ".{$extension}");
        })->all();

        return $this->operation instanceof PackagesArtifacts
            ? $this->operation->outputPaths($paths, $outputs, $workspace)
            : $paths;
    }

    /**
     * Get the artifacts to validate and publish, letting the builder package them when it implements the hook.
     */
    protected function artifacts(array $outputs, array $paths): array
    {
        $artifacts = collect($outputs)->keys()->map(fn (int|string $index): array => [
            'path' => $paths[$index], 'output' => $index, 'destination' => $outputs[$index]->destination(),
        ])->all();

        return $this->operation instanceof PackagesArtifacts
            ? $this->operation->artifacts($artifacts, $outputs, $paths)
            : $artifacts;
    }
}

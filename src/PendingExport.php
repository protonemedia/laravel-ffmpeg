<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\FilterGraph;
use ProtoneMedia\LaravelFFMpeg\Execution\ProcessRunner;
use ProtoneMedia\LaravelFFMpeg\Execution\ProgressParser;
use ProtoneMedia\LaravelFFMpeg\Execution\ResourceFilter;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\MeasuresDuration;
use ProtoneMedia\LaravelFFMpeg\Pipeline\OwnsGraph;
use ProtoneMedia\LaravelFFMpeg\Pipeline\PackagesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Pipeline\RunsAnalysis;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesCommand;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesOutputs;
use ProtoneMedia\LaravelFFMpeg\Pipeline\SplitsTwoPass;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ValidatesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ValidatesSelections;
use ProtoneMedia\LaravelFFMpeg\Pipeline\WritesManifest;
use Throwable;

/**
 * Configure and run an FFmpeg export of one or more inputs.
 */
class PendingExport implements Exportable
{
    use Conditionable, Macroable;
    use ConfiguresOutput;

    /**
     * The media inputs, in FFmpeg input order.
     */
    protected array $inputs;

    /**
     * The outputs declared with output() callbacks.
     */
    protected array $outputs = [];

    /**
     * The files and text that filters reference by name.
     */
    protected array $resources = [];

    /**
     * The allowed global FFmpeg options.
     */
    protected array $globalOptions = [];

    /**
     * The complex filter graph of the export.
     */
    protected ?string $graph = null;

    /**
     * The watermarks overlaid on the main video.
     */
    protected array $watermarks = [];

    /**
     * The input index of the cover art image.
     */
    protected ?int $coverInput = null;

    /**
     * The output video track the cover art becomes, resolved after probing.
     */
    protected ?int $coverVideoTrack = null;

    /**
     * The callback that receives the progress of the execution.
     */
    protected ?Closure $progress = null;

    /**
     * The callback that cancels the execution when it returns true.
     */
    protected ?Closure $cancellation = null;

    /**
     * Indicates if output settings were configured on the export root.
     */
    protected bool $rootConfigured = false;

    /**
     * Indicates if the export is executing.
     */
    protected bool $running = false;

    /**
     * Indicates if the video is encoded in two passes.
     */
    protected bool $twoPass = false;

    /**
     * The total time budget of the execution in seconds.
     */
    protected ?float $timeoutSeconds = null;

    /**
     * The number of seconds a process may run without output.
     */
    protected ?float $idleTimeoutSeconds = null;

    /**
     * The process runner of the running execution.
     */
    protected ?ProcessRunner $runner = null;

    /**
     * The phase of the running execution, reported when it fails.
     */
    protected ?string $phase = null;

    /**
     * The destinations the running execution has published so far.
     */
    protected array $published = [];

    /**
     * The process summaries the running execution has recorded so far.
     */
    protected array $summaries = [];

    /**
     * The default role of the generated files, such as image or hls-playlist.
     */
    protected readonly string $outputRole;

    /**
     * Create a new pending export instance.
     *
     * @param  object|null  $operation  @internal A builder that holds this pipeline and implements Pipeline hooks.
     *                                  Each hook receives the value this class computes and returns the one to use.
     * @param  string|null  $role  @internal The default generated-file role, such as image or hls-playlist.
     */
    public function __construct(
        protected readonly Media $media,
        string|Filesystem $disk,
        protected readonly ?object $operation = null,
        ?string $role = null,
    ) {
        $this->inputs = [$media];
        $this->disk = $disk;
        $this->outputRole = $role ?? 'media';
    }

    /**
     * Create a pending queued export that runs the builder on a queue worker.
     */
    public function queued(
        ?string $connection = null,
        ?string $queue = null,
        \DateTimeInterface|\DateInterval|int|null $delay = null,
        bool $parallel = false,
    ): PendingQueuedExport {
        $this->assertMutable();

        // We will queue the builder that holds this pipeline rather than the pipeline
        // itself, so the worker restores and saves the same builder the caller used.
        $export = $this->operation instanceof Exportable ? $this->operation : $this;

        return new PendingQueuedExport($export, $connection, $queue, $delay, $parallel, $this->timeoutSeconds);
    }

    /**
     * Set the total time budget of the execution in seconds.
     *
     * Passing null restores the configured process limits.
     */
    public function timeout(?float $seconds): self
    {
        $this->assertMutable();
        $this->ensureTimeoutIsPositive('operation', $seconds);

        $this->timeoutSeconds = $seconds;

        return $this;
    }

    /**
     * Set how many seconds a process may run without output.
     */
    public function idleTimeout(?float $seconds): self
    {
        $this->assertMutable();
        $this->ensureTimeoutIsPositive('idle', $seconds);

        $this->idleTimeoutSeconds = $seconds;

        return $this;
    }

    /**
     * Ensure the given timeout is either null or a finite, positive number of seconds.
     */
    protected function ensureTimeoutIsPositive(string $kind, ?float $seconds): void
    {
        if ($seconds !== null && (! is_finite($seconds) || $seconds <= 0)) {
            throw new InvalidArgumentException("The {$kind} timeout must be finite and positive.");
        }
    }

    /**
     * Cancel the execution when the given callback returns true.
     *
     * Queue workers use this for batch cancellation, independent of the user's progress callback.
     *
     * @internal
     */
    public function cancelWhen(callable $callback): self
    {
        $this->cancellation = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Ensure output settings may still be configured on the export root.
     *
     * Builders call this before changing settings they own, such as storyboard cues or HLS variants.
     *
     * @internal
     */
    public function assertOutputMutable(): void
    {
        $this->assertMutable();

        if ($this->outputs !== []) {
            throw new InvalidArgumentException(
                'Configure each output in its output() callback, not on the export root.',
            );
        }

        $this->rootConfigured = true;
    }

    /**
     * Ensure the export is not executing.
     */
    protected function assertMutable(): void
    {
        if ($this->running) {
            throw new InvalidArgumentException('An executing export cannot be changed.');
        }
    }

    /**
     * Add an input to the export and get its FFmpeg input index.
     *
     * The index is stable because staging reuse never removes an input.
     */
    public function addInput(Media $media): int
    {
        $this->assertMutable();
        $this->inputs[] = $media;

        if (! $this->outputDiskExplicit) {
            $this->disk = config('filesystems.default');
        }

        return array_key_last($this->inputs);
    }

    /**
     * Declare a file that video filters reference by the given name.
     */
    public function resource(string $name, Media $media): self
    {
        return $this->addResource($name, 'file', $media);
    }

    /**
     * Declare a UTF-8 text file that video filters reference by the given name.
     */
    public function textResource(string $name, string $text): self
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException("The text resource [{$name}] must be valid UTF-8.");
        }

        if (str_contains($text, "\0")) {
            throw new InvalidArgumentException("The text resource [{$name}] must not contain NUL bytes.");
        }

        return $this->addResource($name, 'text', $text);
    }

    /**
     * Declare a subtitle file that video filters reference by the given name.
     */
    public function subtitleResource(string $name, Media $media): self
    {
        if ($media->inputOptions() !== []) {
            throw new InvalidArgumentException("The subtitle resource [{$name}] does not accept input options.");
        }

        return $this->addResource($name, 'subtitle', $media);
    }

    /**
     * Declare a named resource of the given type.
     */
    protected function addResource(string $name, string $type, Media|string $source): self
    {
        $this->assertMutable();

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $name)) {
            throw new InvalidArgumentException("The resource name [{$name}] must be a simple identifier.");
        }

        if (isset($this->resources[$name])) {
            throw new InvalidArgumentException("The resource [{$name}] is already declared.");
        }

        $this->resources[$name] = ['type' => $type, 'source' => $source];

        return $this;
    }

    /**
     * Set the complex filter graph of the export.
     */
    public function filterComplex(string $expression): self
    {
        $this->assertMutable();
        FilterGraph::validateExpression($expression);

        if ($this->graph !== null) {
            throw new InvalidArgumentException('Declare one complex graph per export.');
        }

        $this->graph = $expression;

        return $this;
    }

    /**
     * Overlay a PNG, JPEG or GIF after all video filters, bottom-right by default.
     *
     * @param  (callable(Watermark): mixed)|null  $configure  Runs immediately; its return value is ignored.
     */
    public function watermark(Media $image, ?callable $configure = null): self
    {
        $this->assertMutable();

        if ($image->inputOptions() !== []) {
            throw new InvalidArgumentException(
                'Watermark images take no input options. Call loop() on the watermark to repeat a GIF.',
            );
        }

        $watermark = new Watermark;
        if ($configure !== null) {
            $configure($watermark);
        }

        $settings = $watermark->settings();
        $this->inputs[] = $image->forWatermark($settings['edits']);
        unset($settings['edits']);
        $this->watermarks[] = ['input' => array_key_last($this->inputs)] + $settings;

        return $this;
    }

    /**
     * Attach a standalone PNG or JPEG image as cover art.
     *
     * The motion video and audio are copied unchanged.
     */
    public function coverArt(Media $image): self
    {
        $this->assertMutable();
        if ($this->coverInput !== null) {
            throw new InvalidArgumentException('An export accepts only one cover art image.');
        }

        if ($image->inputOptions() !== []) {
            throw new InvalidArgumentException('Cover art images take no input options.');
        }

        $this->inputs[] = $image;
        $this->coverInput = array_key_last($this->inputs);

        return $this;
    }

    /**
     * Declare an output and configure it in the given callback.
     */
    public function output(string $path, callable $configure): self
    {
        $this->assertMutable();

        if ($this->rootConfigured) {
            throw new InvalidArgumentException('Do not combine root output settings with output() callbacks.');
        }

        $output = new ExportOutput($path, config('filesystems.default'));
        $configure($output);
        $this->outputs[] = $output;

        return $this;
    }

    /**
     * Add allowed global FFmpeg options to the command.
     */
    public function globalOptions(array $tokens): self
    {
        $this->assertMutable();
        $this->globalOptions = CommandCompiler::options([...$this->globalOptions, ...$tokens], 'global');

        return $this;
    }

    /**
     * Register a callback that receives the progress of the execution.
     */
    public function onProgress(callable $callback): self
    {
        $this->assertMutable();
        $this->progress = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Get the redacted command without creating files or running processes.
     */
    public function inspect(): array
    {
        return $this->command(
            $this->configuredOutputs(),
            array_map(fn (int|string $i): string => '[input:'.$i.']', array_keys($this->inputs)),
        );
    }

    /**
     * Encode the video in two passes.
     */
    public function twoPass(): self
    {
        $this->assertMutable();

        if (! in_array($this->outputRole, ['media', 'hls-playlist'], true)) {
            throw new InvalidArgumentException('Two-pass encoding requires video media outputs, not images.');
        }

        $this->twoPass = true;

        return $this;
    }

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
            $declared[$name]['context'] = 'filter resource '.$name;
        }

        foreach ($outputs as $index => $output) {
            foreach ($output->resourceFilters() as $filter) {
                foreach ($filter->resources as $role => $resource) {
                    $declared['helper:'.spl_object_id($filter).':'.$role] = $resource + [
                        'context' => 'output '.$index.' '.$filter->name,
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
            $paths[$identity] ??= 'resource-'.count($paths).($extension === '' ? '' : '.'.$extension);
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
     * Ensure cover art is combined only with the inputs, outputs, and settings it supports.
     */
    protected function ensureCoverArtCanCompile(?string $graph, array $outputs): void
    {
        if ($this->coverInput === null) {
            return;
        }

        if ($graph !== null) {
            throw new InvalidArgumentException('Cover art does not support a complex filter graph.');
        }

        if ($this->watermarks !== []) {
            throw new InvalidArgumentException('Cover art cannot be combined with watermarks.');
        }

        if (count($this->inputs) !== 2) {
            throw new InvalidArgumentException('Cover art requires exactly one source input.');
        }

        if (count($outputs) !== 1) {
            throw new InvalidArgumentException('Cover art requires exactly one output.');
        }

        if ($this->outputRole !== 'media') {
            throw new InvalidArgumentException("The output role [{$this->outputRole}] does not support cover art.");
        }

        if ($this->twoPass) {
            throw new InvalidArgumentException('Cover art does not support two-pass encoding.');
        }
    }

    /**
     * Ensure watermarks are combined only with the inputs and output roles they support.
     */
    protected function ensureWatermarksCanCompile(?string $graph): void
    {
        if ($this->watermarks === []) {
            return;
        }

        if ($graph !== null) {
            throw new InvalidArgumentException(
                'Watermarks do not support filterComplex(); compose the overlay in the graph instead.',
            );
        }

        if (count($this->inputs) !== count($this->watermarks) + 1) {
            throw new InvalidArgumentException('Watermarks require exactly one source input besides their images.');
        }

        if (! in_array($this->outputRole, ['media', 'hls-playlist'], true)) {
            throw new InvalidArgumentException("The output role [{$this->outputRole}] does not support watermarks.");
        }
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
                    return $filter instanceof ResourceFilter
                        ? $filter->toFilter(
                            fn ($role) => $resources['helper:'.spl_object_id($filter).':'.$role]['path'],
                        )
                        : FilterGraph::resources($filter, $resources, $used);
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
                $mappedLabels[] = 'watermark_out'.$index;
            }

            if ($this->twoPass && in_array($index, $this->twoPassOutputIndexes($outputs), true)) {
                array_push($options, ...$this->secondPassOptions($output, $index));
            }

            $compiledOutputs[] = ['path' => $outputPaths[$index] ?? '[output:'.$index.']', 'options' => $options];
        }

        return [$compiledOutputs, $graph, $mappedLabels, $used];
    }

    /**
     * Apply the settings that two-pass encoding, cover art, and watermarks require of the output.
     */
    protected function prepareOutput(ExportOutput $output): ExportOutput
    {
        if ($this->twoPass && $output->outputConfiguration()['videoStreams'] !== []) {
            throw new InvalidArgumentException('Per-track video settings do not support two-pass encoding.');
        }

        if ($this->coverInput !== null) {
            $output = $this->coverArtOutput($output);
        }

        if ($this->watermarks !== []) {
            $output = $this->watermarkedOutput($output);
        }

        return $output;
    }

    /**
     * Validate the cover art output and copy its source video, audio, metadata, and chapters.
     */
    protected function coverArtOutput(ExportOutput $output): ExportOutput
    {
        $settings = $output->outputConfiguration();
        if (! in_array(
            strtolower(pathinfo($output->destination()['path'], PATHINFO_EXTENSION)),
            ['mp4', 'm4a', 'mp3'],
            true,
        )) {
            throw new InvalidArgumentException('Cover art requires an MP4, M4A, or MP3 destination.');
        }

        if ($output->hasMaps()) {
            throw new InvalidArgumentException(
                'Cover art outputs select their streams automatically and take no maps.',
            );
        }

        if ($settings['omitVideo'] || $settings['omitAudio']) {
            throw new InvalidArgumentException('Cover art outputs must keep the source video and audio.');
        }

        if ($settings['videoFilters'] !== [] || $settings['audioFilters'] !== []) {
            throw new InvalidArgumentException('Cover art outputs do not support filters.');
        }

        if ($settings['videoStreams'] !== [] || $settings['audioStreams'] !== []) {
            throw new InvalidArgumentException('Cover art outputs do not support per-track settings.');
        }

        if ($settings['outputOptions'] !== []) {
            throw new InvalidArgumentException('Cover art outputs do not support output options.');
        }

        if ($settings['dispositions'] !== []) {
            throw new InvalidArgumentException('Cover art outputs do not support disposition overrides.');
        }

        if ($settings['clip'] !== null) {
            throw new InvalidArgumentException('Cover art outputs do not support clipping.');
        }

        if (! in_array($settings['video'], [[], ['codec' => 'copy']], true)
            || ! in_array($settings['audio'], [[], ['codec' => 'copy']], true)) {
            throw new InvalidArgumentException('Cover art outputs copy video and audio without encoding settings.');
        }

        $settings['video'] = $settings['audio'] = ['codec' => 'copy'];
        $settings['metadataSource'] ??= 0;
        $settings['chapterSource'] ??= 0;

        return new ExportOutput($output->destination()['path'], $output->filesystem())->configure($settings);
    }

    /**
     * Validate the watermarked output and take its metadata and chapters from the source.
     */
    protected function watermarkedOutput(ExportOutput $output): ExportOutput
    {
        $settings = $output->outputConfiguration();
        if ($output->hasMaps()) {
            throw new InvalidArgumentException(
                'Watermarked outputs select their video automatically and take no maps.',
            );
        }

        if ($settings['omitVideo']) {
            throw new InvalidArgumentException('Watermarked outputs must keep their video.');
        }

        if (($settings['video']['codec'] ?? null) === 'copy') {
            throw new InvalidArgumentException('Watermarked outputs must transcode video, not copy it.');
        }

        $settings['metadataSource'] ??= 0;
        $settings['chapterSource'] ??= $settings['clip'] === null ? 0 : -1;

        return new ExportOutput($output->destination()['path'], $output->filesystem())->configure($settings);
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
     * Route the output's main video through the watermark overlays instead of its own filter.
     *
     * @return array{0: list<string>, 1: list<string>} The rewritten output options and the graph branches to add.
     */
    protected function compileWatermarks(array $options, int|string $index): array
    {
        $filter = 'null';
        $processed = [];
        for ($i = 0; $i < count($options); $i++) {
            // The per-track chain of the one mapped video stream already includes the
            // output filters and comes after them, so the last chain we see wins.
            if ($options[$i] === '-filter:v' || $options[$i] === '-filter:v:0') {
                $filter = $options[++$i];
            } elseif ($options[$i] === '-map' && str_starts_with($options[$i + 1], '0:V:0')) {
                $processed[] = '-map';
                $processed[] = '[watermark_out'.$index.']';
                $i++;
            } else {
                $processed[] = $options[$i];
            }
        }

        return [$processed, $this->overlayWatermarks($filter, $index)];
    }

    /**
     * Build the graph branches that filter the main video and overlay each watermark on it.
     *
     * @return list<string>
     */
    protected function overlayWatermarks(string $filter, int|string $index): array
    {
        $branches = ['[0:V:0]'.$filter.'[watermark_base'.$index.'_0]'];
        foreach ($this->watermarks as $n => $mark) {
            [
                'input' => $input,
                'horizontal' => [$left, $x],
                'vertical' => [$top, $y],
                'width' => $width,
                'height' => $height,
                'opacity' => $opacity,
                'loop' => $loop
            ] = $mark;
            $imageFilters = $width === null && $height === null
                ? ''
                : 'scale=w='.($width ?? -1).':h='.($height ?? -1).',';
            $label = 'watermark_image'.$index.'_'.$n;
            $branches[] = '['.$input.':v:0]'.$imageFilters.'format=rgba,colorchannelmixer=aa='.$opacity.'['.$label.']';
            $horizontal = match ($left) {
                'right' => 'main_w-overlay_w-'.$x, 'center' => '(main_w-overlay_w)/2', default => (string) $x,
            };
            $vertical = match ($top) {
                'bottom' => 'main_h-overlay_h-'.$y, 'center' => '(main_h-overlay_h)/2', default => (string) $y,
            };
            $out = $n === count($this->watermarks) - 1 ? 'watermark_out'.$index : 'watermark_base'.$index.'_'.($n + 1);
            // A looping mark never ends, so we will let the main video bound it. Without
            // a loop, every stream keeps its own length.
            $shortest = $loop ? 1 : 0;
            $branches[] = '[watermark_base'.$index.'_'.$n.']['.$label.']overlay=x='.$horizontal.':y='.$vertical
                .':eof_action=repeat:repeatlast=1:shortest='.$shortest.'['.$out.']';
        }

        return $branches;
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

        return ['-pass:v', '2', '-passlogfile:v', 'pass-'.$index];
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
        $looping = array_column(
            array_filter($this->watermarks, fn (array $mark): bool => $mark['loop']),
            'loop',
            'input',
        );

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
     * Run the export and publish it to the given path or the declared outputs.
     */
    public function save(?string $path = null): ExportResult
    {
        return $this->execute($path);
    }

    /**
     * Run the export and get its result.
     *
     * With $contents, the one generated file is validated and its bytes are returned instead of published.
     *
     * @internal
     */
    public function execute(?string $path, bool $contents = false): ExportResult|string
    {
        $this->assertMutable();

        $outputs = $this->freezeOutputs($path);
        $this->running = true;
        $operationId = bin2hex(random_bytes(16));
        $destinations = $contents ? [] : array_map(fn ($output) => $output->destination(), $outputs);
        $this->published = [];
        $this->phase = Progress::STAGING;
        $onProgress = fn (Progress $progress) => $this->notify($progress, $operationId, $destinations);

        try {
            $this->runner = new ProcessRunner($this->timeoutSeconds, $this->idleTimeoutSeconds);
            if (config('laravel-ffmpeg.enable_events', true)) {
                Event::dispatch(new Events\ExportStarting($operationId, $destinations));
            }

            $onProgress(new Progress($this->phase));
            $result = $this->media->withInput(
                fn (string $input, Workspace $workspace): string|ExportResult => $this->exportInWorkspace(
                    $workspace,
                    $outputs,
                    $contents,
                    $onProgress,
                ),
                $this->runner,
            );
        } catch (Throwable $throwable) {
            // A failure reports the phase it stopped in and every destination already
            // published, so listeners and callers can clean up a partial publication.
            throw $this->reportFailure($throwable, $operationId, $destinations);
        } finally {
            $this->running = false;
            $this->runner = null;
            $this->phase = null;
            $this->published = [];
            $this->summaries = [];
        }

        if (config('laravel-ffmpeg.enable_events', true)) {
            Event::dispatch(new Events\ExportCompleted(
                $operationId,
                $result instanceof ExportResult ? $result->files()->map->toArray()->all() : [],
            ));
        }

        return $result;
    }

    /**
     * Validate the configured outputs and their command, then freeze the outputs.
     */
    protected function freezeOutputs(?string $path): array
    {
        if ($path === null && $this->outputs === []) {
            throw new InvalidArgumentException('Declare outputs or pass a destination to save().');
        }

        $outputs = $this->configuredOutputs($path);
        $this->command($outputs, array_map(fn (int|string $i): string => '[input:'.$i.']', array_keys($this->inputs)));
        $this->validateDestinations($outputs);

        foreach ($outputs as $output) {
            $output->freeze();
        }

        return $outputs;
    }

    /**
     * Stage, probe, encode, validate, and publish the export inside the workspace.
     */
    protected function exportInWorkspace(
        Workspace $workspace,
        array $outputs,
        bool $contents,
        Closure $onProgress,
    ): string|ExportResult {
        [$paths, $redactions] = $this->stageInputs($workspace);
        $resources = $this->resourceNames($outputs);
        $this->stageResources($workspace, $resources);

        $this->phase = Progress::PROBING;
        $onProgress(new Progress($this->phase));
        $metadata = $this->probeInputs($workspace, $resources);
        $expected = $this->expectedStreams($workspace, $outputs, $metadata);
        $outputPaths = $this->outputPaths($outputs, $workspace);

        foreach ($outputPaths as $index => $outputPath) {
            $redactions[$outputPath] = '[output:'.$index.']';
        }

        $this->prepareOverlays($metadata);
        $duration = $this->progressDuration($outputs, $this->prepareEncoding($metadata));
        $this->phase = Progress::ENCODING;
        $command = $this->command($outputs, $paths, $outputPaths);
        $this->summaries = [];

        // Next, operations may run analysis passes that replace command tokens. Each
        // analysis reports its own phase and records its summary before the encoding
        // passes, so we return to the encoding phase once the command is prepared.
        $command = $this->prepareCommand(
            $workspace,
            $command,
            $outputPaths,
            fn (array $command, string $failure): ProcessResult => $this->runAnalysis(
                $workspace,
                $command,
                $failure,
                $redactions,
                $onProgress,
            ),
        );
        $this->phase = Progress::ENCODING;
        $this->runEncodingPasses($workspace, $command, $outputs, $outputPaths, $redactions, $duration, $onProgress);

        $this->phase = Progress::VALIDATION;
        $onProgress(new Progress($this->phase));
        $artifacts = $this->artifacts($outputs, $outputPaths);
        $sizes = $this->validateArtifacts($workspace, $artifacts, $outputs, $expected);

        if ($contents) {
            return $this->readGeneratedFile($artifacts[0]['path']);
        }

        $this->phase = Progress::PUBLICATION;
        $onProgress(new Progress($this->phase));
        $files = $this->publishArtifacts($workspace->runner, $artifacts, $outputs, $sizes);
        $workspace->runner->check(Progress::PUBLICATION);

        return new ExportResult($files, $this->summaries);
    }

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
            $redactions[$path] ??= '[input:'.$index.']';
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
            $target = $workspace->path.'/'.$resource['path'];

            if (isset($staged[$target])) {
                continue;
            }

            try {
                $written = $resource['type'] === 'text'
                    ? @file_put_contents($target, $source) === strlen($source)
                    : @copy($workspace->stage($source, resource: true), $target);
            } catch (MediaException $exception) {
                throw MediaException::staging('Cannot stage '.$resource['context'].'.', $exception->category);
            }

            if (! $written || ! chmod($target, 0600)) {
                throw MediaException::staging('Cannot stage '.$resource['context'].'.', 'transfer');
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
                    && max(array_keys($tracks)) >= count(array_filter(
                        $expected[$index],
                        fn ($selected): bool => $selected === $type,
                    ))) {
                    throw MediaException::probing(
                        ucfirst($type).' track settings require existing output '.$type.' tracks.',
                        'mapping',
                    );
                }
            }
        }
    }

    /**
     * Determine the longest expected output duration for encoding progress.
     */
    protected function progressDuration(array $outputs, ?float $duration): ?float
    {
        $durations = array_map(function ($output) use ($duration) {
            $clip = $output->clipSettings();

            return $duration === null
                ? null
                : ($clip === null ? $duration : max(0, min($clip['duration'], $duration - $clip['start'])));
        }, $outputs);

        return $duration === null ? null : max($durations);
    }

    /**
     * Run one analysis pass, recording its summary ahead of the encoding passes.
     */
    protected function runAnalysis(
        Workspace $workspace,
        array $command,
        string $failure,
        array $redactions,
        Closure $onProgress,
    ): ProcessResult {
        $this->phase = Progress::ANALYSIS;
        $onProgress(new Progress($this->phase));
        $process = $workspace->runner->run(
            $command,
            $workspace->path,
            $redactions,
            phase: Progress::ANALYSIS,
            progress: new ProgressParser(null, Progress::ANALYSIS, notify: $onProgress),
        );

        if ($process->exitCode !== 0) {
            throw MediaException::analysis($failure, 'exit', $process->diagnostics);
        }

        $this->summaries[] = $process->summary();

        return $process;
    }

    /**
     * Run each encoding pass, recording its summary.
     */
    protected function runEncodingPasses(
        Workspace $workspace,
        array $command,
        array $outputs,
        array $outputPaths,
        array $redactions,
        ?float $duration,
        Closure $onProgress,
    ): void {
        $totalPasses = $this->twoPass ? 2 : 1;

        for ($pass = 1; $pass <= $totalPasses; $pass++) {
            $onProgress(new Progress($this->phase, pass: $pass, totalPasses: $totalPasses));
            $passCommand = $command;

            if ($totalPasses === 2 && $pass === 1) {
                $passCommand = $this->firstPassCommand($command, $outputPaths, $workspace, $redactions);
            }

            $process = $workspace->runner->run(
                $passCommand,
                $workspace->path,
                $redactions,
                phase: $this->phase,
                progress: new ProgressParser($duration, $this->phase, $pass, $totalPasses, $onProgress),
            );

            if ($process->exitCode !== 0) {
                $category = str_contains(
                    $process->diagnostics,
                    'Streamcopy requested for output stream fed from a complex filtergraph',
                )
                    || str_contains($process->diagnostics, 'Simple and complex filtering cannot be used together')
                    ? 'configuration' : 'exit';

                throw new MediaException(
                    'FFmpeg failed (exit '.$process->exitCode.').',
                    $this->phase,
                    $category,
                    $process->diagnostics,
                );
            }

            $this->summaries[] = $process->summary();

            if ($totalPasses === 2 && $pass === 1) {
                $this->ensurePassStatisticsExist($workspace, $outputs);
            }
        }
    }

    /**
     * Ensure the first encoding pass wrote statistics for every two-pass output.
     */
    protected function ensurePassStatisticsExist(Workspace $workspace, array $outputs): void
    {
        foreach ($this->twoPassOutputIndexes($outputs) as $index) {
            $logs = glob($workspace->path.'/pass-'.$index.'-*.log') ?: [];

            if (! array_any($logs, fn (string $log): bool => is_file($log) && filesize($log) > 0)) {
                throw new MediaException(
                    'The first encoding pass did not produce statistics for output '.$index.'.',
                    $this->phase,
                    'artifact',
                );
            }
        }
    }

    /**
     * Validate every artifact, probing its streams when the output needs it.
     *
     * @return list<int> The size in bytes of each artifact.
     */
    protected function validateArtifacts(Workspace $workspace, array $artifacts, array $outputs, array $expected): array
    {
        $sizes = [];

        foreach ($artifacts as $artifact) {
            $workspace->runner->check(Progress::VALIDATION);
            $outputPath = $artifact['path'];
            $index = $artifact['output'];
            $role = $artifact['role'] ?? $this->outputRole;
            $size = $this->validateArtifact($outputPath, $role);
            $settings = $outputs[$index]->outputConfiguration();
            $configuredTracks = $settings['videoStreams'] !== [] || $settings['audioStreams'] !== [];

            if (($artifact['probe'] ?? false)
                || (in_array($role, ['media', 'image'], true)
                    && ($configuredTracks
                        || count($outputs) > 1
                        || count($this->inputs) > 1
                        || $this->filterGraph() !== null))) {
                $this->ensureArtifactStreamsMatch(
                    $workspace,
                    $artifact['probePath'] ?? $outputPath,
                    $expected[$index],
                    $settings,
                );
            }

            $sizes[] = $size;
        }

        return $sizes;
    }

    /**
     * Ensure the encoded streams match the output's maps and per-track settings.
     */
    protected function ensureArtifactStreamsMatch(
        Workspace $workspace,
        string $path,
        array $expected,
        array $settings,
    ): void {
        try {
            $actual = $this->media->probeArtifact($path, $workspace)
                ->streams()
                ->map(fn ($stream) => $stream->get('codec_type'))
                ->all();
        } catch (MediaException $mediaException) {
            if ($mediaException->category === 'timeout') {
                throw $mediaException;
            }

            throw new MediaException(
                'Cannot validate the encoded media.',
                $this->phase,
                'artifact',
                $mediaException->diagnostics,
            );
        }

        if (count($actual) !== count($expected)
            || array_any(
                array_keys($actual),
                fn (int $i): bool => $expected[$i] !== null && $actual[$i] !== $expected[$i],
            )) {
            throw new MediaException('Encoded streams do not match the explicit output maps.', $this->phase, 'mapping');
        }

        foreach (['video' => 'videoStreams', 'audio' => 'audioStreams'] as $type => $key) {
            if ($settings[$key] !== []
                && max(array_keys($settings[$key])) >= count(array_filter(
                    $actual,
                    fn ($selected): bool => $selected === $type,
                ))) {
                throw new MediaException(
                    ucfirst($type).' track settings require existing encoded output '.$type.' tracks.',
                    $this->phase,
                    'mapping',
                );
            }
        }
    }

    /**
     * Read the bytes of the validated generated file.
     */
    protected function readGeneratedFile(string $path): string
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new MediaException('Cannot read the generated image.', $this->phase, 'artifact');
        }

        return $bytes;
    }

    /**
     * Publish every artifact to its destination, tracking each published destination.
     *
     * @return list<GeneratedFile>
     */
    protected function publishArtifacts(ProcessRunner $runner, array $artifacts, array $outputs, array $sizes): array
    {
        $files = [];

        foreach ($artifacts as $index => $artifact) {
            $runner->check(Progress::PUBLICATION);
            $output = $artifact['target'] ?? $outputs[$artifact['output']];
            $destination = $artifact['destination'];
            $stream = null;

            try {
                $disk = $output->filesystem();
                $disk = is_string($disk) ? Storage::disk($disk) : $disk;
                $stream = fopen($artifact['path'], 'rb');

                if ($stream === false || ! $disk->put($destination['path'], $stream, $output->storageOptions())) {
                    throw new \RuntimeException('Publication failed.');
                }

                $this->published[] = $destination;

                if ($disk->size($destination['path']) !== $sizes[$index]) {
                    throw new \RuntimeException('Published size mismatch.');
                }
            } catch (Throwable) {
                throw new MediaException(
                    'Cannot publish the media output.',
                    $this->phase,
                    'transfer',
                    published: $this->published,
                    destination: $destination,
                );
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $files[] = new GeneratedFile(
                $disk,
                $destination['disk'],
                $destination['path'],
                $sizes[$index],
                role: $artifact['role'] ?? $this->outputRole,
            );
        }

        return $files;
    }

    /**
     * Dispatch the failed or cancelled event and return the exception to rethrow.
     */
    protected function reportFailure(Throwable $throwable, string $operationId, array $destinations): Throwable
    {
        if ($throwable instanceof MediaException && $throwable->category === 'timeout' && $this->published !== []) {
            $throwable = new MediaException($throwable->getMessage(), $throwable->phase, $throwable->category,
                $throwable->diagnostics, published: $this->published);
        }

        $event = $throwable instanceof ExportCancelled ? Events\ExportCancelled::class : Events\ExportFailed::class;

        try {
            if (config('laravel-ffmpeg.enable_events', true)) {
                Event::dispatch(new $event(
                    $operationId,
                    $throwable instanceof MediaException ? $throwable->phase : $this->phase,
                    $destinations,
                    $this->published,
                ));
            }
        } catch (Throwable) {
            // A failing listener must not hide the original process or storage failure.
        }

        return $throwable;
    }

    /**
     * Let the builder run analysis passes that may replace compiled command tokens.
     *
     * The given callback runs one analysis pass with progress, records its summary ahead of the encoding
     * passes, and throws the given message when the pass fails.
     *
     * @param  Closure(list<string>, string): ProcessResult  $analyze
     */
    protected function prepareCommand(Workspace $workspace, array $command, array $outputPaths, Closure $analyze): array
    {
        return $this->operation instanceof RunsAnalysis
            ? $this->operation->analyze($workspace, $command, $outputPaths, $analyze)
            : $command;
    }

    /**
     * Let the builder validate the resolved stream types before any encoder starts.
     */
    protected function validateStreamSelections(array $expected): void
    {
        if ($this->operation instanceof ValidatesSelections) {
            $this->operation->selections($expected);
        }
    }

    /**
     * Get the indexes of the outputs that need first-pass encoder statistics.
     *
     * Packaging-only outputs do not need them.
     */
    protected function twoPassOutputIndexes(array $outputs): array
    {
        return $this->operation instanceof SplitsTwoPass
            ? $this->operation->twoPassOutputs(array_keys($outputs), $outputs)
            : array_keys($outputs);
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
                $target = $workspace->path.'/analysis-'.$output.'.null';
                $redactions[$target] = '[analysis:'.$output.']';
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
        $paths = array_map(function (int|string $index) use ($outputs, $workspace): string {
            $extension = pathinfo($outputs[$index]->destination()['path'], PATHINFO_EXTENSION);

            return $workspace->path.'/output-'.$index.($extension === '' ? '' : '.'.$extension);
        }, array_keys($outputs));

        return $this->operation instanceof PackagesArtifacts
            ? $this->operation->outputPaths($paths, $outputs, $workspace)
            : $paths;
    }

    /**
     * Get the artifacts to validate and publish, letting the builder package them when it implements the hook.
     */
    protected function artifacts(array $outputs, array $paths): array
    {
        $artifacts = array_map(fn (int|string $index): array => [
            'path' => $paths[$index], 'output' => $index, 'destination' => $outputs[$index]->destination(),
        ], array_keys($outputs));

        return $this->operation instanceof PackagesArtifacts
            ? $this->operation->artifacts($artifacts, $outputs, $paths)
            : $artifacts;
    }

    /**
     * Validate the cover art and watermark inputs and resolve the facts command() needs for them.
     */
    protected function prepareOverlays(array $metadata): void
    {
        if ($this->coverInput !== null) {
            $image = $metadata[$this->coverInput];
            if (! in_array($image->get('format.format_name'), ['png_pipe', 'jpeg_pipe'], true)
                || $image->streams()->count() !== 1) {
                throw MediaException::probing('Cover art requires a standalone PNG or JPEG image.', 'cover-art');
            }

            if ($metadata[0]->streams()->contains(
                fn ($stream): bool => (int) $stream->get('disposition.attached_pic') === 1,
            )) {
                throw MediaException::probing('Cover art does not replace existing attached pictures.', 'cover-art');
            }

            if ($metadata[0]->streams()->contains(
                fn ($stream): bool => ! in_array($stream->get('codec_type'), ['video', 'audio'], true),
            )) {
                throw MediaException::probing(
                    'Cover art accepts sources containing only motion video and audio.',
                    'cover-art',
                );
            }

            $this->coverVideoTrack = $metadata[0]->streams()
                ->filter(fn ($stream): bool => $stream->get('codec_type') === 'video')
                ->count();
        }

        foreach ($this->watermarks as $mark) {
            $image = $metadata[$mark['input']];
            $gif = $image->get('format.format_name') === 'gif' && $image->video()?->get('codec_name') === 'gif';
            if ((! $gif && ! in_array($image->get('format.format_name'), ['png_pipe', 'jpeg_pipe'], true))
                || $image->streams()->count() !== 1) {
                throw MediaException::probing('Watermarks require a standalone PNG, JPEG, or GIF image.', 'watermark');
            }

            if (! $gif && $mark['loop']) {
                throw MediaException::probing('Only GIF watermarks can loop().', 'watermark');
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
            throw MediaException::probing($operation.' requires a zero presentation origin.', 'metadata');
        }

        if ($item->videoStreams()->contains(fn (Stream $stream): bool => ! is_numeric($stream->get('start_time'))
            || abs((float) $stream->get('start_time')) > 0.000001)) {
            throw MediaException::probing($operation.' requires zero origins on every video stream.', 'metadata');
        }
    }

    /**
     * Get the expected encoding duration, letting the builder measure it when it implements the hook.
     */
    protected function prepareEncoding(array $metadata): ?float
    {
        $duration = $this->defaultDuration($metadata);

        return $this->operation instanceof MeasuresDuration
            ? $this->operation->duration($metadata, $duration)
            : $duration;
    }

    /**
     * Get the expected encoding duration from the probed inputs, or null when it cannot be known.
     */
    protected function defaultDuration(array $metadata): ?float
    {
        if ($this->coverInput !== null) {
            return $metadata[0]->duration();
        }

        foreach ($this->configuredOutputs() as $output) {
            // Inserted silence extends the selected audio but not necessarily the video,
            // so we cannot know how long the output will be.
            if (array_any(
                $output->outputConfiguration()['audioFilters'],
                fn ($filter): int|false => preg_match('/(?:^|,)\s*adelay=/', $filter),
            )) {
                return null;
            }
        }

        if ($this->watermarks !== []) {
            return $metadata[0]->duration();
        }

        // A complex graph can change timing arbitrarily, so we will report no duration
        // rather than invent a progress percentage.
        return $this->filterGraph() === null && ! array_any($metadata, fn ($item): bool => $item->duration() === null)
            ? max(array_map(fn ($item) => $item->duration(), $metadata)) : null;
    }

    /**
     * Validate the given generated file and get its size in bytes.
     *
     * Packaging operations also call this for the files they inventory.
     *
     * @internal
     */
    public function validateArtifact(string $path, string $role = 'media'): int
    {
        clearstatcache(true, $path);

        if (! is_file($path) || ($size = filesize($path)) === false || $size === 0) {
            throw MediaException::validation('FFmpeg did not produce a nonempty media file.', 'artifact');
        }

        if ($role === 'hls-key' && $size !== 16) {
            throw MediaException::validation('An AES-128 HLS key must contain exactly 16 bytes.', 'artifact');
        }

        if ($this->operation instanceof ValidatesArtifacts) {
            $this->operation->artifact($path, $role, $size);
        }

        return $size;
    }

    /**
     * Ensure the FFmpeg build has the filters the resources need and every subtitle resource decodes.
     */
    protected function validateResources(Workspace $workspace, ProcessRunner $runner, array $resources): void
    {
        $required = [];

        foreach ($resources as $resource) {
            if ($resource['type'] !== 'file') {
                $required[] = $resource['type'] === 'text' ? 'drawtext' : 'subtitles';
            }
        }

        if ($required !== []) {
            $filters = $runner->run(
                [config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'), '-hide_banner', '-filters'],
                $workspace->path,
            );

            foreach (array_unique($required) as $filter) {
                if ($filters->exitCode !== 0
                    || ! preg_match('/^\s*\S+\s+'.preg_quote($filter, '/').'\s/m', $filters->output)) {
                    throw MediaException::probing('The FFmpeg build requires the '.$filter.' filter.', 'capability');
                }
            }
        }

        foreach ($resources as $resource) {
            if ($resource['type'] !== 'subtitle') {
                continue;
            }

            if (! mb_check_encoding(file_get_contents($workspace->path.'/'.$resource['path']), 'UTF-8')) {
                throw MediaException::probing(
                    'Subtitle resources require UTF-8 for '.$resource['context'].'.',
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
                    'A subtitle resource must be a standalone SRT, ASS, or WebVTT file for '.$resource['context'].'.',
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
                throw MediaException::probing('Cannot decode '.$resource['context'].'.', 'resource');
            }
        }
    }

    /**
     * Ensure no two outputs share a destination and no composition output overwrites an input.
     *
     * @internal
     */
    public function validateDestinations(array $outputs): void
    {
        $seen = [];

        foreach ($outputs as $output) {
            $disk = $output->filesystem();
            $path = $output->destination()['path'];
            $identity = (is_string($disk) ? 'disk:'.$disk : 'object:'.spl_object_id($disk)).':'.$path;

            if (isset($seen[$identity])) {
                throw new InvalidArgumentException('Outputs cannot share a destination.');
            }

            $seen[$identity] = true;

            if (count($outputs) > 1 || count($this->inputs) > 1) {
                foreach ($this->inputs as $input) {
                    if (new Media($disk, $path, $input->inputOptions())->identity() === $input->identity()) {
                        throw new InvalidArgumentException('Composition outputs cannot overwrite an input.');
                    }
                }
            }
        }
    }

    /**
     * Resolve the output's maps to FFmpeg map specifiers, matching probed streams to their inputs.
     */
    protected function resolveMaps(ExportOutput $output): array
    {
        if ($this->coverInput !== null) {
            return ['0:V?', '0:a?', $this->coverInput.':v:0'];
        }

        return array_map(function (string|array $map): string {
            if (is_string($map)) {
                return $map;
            }

            $source = $map['stream']->sourceIdentity();
            $input = $map['input'];

            if ($input === null) {
                $matches = array_keys(array_filter($this->inputs, fn ($media): bool => $media->identity() === $source));

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

            return $input.':'.$map['stream']->index().($map['optional'] ? '?' : '');
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
                $identity = $input.':'.$stream->get('index');

                if ($exclude) {
                    $selected = array_values(array_filter(
                        $selected,
                        fn (array $item): bool => $item['identity'] !== $identity,
                    ));
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

    /**
     * Cancel the execution when requested, then report the progress to the callback and listeners.
     */
    protected function notify(Progress $progress, string $operationId, array $outputs): void
    {
        if ($this->cancellation instanceof Closure && ($this->cancellation)()) {
            throw new ExportCancelled;
        }

        if ($this->progress instanceof Closure) {
            ($this->progress)($progress);
        }

        if (config('laravel-ffmpeg.enable_events', true)) {
            Event::dispatch(new Events\ExportProgress($operationId, $progress, $outputs));
        }
    }
}

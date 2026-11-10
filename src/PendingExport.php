<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Concerns\CompilesCommands;
use ProtoneMedia\LaravelFFMpeg\Concerns\CompilesOverlays;
use ProtoneMedia\LaravelFFMpeg\Concerns\PublishesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Concerns\StagesInputs;
use ProtoneMedia\LaravelFFMpeg\Concerns\VerifiesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\FilterGraph;
use ProtoneMedia\LaravelFFMpeg\Execution\ProcessRunner;
use ProtoneMedia\LaravelFFMpeg\Execution\ProgressParser;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\MeasuresDuration;
use ProtoneMedia\LaravelFFMpeg\Pipeline\RunsAnalysis;
use ProtoneMedia\LaravelFFMpeg\Pipeline\SplitsTwoPass;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ValidatesSelections;
use Throwable;

/**
 * Configure and run an FFmpeg export of one or more inputs.
 */
class PendingExport implements Exportable
{
    use CompilesCommands, CompilesOverlays, PublishesArtifacts, StagesInputs, VerifiesArtifacts;
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
     * The workspace of the running export.
     */
    protected ?Workspace $workspace = null;

    /**
     * The paths the running export hides from its commands and diagnostics, keyed by path.
     */
    protected array $redactions = [];

    /**
     * The callback that reports the progress of the running export.
     */
    protected ?Closure $reportProgress = null;

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
        DateTimeInterface|DateInterval|int|null $delay = null,
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
    public function timeout(?float $seconds): static
    {
        $this->assertMutable();
        $this->ensureTimeoutIsPositive('operation', $seconds);

        $this->timeoutSeconds = $seconds;

        return $this;
    }

    /**
     * Set how many seconds a process may run without output.
     */
    public function idleTimeout(?float $seconds): static
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
    public function cancelWhen(callable $callback): static
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
    public function resource(string $name, Media $media): static
    {
        return $this->addResource($name, 'file', $media);
    }

    /**
     * Declare a UTF-8 text file that video filters reference by the given name.
     */
    public function textResource(string $name, string $text): static
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
    public function subtitleResource(string $name, Media $media): static
    {
        if ($media->inputOptions() !== []) {
            throw new InvalidArgumentException("The subtitle resource [{$name}] does not accept input options.");
        }

        return $this->addResource($name, 'subtitle', $media);
    }

    /**
     * Declare a named resource of the given type.
     */
    protected function addResource(string $name, string $type, Media|string $source): static
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
    public function filterComplex(string $expression): static
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
    public function watermark(Media $image, ?callable $configure = null): static
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
        $this->watermarks[] = ['input' => array_key_last($this->inputs)] + Arr::except($settings, 'edits');

        return $this;
    }

    /**
     * Attach a standalone PNG or JPEG image as cover art.
     *
     * The motion video and audio are copied unchanged.
     */
    public function coverArt(Media $image): static
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
    public function output(string $path, callable $configure): static
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
    public function globalOptions(array $tokens): static
    {
        $this->assertMutable();
        $this->globalOptions = CommandCompiler::options([...$this->globalOptions, ...$tokens], 'global');

        return $this;
    }

    /**
     * Register a callback that receives the progress of the execution.
     */
    public function onProgress(callable $callback): static
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
            collect($this->inputs)->keys()->map(fn (int|string $i): string => "[input:{$i}]")->all(),
        );
    }

    /**
     * Encode the video in two passes.
     */
    public function twoPass(): static
    {
        $this->assertMutable();

        if (! in_array($this->outputRole, ['media', 'hls-playlist'], true)) {
            throw new InvalidArgumentException('Two-pass encoding requires video media outputs, not images.');
        }

        $this->twoPass = true;

        return $this;
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
        $this->reportProgress = fn (Progress $progress) => $this->notify($progress, $operationId, $destinations);

        try {
            $this->runner = new ProcessRunner($this->timeoutSeconds, $this->idleTimeoutSeconds);

            if (config('laravel-ffmpeg.enable_events', true)) {
                Event::dispatch(new Events\ExportStarting($operationId, $destinations));
            }

            ($this->reportProgress)(new Progress($this->phase));
            $result = $this->media->withInput(
                fn (string $input, Workspace $workspace): string|ExportResult => $this->exportInWorkspace(
                    $workspace,
                    $outputs,
                    $contents,
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
            $this->workspace = null;
            $this->redactions = [];
            $this->reportProgress = null;
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
        $this->command(
            $outputs,
            collect($this->inputs)->keys()->map(fn (int|string $i): string => "[input:{$i}]")->all(),
        );
        $this->validateDestinations($outputs);

        foreach ($outputs as $output) {
            $output->freeze();
        }

        return $outputs;
    }

    /**
     * Stage, probe, encode, validate, and publish the export inside the workspace.
     */
    protected function exportInWorkspace(Workspace $workspace, array $outputs, bool $contents): string|ExportResult
    {
        $this->workspace = $workspace;
        [$paths, $this->redactions] = $this->stageInputs($workspace);
        $resources = $this->resourceNames($outputs);
        $this->stageResources($workspace, $resources);

        $this->phase = Progress::PROBING;
        ($this->reportProgress)(new Progress($this->phase));
        $metadata = $this->probeInputs($workspace, $resources);
        $expected = $this->expectedStreams($workspace, $outputs, $metadata);
        $outputPaths = $this->outputPaths($outputs, $workspace);

        foreach ($outputPaths as $index => $outputPath) {
            $this->redactions[$outputPath] = "[output:{$index}]";
        }

        $this->prepareOverlays($metadata);
        $duration = $this->progressDuration($outputs, $this->prepareEncoding($metadata));
        $this->phase = Progress::ENCODING;
        $command = $this->command($outputs, $paths, $outputPaths);
        $this->summaries = [];

        // Next, operations may run analysis passes that replace command tokens. Each
        // analysis reports its own phase and records its summary before the encoding
        // passes, so we return to the encoding phase once the command is prepared.
        $command = $this->prepareCommand($workspace, $command, $outputPaths, $this->runAnalysis(...));
        $this->phase = Progress::ENCODING;
        $this->runEncodingPasses($command, $outputs, $outputPaths, $duration);

        $this->phase = Progress::VALIDATION;
        ($this->reportProgress)(new Progress($this->phase));
        $artifacts = $this->artifacts($outputs, $outputPaths);
        $sizes = $this->validateArtifacts($workspace, $artifacts, $outputs, $expected);

        if ($contents) {
            return $this->readGeneratedFile($artifacts[0]['path']);
        }

        $this->phase = Progress::PUBLICATION;
        ($this->reportProgress)(new Progress($this->phase));
        $files = $this->publishArtifacts($workspace->runner, $artifacts, $outputs, $sizes);
        $workspace->runner->check(Progress::PUBLICATION);

        return new ExportResult($files, $this->summaries);
    }

    /**
     * Determine the longest expected output duration for encoding progress.
     */
    protected function progressDuration(array $outputs, ?float $duration): ?float
    {
        if ($duration === null) {
            return null;
        }

        $durations = array_map(function ($output) use ($duration) {
            $clip = $output->clipSettings();

            return $clip === null ? $duration : max(0, min($clip['duration'], $duration - $clip['start']));
        }, $outputs);

        return max($durations);
    }

    /**
     * Run one analysis pass, recording its summary ahead of the encoding passes.
     */
    protected function runAnalysis(array $command, string $failure): ProcessResult
    {
        $this->phase = Progress::ANALYSIS;
        ($this->reportProgress)(new Progress($this->phase));
        $process = $this->workspace->runner->run(
            $command,
            $this->workspace->path,
            $this->redactions,
            phase: Progress::ANALYSIS,
            progress: new ProgressParser(null, Progress::ANALYSIS, notify: $this->reportProgress),
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
    protected function runEncodingPasses(array $command, array $outputs, array $outputPaths, ?float $duration): void
    {
        $totalPasses = $this->twoPass ? 2 : 1;

        for ($pass = 1; $pass <= $totalPasses; $pass++) {
            ($this->reportProgress)(new Progress($this->phase, pass: $pass, totalPasses: $totalPasses));
            $passCommand = $command;

            if ($totalPasses === 2 && $pass === 1) {
                $passCommand = $this->firstPassCommand($command, $outputPaths, $this->workspace, $this->redactions);
            }

            $process = $this->workspace->runner->run(
                $passCommand,
                $this->workspace->path,
                $this->redactions,
                phase: $this->phase,
                progress: new ProgressParser($duration, $this->phase, $pass, $totalPasses, $this->reportProgress),
            );

            if ($process->exitCode !== 0) {
                $category = Str::contains($process->diagnostics, [
                    'Streamcopy requested for output stream fed from a complex filtergraph',
                    'Simple and complex filtering cannot be used together',
                ]) ? 'configuration' : 'exit';

                throw new MediaException(
                    "FFmpeg failed (exit {$process->exitCode}).",
                    $this->phase,
                    $category,
                    $process->diagnostics,
                );
            }

            $this->summaries[] = $process->summary();

            if ($totalPasses === 2 && $pass === 1) {
                $this->ensurePassStatisticsExist($this->workspace, $outputs);
            }
        }
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
        if ($this->filterGraph() !== null || array_any($metadata, fn ($item): bool => $item->duration() === null)) {
            return null;
        }

        return max(array_map(fn ($item) => $item->duration(), $metadata));
    }
}

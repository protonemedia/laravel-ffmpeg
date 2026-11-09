<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\FilterGraph;
use ProtoneMedia\LaravelFFMpeg\Execution\ProcessRunner;
use ProtoneMedia\LaravelFFMpeg\Execution\ProgressParser;
use ProtoneMedia\LaravelFFMpeg\Execution\ResourceFilter;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use Throwable;

class PendingExport
{
    use Conditionable;
    use ConfiguresOutput;

    private array $inputs;

    private array $outputs = [];

    private array $resources = [];

    private array $globalOptions = [];

    private ?string $graph = null;

    private array $watermarks = [];

    private ?float $watermarkDuration = null;

    private ?int $coverInput = null;

    private ?int $coverVideoTrack = null;

    private ?Closure $progress = null;

    private ?Closure $cancellation = null;

    private bool $rootConfigured = false;

    private bool $running = false;

    private bool $twoPass = false;

    private ?float $timeoutSeconds = null;

    private ?float $idleTimeoutSeconds = null;

    protected ?ProcessRunner $runner = null;

    protected string $outputRole = 'media';

    public function __construct(private readonly Media $media, string|Filesystem $disk)
    {
        $this->inputs = [$media];
        $this->disk = $disk;
    }

    public function queued(
        ?string $connection = null,
        ?string $queue = null,
        \DateTimeInterface|\DateInterval|int|null $delay = null,
        bool $parallel = false,
    ): PendingQueuedExport {
        $this->assertMutable();

        return new PendingQueuedExport($this, $connection, $queue, $delay, $parallel, $this->timeoutSeconds);
    }

    /** A cumulative budget for this execution; null restores the configured process limits. */
    public function timeout(?float $seconds): self
    {
        $this->assertMutable();
        if ($seconds !== null && (! is_finite($seconds) || $seconds <= 0)) {
            throw new InvalidArgumentException('An operation timeout must be finite, positive seconds or null.');
        }
        $this->timeoutSeconds = $seconds;

        return $this;
    }

    public function idleTimeout(?float $seconds): self
    {
        $this->assertMutable();
        if ($seconds !== null && (! is_finite($seconds) || $seconds <= 0)) {
            throw new InvalidArgumentException('An idle timeout must be finite, positive seconds or null.');
        }
        $this->idleTimeoutSeconds = $seconds;

        return $this;
    }

    /** @internal Worker batch cancellation is independent of user progress callbacks. */
    public function cancelWhen(callable $callback): self
    {
        $this->cancellation = Closure::fromCallable($callback);

        return $this;
    }

    protected function assertOutputMutable(): void
    {
        $this->assertMutable();

        if ($this->outputs !== []) {
            throw new InvalidArgumentException('Configure each output in its output() callback, not on the export root.');
        }

        $this->rootConfigured = true;
    }

    private function assertMutable(): void
    {
        if ($this->running) {
            throw new InvalidArgumentException('An executing export cannot be changed.');
        }
    }

    /** Returns the stable FFmpeg input index; staging reuse never removes an input. */
    public function addInput(Media $media): int
    {
        $this->assertMutable();
        $this->inputs[] = $media;

        if (! $this->outputDiskExplicit) {
            $this->disk = config('filesystems.default');
        }

        return array_key_last($this->inputs);
    }

    public function resource(string $name, Media $media): self
    {
        return $this->addResource($name, 'file', $media);
    }

    public function textResource(string $name, string $text): self
    {
        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            throw new InvalidArgumentException('Text resources require UTF-8 without NUL bytes.');
        }

        return $this->addResource($name, 'text', $text);
    }

    public function subtitleResource(string $name, Media $media): self
    {
        if ($media->inputOptions() !== []) {
            throw new InvalidArgumentException('Subtitle resources do not accept media input options.');
        }

        return $this->addResource($name, 'subtitle', $media);
    }

    private function addResource(string $name, string $type, Media|string $source): self
    {
        $this->assertMutable();

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $name) || isset($this->resources[$name])) {
            throw new InvalidArgumentException('Resource names must be unique simple identifiers.');
        }

        $this->resources[$name] = compact('type', 'source');

        return $this;
    }

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

    /** Overlay a PNG/JPEG or animated GIF after video filters. Offsets are inward margins. */
    public function watermark(Media $image, string $position = 'bottom-right', int $x = 0, int $y = 0, ?int $width = null, ?int $height = null, float $opacity = 1): self
    {
        $this->assertMutable();

        if (! in_array($position, ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'center'], true)
            || $x < 0 || $y < 0 || ($width !== null && $width <= 0) || ($height !== null && $height <= 0)
            || ! is_finite($opacity) || $opacity < 0 || $opacity > 1
            || ! in_array($image->inputOptions(), [[], ['-ignore_loop', '0'], ['-ignore_loop', '1']], true)) {
            throw new InvalidArgumentException('Watermarks require an anchor, nonnegative margins, positive dimensions, opacity between zero and one, and no input options except GIF ignore_loop.');
        }

        $this->inputs[] = $image;
        $input = array_key_last($this->inputs);
        $this->watermarks[] = compact('input', 'position', 'x', 'y', 'width', 'height', 'opacity');

        return $this;
    }

    /** Attach one standalone PNG/JPEG, copying all motion video and audio unchanged. */
    public function coverArt(Media $image): self
    {
        $this->assertMutable();
        if ($this->coverInput !== null || $image->inputOptions() !== []) {
            throw new InvalidArgumentException('Cover art requires one image without input options.');
        }
        $this->inputs[] = $image;
        $this->coverInput = array_key_last($this->inputs);

        return $this;
    }

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

    public function globalOptions(array $tokens): self
    {
        $this->assertMutable();
        $this->globalOptions = CommandCompiler::options([...$this->globalOptions, ...$tokens], 'global');

        return $this;
    }

    public function onProgress(callable $callback): self
    {
        $this->assertMutable();
        $this->progress = Closure::fromCallable($callback);

        return $this;
    }

    /** Pure, redacted inspection. No files or processes are created. */
    public function inspect(): array
    {
        return $this->command($this->configuredOutputs(), array_map(fn ($i) => '[input:'.$i.']', array_keys($this->inputs)));
    }

    public function twoPass(): self
    {
        $this->assertMutable();

        if (! in_array($this->outputRole, ['media', 'hls-playlist'], true)) {
            throw new InvalidArgumentException('Two-pass encoding requires video media outputs, not images.');
        }

        $this->twoPass = true;

        return $this;
    }

    protected function configuredOutputs(?string $path = null): array
    {
        if ($this->outputs !== []) {
            if ($path !== null) {
                throw new InvalidArgumentException('Use save() without a path after output().');
            }

            return $this->outputs;
        }

        return [(new ExportOutput($path ?? 'inspection.mp4', $this->disk))->configure($this->outputConfiguration())];
    }

    private function resourceNames(array $outputs): array
    {
        $declared = $this->resources;

        foreach ($declared as $name => $resource) {
            $declared[$name]['context'] = 'filter resource '.$name;
        }

        foreach ($outputs as $index => $output) {
            foreach ($output->resourceFilters() as $filter) {
                foreach ($filter->resources as $role => $resource) {
                    $declared['helper:'.spl_object_id($filter).':'.$role] = $resource + ['context' => 'output '.$index.' '.$filter->name];
                }
            }
        }

        $names = $paths = [];

        foreach ($declared as $name => $resource) {
            $extension = $resource['type'] === 'text' ? 'txt' : $resource['source']->extension();
            $identity = $resource['type'].':'.($resource['source'] instanceof Media ? $resource['source']->identity() : $name);
            $paths[$identity] ??= 'resource-'.count($paths).($extension === '' ? '' : '.'.$extension);
            $names[$name] = $resource + ['path' => $paths[$identity]];
        }

        return $names;
    }

    protected function filterGraph(): ?string
    {
        return $this->graph;
    }

    protected function command(array $outputs, array $paths, ?array $outputPaths = null): array
    {
        $graph = $this->filterGraph();
        $watermarking = $this->watermarks !== [];
        $covering = $this->coverInput !== null;
        if ($covering && ($graph !== null || $watermarking || count($this->inputs) !== 2
            || count($outputs) !== 1 || $this->outputRole !== 'media' || $this->twoPass)) {
            throw new InvalidArgumentException('Cover art requires one media output, one source, and no graph, watermark, or two-pass encoding.');
        }
        if ($watermarking && ($graph !== null || count($this->inputs) !== count($this->watermarks) + 1
            || ! in_array($this->outputRole, ['media', 'hls-playlist'], true))) {
            throw new InvalidArgumentException('Watermarks require a media export and own their image inputs and graph; use filterComplex() for custom compositions.');
        }
        $composition = ! $covering && ! $watermarking && (count($this->inputs) > 1 || $graph !== null);
        $mappedLabels = $used = $compiledOutputs = [];
        $resources = $this->resourceNames($outputs);

        if (array_any($resources, fn ($resource) => $resource['type'] === 'subtitle')
            && array_any($outputs, fn ($output) => $output->inputSeekOptions() !== [])) {
            throw new InvalidArgumentException('Subtitle resources require an unseeked input. Use an accurate output clip.');
        }

        foreach ($outputs as $index => $output) {
            if ($this->twoPass && $output->outputConfiguration()['videoStreams'] !== []) {
                throw new InvalidArgumentException('Per-track video settings do not support two-pass encoding.');
            }
            if ($covering) {
                $settings = $output->outputConfiguration();
                if (! in_array(strtolower(pathinfo($output->destination()['path'], PATHINFO_EXTENSION)), ['mp4', 'm4a', 'mp3'], true)
                    || $output->hasMaps() || $settings['omitVideo'] || $settings['omitAudio']
                    || $settings['videoFilters'] !== [] || $settings['audioFilters'] !== [] || $settings['videoStreams'] !== []
                    || $settings['audioStreams'] !== []
                    || $settings['outputOptions'] !== [] || $settings['dispositions'] !== [] || $settings['clip'] !== null
                    || ! in_array($settings['video'], [[], ['codec' => 'copy']], true)
                    || ! in_array($settings['audio'], [[], ['codec' => 'copy']], true)) {
                    throw new InvalidArgumentException('Cover art supports MP4, M4A, or MP3 with unchanged audio/video, automatic maps, and no encoding, clipping, or disposition overrides.');
                }
                $settings['video'] = $settings['audio'] = ['codec' => 'copy'];
                $settings['metadataSource'] ??= 0;
                $settings['chapterSource'] ??= 0;
                $output = (new ExportOutput($output->destination()['path'], $output->filesystem()))->configure($settings);
            }
            if ($watermarking) {
                $settings = $output->outputConfiguration();
                if ($output->hasMaps() || $settings['omitVideo'] || ($settings['video']['codec'] ?? null) === 'copy') {
                    throw new InvalidArgumentException('Watermarks require automatically selected, transcoded video.');
                }
                $settings['metadataSource'] ??= 0;
                $settings['chapterSource'] ??= $settings['clip'] === null ? 0 : -1;
                $output = (new ExportOutput($output->destination()['path'], $output->filesystem()))->configure($settings);
            }
            if ($composition && ! $output->hasMaps()) {
                throw new InvalidArgumentException('Every composition output requires explicit maps.');
            }

            if (count($this->inputs) > 1 && ($output->metadataSource() === null || $output->chapterSource() === null)) {
                throw new InvalidArgumentException('Multiple inputs require explicit metadata and chapter sources, or omission.');
            }

            foreach ([$output->metadataSource(), $output->chapterSource()] as $source) {
                if ($source !== null && $source >= count($this->inputs)) {
                    throw new InvalidArgumentException('Metadata or chapters reference an invalid input.');
                }
            }

            if ($output->inputSeekOptions() !== [] && (count($this->inputs) > 1 || count($outputs) > 1 || $graph !== null)) {
                throw new InvalidArgumentException('Keyframe clips require one input and one output without a complex graph.');
            }

            $maps = $this->resolveMaps($output);

            foreach ($maps as $map) {
                if (str_starts_with($map, '[')) {
                    if ($graph === null) {
                        throw new InvalidArgumentException('A graph map requires filterComplex().');
                    }

                    $mappedLabels[] = substr($map, 1, -1);
                } elseif ((int) ltrim($map, '-') >= count($this->inputs)) {
                    throw new InvalidArgumentException('Map references an invalid input.');
                }
            }

            $options = $output->outputArguments(function (string|ResourceFilter $filter) use ($resources, &$used) {
                return $filter instanceof ResourceFilter
                    ? $filter->toFilter(fn ($role) => $resources['helper:'.spl_object_id($filter).':'.$role]['path'])
                    : FilterGraph::resources($filter, $resources, $used);
            }, $maps);

            if ($covering) {
                // Like input paths, this output index is unresolved in pure inspection.
                array_push($options, '-disposition:v:'.($this->coverVideoTrack ?? '[cover-video-track]'), 'attached_pic');
            }

            if ($watermarking) {
                $filter = 'null';
                $processed = [];
                for ($i = 0; $i < count($options); $i++) {
                    // A per-track chain for the one mapped video stream already
                    // includes the output filters, and comes after them.
                    if ($options[$i] === '-filter:v' || $options[$i] === '-filter:v:0') {
                        $filter = $options[++$i];
                    } elseif ($options[$i] === '-map' && str_starts_with($options[$i + 1], '0:V:0')) {
                        array_push($processed, '-map', '[watermark_out'.$index.']');
                        $i++;
                    } else {
                        $processed[] = $options[$i];
                    }
                }
                $options = $processed;
                $branches = ['[0:V:0]'.$filter.'[watermark_base'.$index.'_0]'];
                foreach ($this->watermarks as $n => $mark) {
                    ['input' => $input, 'position' => $position, 'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height, 'opacity' => $opacity] = $mark;
                    $imageFilters = $width === null && $height === null ? '' : 'scale=w='.($width ?? -1).':h='.($height ?? -1).',';
                    $label = 'watermark_image'.$index.'_'.$n;
                    $branches[] = '['.$input.':v:0]'.$imageFilters.'format=rgba,colorchannelmixer=aa='.$opacity.'['.$label.']';
                    $horizontal = str_ends_with($position, 'right') ? 'main_w-overlay_w-'.$x : ($position === 'center' ? '(main_w-overlay_w)/2+'.$x : (string) $x);
                    $vertical = str_starts_with($position, 'bottom') ? 'main_h-overlay_h-'.$y : ($position === 'center' ? '(main_h-overlay_h)/2+'.$y : (string) $y);
                    $out = $n === count($this->watermarks) - 1 ? 'watermark_out'.$index : 'watermark_base'.$index.'_'.($n + 1);
                    $branches[] = '[watermark_base'.$index.'_'.$n.']['.$label.']overlay=x='.$horizontal.':y='.$vertical.':eof_action=repeat:repeatlast=1:shortest=0['.$out.']';
                }
                $graph = ($graph === null ? '' : $graph.';').implode(';', $branches);
                $mappedLabels[] = 'watermark_out'.$index;
                if (array_any($this->watermarks, fn ($mark) => $this->inputs[$mark['input']]->inputOptions() === ['-ignore_loop', '0'])) {
                    $clip = $output->clipSettings();
                    $duration = $this->watermarkDuration === null ? null : max(0, $this->watermarkDuration - ($clip['start'] ?? 0));
                    if ($duration !== null && $clip !== null) {
                        $duration = min($duration, $clip['duration']);
                    }
                    array_push($options, '-t', $duration === null ? '[source-duration]' : $this->clipTimestamp($duration));
                }
            }

            if ($this->twoPass && in_array($index, $this->twoPassOutputIndexes($outputs), true)) {
                $settings = $output->outputConfiguration();
                $video = $settings['video'];

                if ($settings['omitVideo'] || ! isset($video['codec'], $video['bitrate'])
                    || $video['codec'] === 'copy' || isset($video['crf'])) {
                    throw new InvalidArgumentException('Every two-pass output requires an explicit video encoder and target bitrate, without CRF.');
                }

                array_push($options, '-pass:v', '2', '-passlogfile:v', 'pass-'.$index);
            }

            $compiledOutputs[] = ['path' => $outputPaths[$index] ?? '[output:'.$index.']', 'options' => $options];
        }

        $globals = $this->globalOptions;

        if ($graph !== null) {
            FilterGraph::validate($graph, count($this->inputs), $mappedLabels);
            array_push($globals, '-filter_complex', FilterGraph::resources($graph, $resources, $used));
        }

        if (count($used) !== count($this->resources)) {
            throw new InvalidArgumentException('Every declared resource must be used by a filter.');
        }

        $compiledInputs = [];

        foreach ($this->inputs as $index => $input) {
            $compiledInputs[] = [
                'path' => $paths[$index], 'protocols' => $input->protocolWhitelist(),
                'options' => [...$input->transportOptions(), ...$input->inputOptions(), ...($index === 0 ? $outputs[0]->inputSeekOptions() : [])],
            ];
        }

        return CommandCompiler::compile(config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'), $compiledInputs, $compiledOutputs, $globals);
    }

    public function save(?string $path = null): ExportResult
    {
        return $this->execute($path);
    }

    protected function execute(?string $path, bool $contents = false): ExportResult|string
    {
        $this->assertMutable();

        if ($path === null && $this->outputs === []) {
            throw new InvalidArgumentException('Declare outputs or pass a destination to save().');
        }

        $outputs = $this->configuredOutputs($path);
        $this->command($outputs, array_map(fn ($i) => '[input:'.$i.']', array_keys($this->inputs)));
        $this->validateDestinations($outputs);

        foreach ($outputs as $output) {
            $output->freeze();
        }

        $this->running = true;
        $operationId = bin2hex(random_bytes(16));
        $destinations = $contents ? [] : array_map(fn ($output) => $output->destination(), $outputs);
        $published = [];
        $phase = Progress::STAGING;
        $onProgress = fn (Progress $progress) => $this->notify($progress, $operationId, $destinations);

        try {
            $this->runner = new ProcessRunner($this->timeoutSeconds, $this->idleTimeoutSeconds);
            if (config('laravel-ffmpeg.enable_events', true)) {
                Event::dispatch(new Events\ExportStarting($operationId, $destinations));
            }
            $onProgress(new Progress($phase));
            $result = $this->media->withInput(function (string $input, Workspace $workspace) use ($outputs, $contents, $onProgress, &$phase, &$published) {
                $paths = array_map(fn ($media) => $workspace->stage($media), $this->inputs);
                $redactions = [];

                foreach ($paths as $index => $path) {
                    $redactions[$path] ??= '[input:'.$index.']';
                    $redactions += $this->inputs[$index]->redactions();
                }

                $resources = $this->resourceNames($outputs);
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
                        throw new MediaException('Cannot stage '.$resource['context'].'.', 'staging', $exception->category);
                    }

                    if (! $written || ! chmod($target, 0600)) {
                        throw new MediaException('Cannot stage '.$resource['context'].'.', 'staging', 'transfer');
                    }

                    $staged[$target] = true;
                }

                $runner = $workspace->runner;
                $phase = Progress::PROBING;
                $onProgress(new Progress($phase));
                $ffmpeg = $runner->version(config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'), 'ffmpeg', $workspace->path);
                $ffprobe = $runner->version(config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'), 'ffprobe', $workspace->path);

                if ($ffmpeg !== $ffprobe) {
                    throw new MediaException('FFmpeg and FFprobe must have matching release versions.', 'probing', 'version');
                }

                foreach ($this->inputs as $media) {
                    $workspace->requireProtocols(config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'), $media->protocolWhitelist());
                }

                $this->validateResources($workspace, $runner, $resources);
                $metadata = array_map(fn ($media) => $workspace->probe($media), $this->inputs);
                $expected = array_map(fn ($output) => $this->selectedStreams($output, $workspace, $metadata), $outputs);
                foreach ($outputs as $index => $output) {
                    foreach (['video' => 'videoStreams', 'audio' => 'audioStreams'] as $type => $key) {
                        $tracks = $output->outputConfiguration()[$key];
                        if ($tracks !== [] && ! in_array(null, $expected[$index], true)
                            && max(array_keys($tracks)) >= count(array_filter($expected[$index], fn ($selected) => $selected === $type))) {
                            throw new MediaException(ucfirst($type).' track settings require existing output '.$type.' tracks.', 'probing', 'mapping');
                        }
                    }
                }
                $this->validateStreamSelections($expected);

                if ($this->twoPass && array_any($this->twoPassOutputIndexes($outputs), fn ($index) => ! in_array('video', $expected[$index], true) && ! in_array(null, $expected[$index], true))) {
                    throw new MediaException('Every two-pass output must select video.', $phase, 'mapping');
                }

                $outputPaths = $this->outputPaths($outputs, $workspace);

                foreach ($outputPaths as $index => $outputPath) {
                    $redactions[$outputPath] = '[output:'.$index.']';
                }

                // Overlay facts never depend on subclasses calling parent::prepareEncoding().
                $this->prepareOverlays($metadata);
                $duration = $this->prepareEncoding($metadata);
                $durations = array_map(function ($output) use ($duration) {
                    $clip = $output->clipSettings();

                    return $duration === null ? null : ($clip === null ? $duration : max(0, min($clip['duration'], $duration - $clip['start'])));
                }, $outputs);
                $phase = Progress::ENCODING;
                $command = $this->command($outputs, $paths, $outputPaths);
                $summaries = [];
                $analyze = function (array $command, string $failure) use ($runner, $workspace, $redactions, $onProgress, &$summaries, &$phase): ProcessResult {
                    $phase = Progress::ANALYSIS;
                    $onProgress(new Progress($phase));
                    $process = $runner->run($command, $workspace->path, $redactions, phase: Progress::ANALYSIS, progress: new ProgressParser(null, Progress::ANALYSIS, notify: $onProgress));

                    if ($process->exitCode !== 0) {
                        throw new MediaException($failure, Progress::ANALYSIS, 'exit', $process->diagnostics);
                    }

                    $summaries[] = $process->summary();

                    return $process;
                };
                $command = $this->prepareCommand($workspace, $command, $outputPaths, $analyze);
                $phase = Progress::ENCODING;
                $totalPasses = $this->twoPass ? 2 : 1;

                for ($pass = 1; $pass <= $totalPasses; $pass++) {
                    $onProgress(new Progress($phase, pass: $pass, totalPasses: $totalPasses));
                    $passCommand = $command;

                    if ($totalPasses === 2 && $pass === 1) {
                        $passCommand = $this->firstPassCommand($command, $outputPaths, $workspace, $redactions);
                    }

                    $process = $runner->run(
                        $passCommand, $workspace->path, $redactions,
                        phase: $phase, progress: new ProgressParser($duration === null ? null : max($durations), $phase, $pass, $totalPasses, $onProgress),
                    );

                    if ($process->exitCode !== 0) {
                        $category = str_contains($process->diagnostics, 'Streamcopy requested for output stream fed from a complex filtergraph')
                            || str_contains($process->diagnostics, 'Simple and complex filtering cannot be used together')
                            ? 'configuration' : 'exit';

                        throw new MediaException('FFmpeg failed (exit '.$process->exitCode.').', $phase, $category, $process->diagnostics);
                    }

                    $summaries[] = $process->summary();

                    if ($totalPasses === 2 && $pass === 1) {
                        foreach ($this->twoPassOutputIndexes($outputs) as $index) {
                            $logs = glob($workspace->path.'/pass-'.$index.'-*.log') ?: [];

                            if (! array_any($logs, fn ($log) => is_file($log) && filesize($log) > 0)) {
                                throw new MediaException('The first encoding pass did not produce statistics for output '.$index.'.', $phase, 'artifact');
                            }
                        }
                    }
                }

                $phase = Progress::VALIDATION;
                $onProgress(new Progress($phase));
                $artifacts = $this->artifacts($outputs, $outputPaths);
                $sizes = [];

                foreach ($artifacts as $artifact) {
                    $runner->check(Progress::VALIDATION);
                    $outputPath = $artifact['path'];
                    $index = $artifact['output'];
                    $role = $artifact['role'] ?? $this->outputRole;
                    $size = $this->validateArtifact($outputPath, $role);
                    $settings = $outputs[$index]->outputConfiguration();
                    $configuredTracks = $settings['videoStreams'] !== [] || $settings['audioStreams'] !== [];

                    if (($artifact['probe'] ?? false)
                        || (in_array($role, ['media', 'image'], true) && ($configuredTracks || count($outputs) > 1 || count($this->inputs) > 1 || $this->graph !== null))) {
                        try {
                            $actual = $this->media->probeArtifact($artifact['probePath'] ?? $outputPath, $workspace)->streams()->map(fn ($stream) => $stream->get('codec_type'))->all();
                        } catch (MediaException $exception) {
                            if ($exception->category === 'timeout') {
                                throw $exception;
                            }
                            throw new MediaException('Cannot validate the encoded media.', $phase, 'artifact', $exception->diagnostics);
                        }

                        if (count($actual) !== count($expected[$index]) || array_any(array_keys($actual), fn ($i) => $expected[$index][$i] !== null && $actual[$i] !== $expected[$index][$i])) {
                            throw new MediaException('Encoded streams do not match the explicit output maps.', $phase, 'mapping');
                        }
                        foreach (['video' => 'videoStreams', 'audio' => 'audioStreams'] as $type => $key) {
                            if ($settings[$key] !== [] && max(array_keys($settings[$key])) >= count(array_filter($actual, fn ($selected) => $selected === $type))) {
                                throw new MediaException(ucfirst($type).' track settings require existing encoded output '.$type.' tracks.', $phase, 'mapping');
                            }
                        }
                    }

                    $sizes[] = $size;
                }

                if ($contents) {
                    $bytes = @file_get_contents($artifacts[0]['path']);

                    if ($bytes === false) {
                        throw new MediaException('Cannot read the generated image.', $phase, 'artifact');
                    }

                    return $bytes;
                }

                $phase = Progress::PUBLICATION;
                $onProgress(new Progress($phase));
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

                        $published[] = $destination;

                        if ($disk->size($destination['path']) !== $sizes[$index]) {
                            throw new \RuntimeException('Published size mismatch.');
                        }
                    } catch (Throwable) {
                        throw new MediaException('Cannot publish the media output.', $phase, 'transfer', published: $published, destination: $destination);
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }

                    $files[] = new GeneratedFile($disk, $destination['disk'], $destination['path'], $sizes[$index], role: $artifact['role'] ?? $this->outputRole);
                }

                $runner->check(Progress::PUBLICATION);

                return new ExportResult($files, $summaries);
            }, $this->runner);
        } catch (Throwable $exception) {
            if ($exception instanceof MediaException && $exception->category === 'timeout' && $published !== []) {
                $exception = new MediaException($exception->getMessage(), $exception->phase, $exception->category,
                    $exception->diagnostics, published: $published);
            }
            $event = $exception instanceof ExportCancelled ? Events\ExportCancelled::class : Events\ExportFailed::class;

            try {
                if (config('laravel-ffmpeg.enable_events', true)) {
                    Event::dispatch(new $event($operationId, $exception instanceof MediaException ? $exception->phase : $phase, $destinations, $published));
                }
            } catch (Throwable) {
                // Preserve the primary process or Storage failure.
            }

            throw $exception;
        } finally {
            $this->running = false;
            $this->runner = null;
        }

        if (config('laravel-ffmpeg.enable_events', true)) {
            Event::dispatch(new Events\ExportCompleted($operationId, $result instanceof ExportResult ? $result->files()->map->toArray()->all() : []));
        }

        return $result;
    }

    /**
     * @internal Execution-only analyses may replace compiled command tokens.
     *
     * @param  Closure(list<string>, string): ProcessResult  $analyze  Runs one analysis pass with progress, records
     *                                                               its summary before the encoding passes, and throws the given message on failure.
     */
    protected function prepareCommand(Workspace $workspace, array $command, array $outputPaths, Closure $analyze): array
    {
        return $command;
    }

    /** @internal Validate resolved stream types before starting any encoder. */
    protected function validateStreamSelections(array $expected): void {}

    /** @internal Outputs requiring encoder statistics; packaging-only outputs do not. */
    protected function twoPassOutputIndexes(array $outputs): array
    {
        return array_keys($outputs);
    }

    /** @internal Keep analysis inside the private workspace, never at publication paths. */
    protected function firstPassCommand(array $command, array $outputPaths, Workspace $workspace, array &$redactions): array
    {
        $result = [];
        foreach ($command as $index => $token) {
            $output = array_search($token, $outputPaths, true);
            if ($output !== false) {
                $target = $workspace->path.'/analysis-'.$output.'.null';
                $redactions[$target] = '[analysis:'.$output.']';
                array_push($result, '-f', 'null', $target);
            } else {
                $result[] = ($command[$index - 1] ?? null) === '-pass:v' ? '1' : $token;
            }
        }

        return $result;
    }

    protected function outputPaths(array $outputs, Workspace $workspace): array
    {
        return array_map(function ($index) use ($outputs, $workspace) {
            $extension = pathinfo($outputs[$index]->destination()['path'], PATHINFO_EXTENSION);

            return $workspace->path.'/output-'.$index.($extension === '' ? '' : '.'.$extension);
        }, array_keys($outputs));
    }

    protected function artifacts(array $outputs, array $paths): array
    {
        return array_map(fn ($index) => [
            'path' => $paths[$index], 'output' => $index, 'destination' => $outputs[$index]->destination(),
        ], array_keys($outputs));
    }

    /** Validate cover-art and watermark inputs and resolve the facts command() needs for them. */
    private function prepareOverlays(array $metadata): void
    {
        if ($this->coverInput !== null) {
            $image = $metadata[$this->coverInput];
            if (! in_array($image->get('format.format_name'), ['png_pipe', 'jpeg_pipe'], true)
                || $image->streams()->count() !== 1) {
                throw new MediaException('Cover art requires a standalone PNG or JPEG image.', 'probing', 'cover-art');
            }
            if ($metadata[0]->streams()->contains(fn ($stream) => (int) $stream->get('disposition.attached_pic') === 1)) {
                throw new MediaException('Cover art does not replace existing attached pictures.', 'probing', 'cover-art');
            }
            if ($metadata[0]->streams()->contains(fn ($stream) => ! in_array($stream->get('codec_type'), ['video', 'audio'], true))) {
                throw new MediaException('Cover art accepts sources containing only motion video and audio.', 'probing', 'cover-art');
            }
            $this->coverVideoTrack = $metadata[0]->streams()->filter(fn ($stream) => $stream->get('codec_type') === 'video')->count();
        }
        foreach ($this->watermarks as $mark) {
            $image = $metadata[$mark['input']];
            $gif = $image->get('format.format_name') === 'gif' && $image->video()?->get('codec_name') === 'gif';
            if ((! $gif && ! in_array($image->get('format.format_name'), ['png_pipe', 'jpeg_pipe'], true))
                || $image->streams()->count() !== 1 || (! $gif && $this->inputs[$mark['input']]->inputOptions() !== [])) {
                throw new MediaException('Watermarks require a standalone PNG, JPEG, or GIF image; ignore_loop is GIF-only.', 'probing', 'watermark');
            }
            if ($this->inputs[$mark['input']]->inputOptions() === ['-ignore_loop', '0']) {
                $this->watermarkDuration = $metadata[0]->duration();
                if ($this->watermarkDuration === null || $this->watermarkDuration <= 0) {
                    throw new MediaException('Looping GIF overlays require a finite primary duration.', 'probing', 'watermark');
                }
            }
        }
    }

    protected function prepareEncoding(array $metadata): ?float
    {
        if ($this->coverInput !== null) {
            return $metadata[0]->duration();
        }
        foreach ($this->configuredOutputs() as $output) {
            // Inserted silence extends the selected audio, not necessarily the video.
            if (array_any($output->outputConfiguration()['audioFilters'], fn ($filter) => preg_match('/(?:^|,)\s*adelay=/', $filter))) {
                return null;
            }
        }
        if ($this->watermarks !== []) {
            return $metadata[0]->duration();
        }

        // Graphs can change timing arbitrarily; do not invent a percentage.
        return $this->filterGraph() === null && ! array_any($metadata, fn ($item) => $item->duration() === null)
            ? max(array_map(fn ($item) => $item->duration(), $metadata)) : null;
    }

    protected function validateArtifact(string $path, string $role = 'media'): int
    {
        clearstatcache(true, $path);

        if (! is_file($path) || ($size = filesize($path)) === false || $size === 0) {
            throw new MediaException('FFmpeg did not produce a nonempty media file.', Progress::VALIDATION, 'artifact');
        }
        if ($role === 'hls-key' && $size !== 16) {
            throw new MediaException('An AES-128 HLS key must contain exactly 16 bytes.', Progress::VALIDATION, 'artifact');
        }

        return $size;
    }

    private function validateResources(Workspace $workspace, ProcessRunner $runner, array $resources): void
    {
        $required = [];

        foreach ($resources as $resource) {
            if ($resource['type'] !== 'file') {
                $required[] = $resource['type'] === 'text' ? 'drawtext' : 'subtitles';
            }
        }

        if ($required !== []) {
            $filters = $runner->run([config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'), '-hide_banner', '-filters'], $workspace->path);

            foreach (array_unique($required) as $filter) {
                if ($filters->exitCode !== 0 || ! preg_match('/^\s*\S+\s+'.preg_quote($filter, '/').'\s/m', $filters->output)) {
                    throw new MediaException('The FFmpeg build requires the '.$filter.' filter.', 'probing', 'capability');
                }
            }
        }

        foreach ($resources as $resource) {
            if ($resource['type'] !== 'subtitle') {
                continue;
            }

            if (! mb_check_encoding(file_get_contents($workspace->path.'/'.$resource['path']), 'UTF-8')) {
                throw new MediaException('Subtitle resources require UTF-8 for '.$resource['context'].'.', 'probing', 'resource');
            }

            // Probe the exact filename and bytes the subtitles filter reopens.
            // Caller input options must not force a different demuxer or seek.
            $probe = $runner->run([
                config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'), '-v', 'error', '-of', 'json',
                '-show_entries', 'format=format_name:stream=codec_type', '-protocol_whitelist', 'file',
                '-format_whitelist', 'srt,ass,webvtt', $resource['path'],
            ], $workspace->path);
            $data = json_decode($probe->output, true);

            if ($probe->exitCode !== 0 || ! in_array($data['format']['format_name'] ?? null, ['srt', 'ass', 'webvtt'], true)
                || ($data['streams'] ?? null) !== [['codec_type' => 'subtitle']]) {
                throw new MediaException('A subtitle resource must be a standalone SRT, ASS, or WebVTT file for '.$resource['context'].'.', 'probing', 'resource');
            }

            // The encoding binary must also accept and decode these unchanged
            // bytes under the whitelist before its filter reopens the file.
            $decode = $runner->run([
                config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'), '-v', 'error', '-nostdin',
                '-protocol_whitelist', 'file', '-format_whitelist', 'srt,ass,webvtt', '-i', $resource['path'],
                '-map', '0:s:0', '-c:s', 'ass', '-f', 'null', '-',
            ], $workspace->path);

            if ($decode->exitCode !== 0) {
                throw new MediaException('Cannot decode '.$resource['context'].'.', 'probing', 'resource');
            }
        }
    }

    protected function validateDestinations(array $outputs): void
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
                    if ((new Media($disk, $path, $input->inputOptions()))->identity() === $input->identity()) {
                        throw new InvalidArgumentException('Composition outputs cannot overwrite an input.');
                    }
                }
            }
        }
    }

    private function resolveMaps(ExportOutput $output): array
    {
        if ($this->coverInput !== null) {
            return ['0:V?', '0:a?', $this->coverInput.':v:0'];
        }

        return array_map(function ($map) {
            if (is_string($map)) {
                return $map;
            }

            $source = $map['stream']->sourceIdentity();
            $input = $map['input'];

            if ($input === null) {
                $matches = array_keys(array_filter($this->inputs, fn ($media) => $media->identity() === $source));

                if (count($matches) !== 1) {
                    throw new InvalidArgumentException('A probed stream must match exactly one input. Add its media or specify input explicitly for repeated media.');
                }

                $input = $matches[0];
            }

            if (! isset($this->inputs[$input]) || $this->inputs[$input]->identity() !== $source) {
                throw new InvalidArgumentException('The probed stream does not belong to the selected input.');
            }

            return $input.':'.$map['stream']->index().($map['optional'] ? '?' : '');
        }, $output->maps());
    }

    /** Ordered expected stream types; null denotes a graph pad whose type FFmpeg resolves. */
    private function selectedStreams(ExportOutput $output, Workspace $workspace, array $metadata): array
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

            $streams = $selector === '' ? $metadata[$input]->streams() : $workspace->probe($this->inputs[$input], $selector)->streams();

            if ($streams->isEmpty() && ! $exclude && ! str_ends_with($map, '?')) {
                throw new MediaException('A required stream map matched no streams.', 'probing', 'mapping');
            }

            foreach ($streams as $stream) {
                $identity = $input.':'.$stream->get('index');

                if ($exclude) {
                    $selected = array_values(array_filter($selected, fn ($item) => $item['identity'] !== $identity));
                } else {
                    $selected[] = ['identity' => $identity, 'type' => $stream->get('codec_type')];
                }
            }
        }

        if ($output->hasMaps() && $selected === []) {
            throw new MediaException('Explicit stream maps selected no output streams.', 'probing', 'mapping');
        }

        return array_column($selected, 'type');
    }

    private function notify(Progress $progress, string $operationId, array $outputs): void
    {
        if ($this->cancellation !== null && ($this->cancellation)()) {
            throw new ExportCancelled;
        }
        if ($this->progress !== null) {
            ($this->progress)($progress);
        }
        if (config('laravel-ffmpeg.enable_events', true)) {
            Event::dispatch(new Events\ExportProgress($operationId, $progress, $outputs));
        }
    }
}

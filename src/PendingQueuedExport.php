<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Queue\ExportJob;
use ProtoneMedia\LaravelFFMpeg\Queue\ExportPlan;
use ProtoneMedia\LaravelFFMpeg\Queue\HlsBatchJob;

/**
 * An export that runs on the queue, as a single job or as a parallel HLS batch.
 */
class PendingQueuedExport
{
    /**
     * The callback that configures each job before dispatch.
     */
    protected ?Closure $configure = null;

    /**
     * The unique identifier of the queued export.
     */
    public readonly string $id;

    /**
     * The captured state of the export builder.
     */
    protected readonly ExportPlan $plan;

    /**
     * The queue connection the jobs are dispatched to.
     */
    protected readonly ?string $connection;

    /**
     * The queue the jobs are dispatched to.
     */
    protected readonly ?string $queue;

    /**
     * Create a new pending queued export instance.
     */
    public function __construct(
        Exportable $export,
        ?string $connection = null,
        ?string $queue = null,
        protected readonly DateTimeInterface|DateInterval|int|null $delay = null,
        protected readonly bool $parallel = false,
        protected readonly ?float $timeout = null,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->connection = $connection ?? config('laravel-ffmpeg.queue.connection');
        $this->queue = $queue ?? config('laravel-ffmpeg.queue.queue');
        $this->plan = new ExportPlan($export);
    }

    /**
     * Configure the native Laravel job properties, middleware and chains before dispatch.
     */
    public function configureJob(callable $configure): static
    {
        $this->configure = Closure::fromCallable($configure);

        return $this;
    }

    /**
     * Build the job without dispatching it, for use with Bus::chain() and Bus::batch().
     */
    public function job(?string $path = null): ExportJob|HlsBatchJob
    {
        if (! $this->parallel) {
            return $this->configure(new ExportJob($this->id, $this->plan, $path));
        }

        $export = $this->plan->restore();

        if (! $export instanceof PendingHlsExport) {
            throw new InvalidArgumentException('Parallel queue exports require an HLS export.');
        }

        if ($path === null) {
            throw new InvalidArgumentException('Parallel queue exports require a master playlist destination.');
        }

        $disk = $export->filesystem();

        if (! is_string($disk)) {
            throw new InvalidArgumentException('Parallel HLS requires a shared named destination disk.');
        }

        // An export that splits into a single part has nothing to run in parallel, so we
        // will dispatch it as one ordinary job instead of a batch.
        $prefix = "laravel-ffmpeg/queued/{$this->id}";
        $partExports = $export->queueParts($path, $prefix);

        if (count($partExports) === 1) {
            return $this->configure(new ExportJob($this->id, $this->plan, $path));
        }

        // Every part becomes a batched job that writes its playback inventory. Once the
        // batch completes, the chained finalizer merges those inventories into the master
        // playlist at the requested path.
        $parts = [];
        $jobs = [];

        foreach ($partExports as $index => [$part, $destination]) {
            $parts[] = $manifest = "{$prefix}/inventories/{$index}.json";
            $jobs[] = $this->configure(
                new ExportJob($this->id, new ExportPlan($part), $destination, $disk, $manifest),
                initial: false,
            );
        }

        $finalizer = $this->configure(new ExportJob($this->id, null, $path, $disk, parts: $parts), initial: false);
        $batch = Bus::batch($jobs)->name("FFmpeg {$this->id}");

        if ($this->connection !== null) {
            $batch->onConnection($this->connection);
        }

        if ($this->queue !== null) {
            $batch->onQueue($this->queue);
        }

        $workflow = $this->configure(new HlsBatchJob($batch, $finalizer));
        $workflow->allOnConnection($this->connection)->allOnQueue($this->queue);

        return $workflow;
    }

    /**
     * Dispatch the export to the queue.
     */
    public function save(?string $path = null): PendingDispatch
    {
        return new PendingDispatch($this->job($path));
    }

    /**
     * Apply the queue, delay, timeout and retry settings to the given job.
     */
    protected function configure(ExportJob|HlsBatchJob $job, bool $initial = true): ExportJob|HlsBatchJob
    {
        $job->onConnection($this->connection)->onQueue($this->queue)->delay($initial ? $this->delay : null);
        $timeout = $this->timeout ?? config('laravel-ffmpeg.timeout', 3600);
        $job->timeout = config('laravel-ffmpeg.queue.timeout') ?? ($timeout === null ? 0 : (int) ceil($timeout) + 30);
        $job->tries = config('laravel-ffmpeg.queue.tries', 1);
        $job->backoff = config('laravel-ffmpeg.queue.backoff', 0);

        if ($this->configure instanceof Closure) {
            ($this->configure)($job);
        }

        return $job;
    }
}

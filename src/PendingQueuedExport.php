<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Queue\ExportJob;
use ProtoneMedia\LaravelFFMpeg\Queue\ExportPlan;
use ProtoneMedia\LaravelFFMpeg\Queue\HlsBatchJob;

class PendingQueuedExport
{
    private ?Closure $configure = null;

    public readonly string $id;

    private readonly ExportPlan $plan;

    public function __construct(
        PendingExport $export,
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
        private readonly DateTimeInterface|DateInterval|int|null $delay = null,
        private readonly bool $parallel = false,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->plan = new ExportPlan($export);
    }

    /** Configure native Laravel job properties, middleware and chains before dispatch. */
    public function configureJob(callable $configure): self
    {
        $this->configure = Closure::fromCallable($configure);

        return $this;
    }

    /** Build without dispatch, for Laravel Bus::chain() and Bus::batch(). */
    public function job(?string $path = null): ExportJob|HlsBatchJob
    {
        if (! $this->parallel) {
            return $this->configure(new ExportJob($this->id, $this->plan, $path));
        }
        $export = $this->plan->restore();
        if (! $export instanceof PendingHlsExport || $path === null) {
            throw new InvalidArgumentException('Parallel queue exports require HLS and a master destination.');
        }
        $disk = $export->filesystem();
        if (! is_string($disk)) {
            throw new InvalidArgumentException('Parallel HLS requires a shared named destination disk.');
        }
        $prefix = 'laravel-ffmpeg/queued/'.$this->id;
        $partExports = $export->queueParts($path, $prefix);
        if (count($partExports) === 1) {
            return $this->configure(new ExportJob($this->id, $this->plan, $path));
        }
        $parts = $jobs = [];
        foreach ($partExports as $index => [$part, $destination]) {
            $parts[] = $manifest = $prefix.'/inventories/'.$index.'.json';
            $jobs[] = $this->configure(new ExportJob($this->id, new ExportPlan($part), $destination, $disk, $manifest), initial: false);
        }
        $finalizer = $this->configure(new ExportJob($this->id, null, $path, $disk, parts: $parts), initial: false);
        $batch = Bus::batch($jobs)->name('FFmpeg '.$this->id);
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

    public function save(?string $path = null): PendingDispatch
    {
        return new PendingDispatch($this->job($path));
    }

    private function configure(ExportJob|HlsBatchJob $job, bool $initial = true): ExportJob|HlsBatchJob
    {
        $job->onConnection($this->connection)->onQueue($this->queue)->delay($initial ? $this->delay : null);
        $job->timeout = (int) config('laravel-ffmpeg.timeout', 3600) + 30;
        if ($this->configure !== null) {
            ($this->configure)($job);
        }

        return $job;
    }
}

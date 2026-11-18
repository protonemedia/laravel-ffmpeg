<?php

namespace ProtoneMedia\LaravelFFMpeg\Queue;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\DashExportResult;
use ProtoneMedia\LaravelFFMpeg\Events\QueuedExportCompleted;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\HlsExportResult;
use ProtoneMedia\LaravelFFMpeg\PendingHlsMerge;
use ProtoneMedia\LaravelFFMpeg\Result;
use RuntimeException;

/**
 * The queued job that runs an export, a parallel HLS part, or the merge of those parts.
 */
class ExportJob implements ShouldBeEncrypted, ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = 0;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public $maxExceptions;

    /**
     * Indicate if the job should be marked as failed on timeout.
     */
    public $failOnTimeout = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $exportId,
        public readonly ?ExportPlan $plan,
        public readonly ?string $path,
        public readonly ?string $manifestDisk = null,
        public readonly ?string $manifestPath = null,
        public readonly array $parts = [],
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            $this->chained = [];

            return;
        }

        // A parallel HLS part that already wrote its inventory has finished before, so we
        // will not encode it a second time when the job runs again.
        if ($this->manifestPath !== null && Storage::disk($this->manifestDisk)->exists($this->manifestPath)) {
            return;
        }

        if ($this->plan instanceof ExportPlan) {
            $export = $this->plan->restore();

            $export->cancelWhen(fn () => $this->batch()?->cancelled() ?? false);

            $result = $export->save($this->path);
        } else {
            $result = $this->mergeParts();
        }

        // A part stores its playback inventory privately for the finalizer to merge. Only
        // the finalizer reports the completed export, so a part returns without an event.
        if ($this->manifestPath !== null) {
            $this->retainInventory($result);

            return;
        }

        if (config('laravel-ffmpeg.enable_events', true)) {
            $this->dispatchCompletedEvent($result);
        }
    }

    /**
     * Merge the parts of a parallel HLS export into a single master playlist.
     */
    protected function mergeParts(): HlsExportResult
    {
        // Without a plan, this job is the finalizer of a parallel HLS export. We will read
        // the inventory of every part, make sure the whole batch succeeded, and then merge
        // the parts into a single master playlist.
        $presentations = [];
        $batchId = null;

        foreach ($this->parts as $part) {
            $manifest = json_decode(
                Storage::disk($this->manifestDisk)->get($part),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $batchId ??= $manifest['batchId'];

            if ($manifest['batchId'] !== $batchId) {
                throw new RuntimeException('Queued HLS inventories must belong to the same batch.');
            }

            $presentations[] = $manifest['playback'];
        }

        $batch = Bus::findBatch($batchId);

        if ($batch === null || $batch->cancelled() || $batch->pendingJobs !== 0 || $batch->failedJobs !== 0) {
            throw new ExportCancelled('The HLS conversion batch did not complete successfully.');
        }

        return new PendingHlsMerge($presentations)->toDisk($this->manifestDisk)->save($this->path);
    }

    /**
     * Store the playback inventory of a completed part for the finalizer.
     */
    protected function retainInventory(Result $result): void
    {
        if (! $result instanceof HlsExportResult
            || ! Storage::disk($this->manifestDisk)->put($this->manifestPath, json_encode([
                'batchId' => $this->batchId, 'playback' => $result->playback()->toArray(),
            ], JSON_THROW_ON_ERROR), ['visibility' => 'private'])) {
            throw new RuntimeException('Cannot retain the completed queued HLS inventory.');
        }
    }

    /**
     * Dispatch the event that reports the completed export.
     */
    protected function dispatchCompletedEvent(Result $result): void
    {
        $playback = null;

        if ($result instanceof HlsExportResult
            || ($result instanceof DashExportResult && $result->files()->contains('role', 'hls-master'))) {
            $playback = $result->playback()->toArray();
        }

        Event::dispatch(new QueuedExportCompleted(
            $this->exportId,
            $result->files()->map->toArray()->all(),
            $playback,
        ));
    }
}

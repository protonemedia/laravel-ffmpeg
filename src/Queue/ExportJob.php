<?php

namespace ProtoneMedia\LaravelFFMpeg\Queue;

use Illuminate\Bus\Batch;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use ProtoneMedia\LaravelFFMpeg\DashExportResult;
use ProtoneMedia\LaravelFFMpeg\Events\ExportFailed;
use ProtoneMedia\LaravelFFMpeg\Events\QueuedExportCompleted;
use ProtoneMedia\LaravelFFMpeg\Exceptions\ExportCancelled;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\HlsExportResult;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
use ProtoneMedia\LaravelFFMpeg\PendingHlsMerge;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Progress;
use ProtoneMedia\LaravelFFMpeg\Result;
use RuntimeException;
use Throwable;

/**
 * The queued job that runs an export, a parallel HLS part, or the merge of those parts.
 */
class ExportJob implements Interruptible, ShouldBeEncrypted, ShouldQueue
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
     * The export the job is running.
     */
    protected ?Exportable $export = null;

    /**
     * The signal that interrupted the job.
     */
    protected ?int $interruptedBy = null;

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

        $result = $this->plan instanceof ExportPlan ? $this->runExport() : $this->mergeParts();

        // A cancelled batch stops the parts that are still encoding. Laravel skips the jobs
        // of a cancelled batch instead of failing them, so such a part returns the same way.
        if (! $result instanceof Result) {
            $this->chained = [];

            return;
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

        // The merged master no longer needs the private masters and inventories of the
        // parts. We remove them last, so a finalizer that fails before this point still
        // finds them on its next attempt, and a failed removal never fails the export.
        if ($this->parts !== []) {
            rescue(fn () => $this->deletePartInventories());
        }
    }

    /**
     * Handle a signal received by the queue worker.
     */
    public function interrupted(int $signal): void
    {
        $this->interruptedBy ??= $signal;

        // A worker that times out kills itself as soon as this method returns, so we will
        // stop FFmpeg and delete the workspace right away. After any other signal the job
        // keeps running, so the export fails at its next check and cleans up after itself.
        $this->export?->interrupt($signal, terminate: $signal === SIGALRM);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception = null): void
    {
        // A failed part cancels the batch, which reports the completed parts once every
        // part has run. The finalizer only runs after every part completed, so when it
        // fails, it reports those parts itself.
        if ($this->parts !== []) {
            $this->dispatchFailedEvent(Progress::PUBLICATION);
        }
    }

    /**
     * Dispatch the event that reports the files the completed parts of a failed parallel export published.
     *
     * @internal
     */
    public function dispatchFailedEvent(string $phase): void
    {
        if (! config('laravel-ffmpeg.enable_events', true)) {
            return;
        }

        Event::dispatch(new ExportFailed(
            $this->exportId,
            $phase,
            [['disk' => $this->manifestDisk, 'path' => $this->path]],
            $this->publishedParts(),
        ));
    }

    /**
     * Restore the planned export and run it, or get null when its batch was cancelled.
     */
    protected function runExport(): ?Result
    {
        $export = $this->plan->restore();

        $export->cancelWhen(fn () => $this->batch()?->cancelled() ?? false);

        $this->export = $export;

        // The worker may have been interrupted while the export was being restored, so we
        // will pass the signal on. Any later signal reaches the export directly.
        if ($this->interruptedBy !== null) {
            $export->interrupt($this->interruptedBy);
        }

        try {
            return $export->save($this->path);
        } catch (ExportCancelled $exportCancelled) {
            if (! $this->batch()?->cancelled()) {
                throw $exportCancelled;
            }

            return null;
        } finally {
            $this->export = null;
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
            $inventory = $this->inventory($part);
            $batchId ??= $inventory['batchId'];

            if ($inventory['batchId'] !== $batchId) {
                throw new RuntimeException('Queued HLS inventories must belong to the same batch.');
            }

            $presentations[] = $inventory['playback'];
        }

        $batch = Bus::findBatch($batchId);

        if (! $this->batchSucceeded($batch)) {
            throw new ExportCancelled('The HLS conversion batch did not complete successfully.');
        }

        $this->ensureNotInterrupted();

        // The parts belong to this export alone, so the merged inventory owns their files,
        // unlike a mergeHls() composition, which borrows the files of other exports.
        return new PendingHlsMerge($presentations, owned: true)->toDisk($this->manifestDisk)->save($this->path);
    }

    /**
     * Determine if the given batch finished without cancelled, pending, or failed jobs.
     */
    protected function batchSucceeded(?Batch $batch): bool
    {
        return $batch instanceof Batch
            && ! $batch->cancelled()
            && $batch->pendingJobs === 0
            && $batch->failedJobs === 0;
    }

    /**
     * Ensure the queue worker has not interrupted the job.
     */
    protected function ensureNotInterrupted(): void
    {
        if ($this->interruptedBy !== null) {
            throw MediaException::publication(
                "The media operation was interrupted by signal [{$this->interruptedBy}].",
                'interrupted',
            );
        }
    }

    /**
     * Get the inventory that the given completed part retained.
     */
    protected function inventory(string $part): array
    {
        return json_decode(Storage::disk($this->manifestDisk)->get($part), true, flags: JSON_THROW_ON_ERROR);
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
     * Delete the private master playlists and inventories of the merged parts.
     */
    protected function deletePartInventories(): void
    {
        foreach ($this->parts as $part) {
            $master = HlsPresentation::fromArray($this->inventory($part)['playback'])->file();

            Storage::disk($master['disk'])->delete($master['path']);
            Storage::disk($this->manifestDisk)->delete($part);
        }
    }

    /**
     * Get the disk and path of every file the completed parts published, including their inventories.
     *
     * @return list<array{disk: string, path: string}>
     */
    protected function publishedParts(): array
    {
        $disk = Storage::disk($this->manifestDisk);

        return collect($this->parts)
            ->filter(fn (string $part): bool => $disk->exists($part))
            ->flatMap(fn (string $part): array => [
                ...$this->inventory($part)['playback']['files'],
                ['disk' => $this->manifestDisk, 'path' => $part],
            ])
            ->map(fn (array $file): array => ['disk' => $file['disk'], 'path' => $file['path']])
            ->values()
            ->all();
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

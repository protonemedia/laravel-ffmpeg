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
use RuntimeException;

class ExportJob implements ShouldBeEncrypted, ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;

    public $timeout;

    public $backoff = 0;

    public $maxExceptions;

    public $failOnTimeout = true;

    public function __construct(
        public readonly string $exportId,
        public readonly ?ExportPlan $plan,
        public readonly ?string $path,
        public readonly ?string $manifestDisk = null,
        public readonly ?string $manifestPath = null,
        public readonly array $parts = [],
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            $this->chained = [];

            return;
        }

        if ($this->manifestPath !== null && Storage::disk($this->manifestDisk)->exists($this->manifestPath)) {
            return;
        }

        if ($this->plan instanceof ExportPlan) {
            $export = $this->plan->restore();
            $export->cancelWhen(fn () => $this->batch()?->cancelled() ?? false);
            $result = $export->save($this->path);
        } else {
            $presentations = [];
            $batchId = null;
            foreach ($this->parts as $part) {
                $manifest = json_decode(Storage::disk($this->manifestDisk)->get($part), true, flags: JSON_THROW_ON_ERROR);
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

            $result = new PendingHlsMerge($presentations)->toDisk($this->manifestDisk)->save($this->path);
        }

        if ($this->manifestPath !== null) {
            if (! $result instanceof HlsExportResult
                || ! Storage::disk($this->manifestDisk)->put($this->manifestPath, json_encode([
                    'batchId' => $this->batchId, 'playback' => $result->playback()->toArray(),
                ], JSON_THROW_ON_ERROR), ['visibility' => 'private'])) {
                throw new RuntimeException('Cannot retain the completed queued HLS inventory.');
            }

            return;
        }

        if (config('laravel-ffmpeg.enable_events', true)) {
            Event::dispatch(new QueuedExportCompleted($this->exportId, $result->files()->map->toArray()->all(),
                $result instanceof HlsExportResult || ($result instanceof DashExportResult && $result->files()->contains('role', 'hls-master'))
                    ? $result->playback()->toArray() : null));
        }
    }
}

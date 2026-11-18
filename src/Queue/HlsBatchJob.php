<?php

namespace ProtoneMedia\LaravelFFMpeg\Queue;

use Illuminate\Bus\ChainedBatch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use InvalidArgumentException;

/**
 * The batch of parallel HLS parts, chained to the job that merges them.
 * Laravel's chain waits for the batch before dispatching the finalizer.
 *
 * @internal
 */
class HlsBatchJob extends ChainedBatch implements ShouldBeEncrypted
{
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
     * Create a new chained batch instance.
     */
    public function __construct(PendingBatch $batch, protected readonly ExportJob $finalizer)
    {
        parent::__construct($batch);
    }

    /**
     * Prevent the workflow from being added to another batch.
     */
    public function withBatchId(string $batchId)
    {
        throw new InvalidArgumentException(
            'Parallel HLS already creates a batch. Compose this workflow with Bus::chain(), not inside another Bus::batch().'
        );
    }

    /**
     * Attach the finalizer and the remainder of the chain to the end of the batch.
     */
    protected function attachRemainderOfChainToEndOfBatch(PendingBatch $batch)
    {
        $this->prependToChain($this->finalizer);

        return parent::attachRemainderOfChainToEndOfBatch($batch);
    }
}

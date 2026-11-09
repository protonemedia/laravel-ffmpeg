<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Queue;

use Illuminate\Bus\ChainedBatch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use InvalidArgumentException;

/** @internal Laravel's chain waits for the batch before dispatching the finalizer. */
class HlsBatchJob extends ChainedBatch implements ShouldBeEncrypted
{
    public $tries = 1;

    public $timeout;

    public $backoff = 0;

    public $maxExceptions;

    public $failOnTimeout = true;

    public function __construct(PendingBatch $batch, protected readonly ExportJob $finalizer)
    {
        parent::__construct($batch);
    }

    public function withBatchId(string $batchId)
    {
        throw new InvalidArgumentException('Parallel HLS already creates a batch. Compose this workflow with Bus::chain(), not inside another Bus::batch().');
    }

    protected function attachRemainderOfChainToEndOfBatch(PendingBatch $batch)
    {
        $this->prependToChain($this->finalizer);

        return parent::attachRemainderOfChainToEndOfBatch($batch);
    }
}

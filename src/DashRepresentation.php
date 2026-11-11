<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Traits\Conditionable;
use LogicException;

/**
 * Configure the encoding of one explicitly mapped audio or video DASH stream.
 */
class DashRepresentation
{
    use Conditionable;
    use ConfiguresOutput {
        toDisk as private;
        visibility as private;
        filesystem as private;
        storageOptions as private;
        inherit as public;
    }

    /**
     * Indicates if the representation settings are frozen because the export has started.
     */
    protected bool $frozen = false;

    /**
     * Create a new DASH representation instance.
     */
    public function __construct()
    {
        $this->disk = config('filesystems.default');
    }

    /**
     * Ensure the representation settings may still be changed.
     */
    protected function assertOutputMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('DASH representation settings cannot change after execution starts.');
        }
    }

    /**
     * Freeze the representation settings once the export starts.
     *
     * @internal
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }
}

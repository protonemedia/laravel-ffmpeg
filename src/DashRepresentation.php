<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Traits\Conditionable;
use LogicException;

/** Encoding settings for one explicitly mapped audio or video DASH stream. */
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

    protected bool $frozen = false;

    public function __construct()
    {
        $this->disk = config('filesystems.default');
    }

    protected function assertOutputMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('DASH representation settings cannot change after execution starts.');
        }
    }

    /** @internal */
    public function freeze(): void
    {
        $this->frozen = true;
    }
}

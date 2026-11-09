<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Traits\Conditionable;
use LogicException;

class HlsVariant
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

    protected ?string $audioGroup = null;

    protected ?string $subtitleGroup = null;

    /** Reference reusable alternate renditions declared on PendingHlsExport. */
    public function audioGroup(string $group): self
    {
        $this->assertOutputMutable();
        $this->audioGroup = $group;

        return $this;
    }

    public function subtitleGroup(string $group): self
    {
        $this->assertOutputMutable();
        $this->subtitleGroup = $group;

        return $this;
    }

    /** @internal */
    public function groups(): array
    {
        return ['AUDIO' => $this->audioGroup, 'SUBTITLES' => $this->subtitleGroup];
    }

    public function __construct()
    {
        $this->disk = config('filesystems.default');
    }

    protected function assertOutputMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('Variant settings cannot be changed after the export has started.');
        }
    }

    /** @internal */
    public function freeze(): void
    {
        $this->frozen = true;
    }
}

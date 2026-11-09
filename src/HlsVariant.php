<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Traits\Conditionable;
use LogicException;

/**
 * Configure the encoding of one HLS variant stream.
 */
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

    /**
     * Indicates if the variant settings are frozen because the export has started.
     */
    protected bool $frozen = false;

    /**
     * The audio rendition group the variant references.
     */
    protected ?string $audioGroup = null;

    /**
     * The subtitle rendition group the variant references.
     */
    protected ?string $subtitleGroup = null;

    /**
     * Reference the reusable audio renditions declared on the HLS export.
     */
    public function audioGroup(string $group): self
    {
        $this->assertOutputMutable();
        $this->audioGroup = $group;

        return $this;
    }

    /**
     * Reference the reusable subtitle renditions declared on the HLS export.
     */
    public function subtitleGroup(string $group): self
    {
        $this->assertOutputMutable();
        $this->subtitleGroup = $group;

        return $this;
    }

    /**
     * Get the audio and subtitle groups the variant references.
     *
     * @internal
     */
    public function groups(): array
    {
        return ['AUDIO' => $this->audioGroup, 'SUBTITLES' => $this->subtitleGroup];
    }

    /**
     * Create a new HLS variant instance.
     */
    public function __construct()
    {
        $this->disk = config('filesystems.default');
    }

    /**
     * Ensure the variant settings may still be changed.
     */
    protected function assertOutputMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('Variant settings cannot be changed after the export has started.');
        }
    }

    /**
     * Freeze the variant settings once the export starts.
     *
     * @internal
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }
}

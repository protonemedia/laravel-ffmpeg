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
    }

    private bool $frozen = false;

    private ?string $audioGroup = null;

    private ?string $subtitlesGroup = null;

    /** Reference reusable alternate renditions declared on PendingHlsExport. */
    public function audioGroup(string $group): self
    {
        $this->assertOutputMutable();
        $this->audioGroup = $group;

        return $this;
    }

    public function subtitlesGroup(string $group): self
    {
        $this->assertOutputMutable();
        $this->subtitlesGroup = $group;

        return $this;
    }

    /** @internal */
    public function groups(): array
    {
        return ['AUDIO' => $this->audioGroup, 'SUBTITLES' => $this->subtitlesGroup];
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

    /** @internal Resolve shared defaults at compilation, not declaration time. */
    public function inherit(array $shared): array
    {
        foreach ($this->outputConfiguration() as $key => $value) {
            if (in_array($key, ['disk', 'outputDiskExplicit', 'writeOptions'], true)) {
                continue;
            }

            if (in_array($key, ['video', 'audio', 'metadata'], true)) {
                $shared[$key] = array_replace($shared[$key], $value);
            } elseif (in_array($key, ['videoFilters', 'audioFilters', 'streamMetadata', 'dispositions'], true)) {
                $shared[$key] = [...$shared[$key], ...$value];
            } elseif ($key === 'outputOptions') {
                $overrides = [];
                for ($i = 0; $i < count($value); $i += 2) {
                    $overrides[] = $value[$i];
                }
                $tokens = $shared[$key];
                $shared[$key] = [];
                for ($i = 0; $i < count($tokens); $i += 2) {
                    if ($tokens[$i] === '-streamid' || ! in_array($tokens[$i], $overrides, true)) {
                        array_push($shared[$key], $tokens[$i], $tokens[$i + 1]);
                    }
                }
                array_push($shared[$key], ...$value);
            } elseif ($value !== [] && $value !== null && $value !== false) {
                $shared[$key] = $value;
            }
        }

        return $shared;
    }
}

<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Collection;

/** The published files and process summaries of a completed export. */
interface Result
{
    /** @return Collection<int, GeneratedFile> */
    public function files(): Collection;

    public function file(?string $path = null, ?string $disk = null): GeneratedFile;

    public function processes(): Collection;
}

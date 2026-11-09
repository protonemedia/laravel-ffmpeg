<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Execution;

/** @internal Sources other than a Storage key or a local file. */
interface InputSource
{
    public function identity(): string;

    public function extension(): string;

    public function label(): string;

    public function protocols(): string;

    public function options(): array;

    public function redactions(): array;

    public function stage(Workspace $workspace, string $name, bool $resource): string;
}

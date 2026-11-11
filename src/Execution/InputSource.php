<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

/**
 * A media source other than a Storage key or a local file.
 *
 * @internal
 */
interface InputSource
{
    /**
     * Get a stable hash that identifies the source and its settings.
     */
    public function identity(): string;

    /**
     * Get the file extension of the source.
     */
    public function extension(): string;

    /**
     * Get the redacted label that stands in for the source in metadata.
     */
    public function label(): string;

    /**
     * Get the comma-separated FFmpeg protocol whitelist for the staged source.
     */
    public function protocols(): string;

    /**
     * Get the FFmpeg transport options that precede the input.
     */
    public function options(): array;

    /**
     * Get the secret values to replace in commands and diagnostics.
     */
    public function redactions(): array;

    /**
     * Stage the source in the workspace and get the path or URL FFmpeg reads.
     */
    public function stage(Workspace $workspace, string $name, bool $resource): string;
}

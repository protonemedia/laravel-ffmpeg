<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use Illuminate\Filesystem\Filesystem;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

/** @internal */
class Workspace
{
    public readonly string $path;

    public function __construct()
    {
        $root = config('laravel-ffmpeg.temporary_files_root') ?: sys_get_temp_dir();
        $this->path = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'laravel-ffmpeg-'.bin2hex(random_bytes(16));

        if (! @mkdir($this->path, 0700)) {
            throw new MediaException('Cannot create the media workspace.', 'staging', 'filesystem');
        }
    }

    public function delete(): void
    {
        if (! (new Filesystem)->deleteDirectory($this->path)) {
            throw new MediaException('Cannot remove the media workspace.', 'cleanup', 'filesystem');
        }
    }
}

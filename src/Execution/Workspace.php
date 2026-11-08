<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use Illuminate\Filesystem\Filesystem;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\Metadata;

/** @internal */
class Workspace
{
    public readonly string $path;

    private array $assets = [];

    private array $metadata = [];

    private array $probeVersions = [];

    private array $protocols = [];

    private int $nextAsset = 0;

    public function __construct(public readonly ProcessRunner $runner = new ProcessRunner)
    {
        $root = config('laravel-ffmpeg.temporary_files_root') ?: sys_get_temp_dir();
        $this->path = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'laravel-ffmpeg-'.bin2hex(random_bytes(16));

        if (! @mkdir($this->path, 0700)) {
            throw new MediaException('Cannot create the media workspace.', 'staging', 'filesystem');
        }
    }

    public function stage(Media $media, bool $resource = false): string
    {
        $this->runner->check('staging');
        // Keep purpose entries separate: a resource may contain manifest-like
        // text, but that must never bypass validation when used as media later.
        // The trade-off is a second transfer if both purposes use a remote key.
        $identity = ($resource ? 'resource:' : 'media:').$media->identity();

        if (! isset($this->assets[$identity])) {
            $extension = $media->extension();
            $name = 'input-'.$this->nextAsset++.($extension === '' ? '' : '.'.$extension);
            $path = $media->stage($this, $name, $resource);

            // Retain the descriptor so filesystem object identities cannot be
            // recycled while their staged entries are still in this execution.
            $this->assets[$identity] = ['media' => $media, 'path' => $path];
        }

        $this->runner->check('staging');

        return $this->assets[$identity]['path'];
    }

    public function probe(Media $media, ?string $selector = null): Metadata
    {
        $path = $this->stage($media);
        $identity = $media->identity().':'.serialize($selector);

        if (! isset($this->metadata[$identity])) {
            $binary = config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe');

            if (! isset($this->probeVersions[$binary])) {
                $this->runner->version($binary, 'ffprobe', $this->path);
                $this->probeVersions[$binary] = true;
            }

            $this->metadata[$identity] = $media->probePath($path, $this, $selector);
        }

        return $this->metadata[$identity];
    }

    public function requireProtocols(string $binary, string $protocols): void
    {
        if ($protocols === 'file') {
            return;
        }

        if (! isset($this->protocols[$binary])) {
            $result = $this->runner->run([$binary, '-hide_banner', '-protocols'], $this->path, outputLimit: 65536);
            $this->protocols[$binary] = $result->exitCode === 0 && preg_match('/Input:\s*(.*?)\s*Output:/s', $result->output, $matches)
                ? preg_split('/\s+/', trim($matches[1])) : [];
        }

        if (array_diff(explode(',', $protocols), $this->protocols[$binary]) !== []) {
            throw new MediaException('The media binary lacks required URL input protocols.', 'probing', 'capability');
        }
    }

    public function delete(): void
    {
        if (! (new Filesystem)->deleteDirectory($this->path)) {
            throw new MediaException('Cannot remove the media workspace.', 'cleanup', 'filesystem');
        }
    }
}

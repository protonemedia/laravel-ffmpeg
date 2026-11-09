<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use Illuminate\Filesystem\Filesystem;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\Metadata;

/**
 * A private temporary directory that holds the staged inputs and outputs of one operation.
 *
 * @internal
 */
class Workspace
{
    /**
     * The absolute path of the workspace directory.
     */
    public readonly string $path;

    /**
     * The staged media and their paths, keyed by purpose and identity.
     */
    protected array $assets = [];

    /**
     * The probed metadata, keyed by media identity and stream selector.
     */
    protected array $metadata = [];

    /**
     * The FFprobe binaries whose version has been verified.
     */
    protected array $probeVersions = [];

    /**
     * The input protocols each binary supports.
     */
    protected array $protocols = [];

    /**
     * The number of the next staged asset.
     */
    protected int $nextAsset = 0;

    /**
     * Create a new workspace instance.
     */
    public function __construct(public readonly ProcessRunner $runner = new ProcessRunner)
    {
        $root = config('laravel-ffmpeg.temporary_files_root') ?: sys_get_temp_dir();
        $this->path = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'laravel-ffmpeg-'.bin2hex(random_bytes(16));

        if (! @mkdir($this->path, 0700)) {
            throw MediaException::staging('Cannot create the media workspace.', 'filesystem');
        }
    }

    /**
     * Stage the given media once and get the path FFmpeg reads.
     */
    public function stage(Media $media, bool $resource = false): string
    {
        $this->runner->check('staging');

        // Media and resource entries are kept apart. A resource may contain manifest-like
        // text, which must never bypass validation when the same file is later used as
        // media. The trade-off is a second transfer when both purposes use a remote key.
        $identity = ($resource ? 'resource:' : 'media:').$media->identity();

        if (! isset($this->assets[$identity])) {
            $extension = $media->extension();
            $name = 'input-'.$this->nextAsset++.($extension === '' ? '' : '.'.$extension);
            $path = $media->stage($this, $name, $resource);

            // We retain the descriptor so its filesystem object identity cannot be
            // recycled while the staged entry is still in use by this execution.
            $this->assets[$identity] = ['media' => $media, 'path' => $path];
        }

        $this->runner->check('staging');

        return $this->assets[$identity]['path'];
    }

    /**
     * Probe the given media once and get its metadata.
     */
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

            $this->metadata[$identity] = $media->probeInput($path, $this, $selector);
        }

        return $this->metadata[$identity];
    }

    /**
     * Ensure the given binary supports every input protocol in the whitelist.
     */
    public function requireProtocols(string $binary, string $protocols, string $phase = 'probing'): void
    {
        if ($protocols === 'file') {
            return;
        }

        if (! isset($this->protocols[$binary])) {
            $result = $this->runner->run(
                [$binary, '-hide_banner', '-protocols'],
                $this->path,
                outputLimit: 65536,
                phase: $phase,
            );
            $this->protocols[$binary] = $result->exitCode === 0
                && preg_match('/Input:\s*(.*?)\s*Output:/s', $result->output, $matches)
                ? preg_split('/\s+/', trim($matches[1]))
                : [];
        }

        if (array_diff(explode(',', $protocols), $this->protocols[$binary]) !== []) {
            throw new MediaException('The media binary lacks required URL input protocols.', $phase, 'capability');
        }
    }

    /**
     * Delete the workspace directory and everything in it.
     */
    public function delete(): void
    {
        if (! (new Filesystem)->deleteDirectory($this->path)) {
            throw MediaException::cleanup('Cannot remove the media workspace.', 'filesystem');
        }
    }
}

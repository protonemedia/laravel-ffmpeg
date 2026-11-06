<?php

namespace ProtoneMedia\LaravelFFMpeg;

use BadMethodCallException;
use Illuminate\Contracts\Filesystem\Filesystem;

readonly class GeneratedFile
{
    public function __construct(
        private Filesystem $filesystem,
        public ?string $disk,
        public string $path,
        public int $size,
        public string $role = 'media',
        public bool $owned = true,
    ) {}

    public function url(): string
    {
        if (! method_exists($this->filesystem, 'url')) {
            throw new BadMethodCallException('This filesystem does not support URLs.');
        }

        return $this->filesystem->url($this->path);
    }

    public function toArray(): array
    {
        return ['disk' => $this->disk, 'path' => $this->path, 'size' => $this->size, 'role' => $this->role, 'owned' => $this->owned];
    }
}

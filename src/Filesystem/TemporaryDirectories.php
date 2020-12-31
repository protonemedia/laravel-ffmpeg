<?php

namespace ProtoneMedia\LaravelFFMpeg\Filesystem;

use Illuminate\Filesystem\Filesystem;

class TemporaryDirectories
{
    private $root;

    private $directories = [];

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function create(): string
    {
        return $this->directories[] = $this->root . uniqid('/');
    }

    /**
     * Loop through all directories and delete them.
     */
    public function deleteAll(): void
    {
        $filesystem = new Filesystem;

        foreach ($this->directories as $directory) {
            $filesystem->deleteDirectory($directory);
        }

        $this->directories = [];
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Filesystem;

use Illuminate\Filesystem\Filesystem;

class TemporaryDirectories
{
    /**
     * Root of the temporary directories.
     *
     * @var string
     */
    private $root;

    /**
     * Array of all directories
     *
     * @var array
     */
    private $directories = [];

    /**
     * Sets the root and removes the trailing slash.
     *
     * @param string $root
     */
    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    /**
     * Returns the full path a of new temporary directory.
     *
     * @return string
     */
    public function create(): string
    {
        return $this->directories[] = $this->root . '/' . bin2hex(random_bytes(8));
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

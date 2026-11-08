<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use ProtoneMedia\LaravelFFMpeg\InputSelector;

class MediaOpenerFactory
{
    public function disk(string|Filesystem|null $disk = null): InputSelector
    {
        return new InputSelector($disk ?? config('filesystems.default'));
    }
}

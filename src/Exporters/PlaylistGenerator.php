<?php

namespace Pbmedia\LaravelFFMpeg\Exporters;

use Pbmedia\LaravelFFMpeg\Drivers\PHPFFMpeg;

interface PlaylistGenerator
{
    public function get(array $playlistMedia, PHPFFMpeg $driver): string;
}

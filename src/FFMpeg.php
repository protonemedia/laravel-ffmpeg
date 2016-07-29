<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\FFMpeg as BaseFFMpeg;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Psr\Log\LoggerInterface;

class FFMpeg
{
    protected $filesystems;

    protected $disk;

    protected $ffmpeg;

    public function __construct(Filesystems $filesystems, ConfigRepository $config, LoggerInterface $logger)
    {
        $this->filesystems = $filesystems;

        $this->ffmpeg = BaseFFMpeg::create($config->get('laravel-ffmpeg'), $logger);

        $this->disk($config->get('laravel-ffmpeg.default_disk') ?: $config->get('filesystems.default'));
    }

    public function disk(string $diskName): self
    {
        $this->disk = new Disk($this->filesystems->disk($diskName));

        return $this;
    }

    public function open($path): Media
    {
        $file = $this->disk->getFile($path);

        $ffmpegMedia = $this->ffmpeg->open($file->getFullPath());

        return new Media($file, $ffmpegMedia);
    }
}

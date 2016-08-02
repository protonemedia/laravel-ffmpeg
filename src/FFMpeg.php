<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\FFMpeg as BaseFFMpeg;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;

class FFMpeg
{
    protected $disk;

    protected $ffmpeg;

    public function __construct(ConfigRepository $config, LoggerInterface $logger)
    {
        $this->ffmpeg = BaseFFMpeg::create(
            $config->get('laravel-ffmpeg'),
            $logger
        );

        $this->fromDisk($config->get('laravel-ffmpeg.default_disk') ?: $config->get('filesystems.default'));
    }

    public function fromDisk(string $diskName): self
    {
        $this->disk = Disk::fromName($diskName);

        return $this;
    }

    public function open($path): Media
    {
        $file = $this->disk->newFile($path);

        $ffmpegMedia = $this->ffmpeg->open($file->getFullPath());

        return new Media($file, $ffmpegMedia);
    }
}

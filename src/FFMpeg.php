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

        $this->ffmpeg = BaseFFMpeg::create($config->get('ffmpeg'), $logger);

        $this->setDisk($config->get('filesystems.default'));
    }

    public function setDisk($diskName): self
    {
        $this->disk = new Disk($this->filesystems->disk($disk));

        return $this;
    }

    public function open(File $file): Media
    {
        $file = $this->disk->getFile($pathfile);

        $ffmpegMedia = $this->ffmpeg->open($file->getFullPath());

        return new Media($file, $ffmpegMedia);
    }
}

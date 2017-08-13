<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\FFMpeg as BaseFFMpeg;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;

class FFMpeg
{
    protected static $filesystems;

    private static $temporaryFiles = [];

    protected $disk;

    protected $ffmpeg;

    public function __construct(Filesystems $filesystems, ConfigRepository $config, LoggerInterface $logger)
    {
        static::$filesystems = $filesystems;

        $ffmpegConfig = $config->get('laravel-ffmpeg');

        $this->ffmpeg = BaseFFMpeg::create($ffmpegConfig, $logger);
        $this->fromDisk($ffmpegConfig['default_disk'] ?? $config->get('filesystems.default'));
    }

    public static function getFilesystems(): Filesystems
    {
        return static::$filesystems;
    }

    public static function newTemporaryFile(): string
    {
        return self::$temporaryFiles[] = tempnam(sys_get_temp_dir(), 'laravel-ffmpeg');
    }

    public function cleanupTemporaryFiles()
    {
        foreach (self::$temporaryFiles as $path) {
            @unlink($path);
        }
    }

    public function fromFilesystem(Filesystem $filesystem): FFMpeg
    {
        $this->disk = new Disk($filesystem);

        return $this;
    }

    public function fromDisk(string $diskName): FFMpeg
    {
        $filesystem = static::getFilesystems()->disk($diskName);
        $this->disk = new Disk($filesystem);

        return $this;
    }

    public function open($path): Media
    {
        $file = $this->disk->newFile($path);

        if ($this->disk->isLocal()) {
            $ffmpegPathFile = $file->getFullPath();
        } else {
            $ffmpegPathFile = static::newTemporaryFile();
            file_put_contents($ffmpegPathFile, $this->disk->read($path));
        }

        $ffmpegMedia = $this->ffmpeg->open($ffmpegPathFile);

        return new Media($file, $ffmpegMedia);
    }
}

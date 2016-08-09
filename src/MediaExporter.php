<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Format\FormatInterface;

/**
 * @method mixed save(FormatInterface $format, $outputPathfile)
 */
class MediaExporter
{
    protected $media;

    protected $disk;

    protected $frameMustBeAccurate = false;

    protected $format;

    public function __construct(Media $media)
    {
        $this->media = $media;
        $this->disk  = $media->getFile()->getDisk();
    }

    protected function getFormat(): FormatInterface
    {
        return $this->format;
    }

    public function inFormat(FormatInterface $format): self
    {
        $this->format = $format;

        return $this;
    }

    protected function getDisk(): Disk
    {
        return $this->disk;
    }

    public function toDisk(string $diskName): self
    {
        $this->disk = Disk::fromName($diskName);

        return $this;
    }

    public function accurate(): self
    {
        $this->frameMustBeAccurate = true;

        return $this;
    }

    public function unaccurate(): self
    {
        $this->frameMustBeAccurate = false;

        return $this;
    }

    public function getAccuracy(): bool
    {
        return $this->frameMustBeAccurate;
    }

    public function save(string $path): Media
    {
        $disk = $this->getDisk();
        $file = $disk->newFile($path);

        $pathinfo = pathinfo($path);

        if (!$disk->isLocal()) {
            $destination = tempnam(sys_get_temp_dir(), 'laravel-ffmpeg') . '.' . $pathinfo['extension'];
        } else {
            $destination = $file->getFullPath();
        }

        if ($this->media->isFrame()) {
            $this->saveFrame($destination);
        } else {
            $this->saveAudioOrVideo($destination);
        }

        if (!$disk->isLocal()) {
            $file->createFromTempPath($destination);
        }

        return $this->media;
    }

    private function saveFrame(string $fullPath): self
    {
        $this->media->save($fullPath, $this->getAccuracy());

        return $this;
    }

    private function saveAudioOrVideo(string $fullPath): self
    {
        $this->media->save($this->getFormat(), $fullPath);

        return $this;
    }
}

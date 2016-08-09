<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Format\FormatInterface;

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

    public function inFormat(FormatInterface $format): MediaExporter
    {
        $this->format = $format;

        return $this;
    }

    protected function getDisk(): Disk
    {
        return $this->disk;
    }

    public function toDisk($diskOrName): MediaExporter
    {
        if ($diskOrName instanceof Disk) {
            $this->disk = $diskOrName;
        } else {
            $this->disk = Disk::fromName($diskOrName);
        }

        return $this;
    }

    public function accurate(): MediaExporter
    {
        $this->frameMustBeAccurate = true;

        return $this;
    }

    public function unaccurate(): MediaExporter
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
        $file = $this->getDisk()->newFile($path);

        $destinationPath = $this->getDestinationPathForSaving($file);

        $saveMethod = $this->media->isFrame() ? 'saveFrame' : 'saveAudioOrVideo';

        $this->{$saveMethod}($destinationPath);

        if (!$this->getDisk()->isLocal()) {
            $this->moveSavedFileToRemoteDisk($destinationPath, $file);
        }

        return $this->media;
    }

    protected function moveSavedFileToRemoteDisk($localSourcePath, File $fileOnRemoteDisk): bool
    {
        return $fileOnRemoteDisk->put($localSourcePath) && unlink($localSourcePath);
    }

    private function getDestinationPathForSaving(File $file): string
    {
        if (!$file->getDisk()->isLocal()) {
            $tempName = tempnam(sys_get_temp_dir(), 'laravel-ffmpeg');

            return $tempName . '.' . $file->getExtension();
        }

        return $file->getFullPath();
    }

    private function saveFrame(string $fullPath): MediaExporter
    {
        $this->media->save($fullPath, $this->getAccuracy());

        return $this;
    }

    private function saveAudioOrVideo(string $fullPath): MediaExporter
    {
        $this->media->save($this->getFormat(), $fullPath);

        return $this;
    }
}

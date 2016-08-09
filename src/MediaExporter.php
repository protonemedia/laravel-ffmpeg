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
        $file = $this->getDisk()->newFile($path);

        $destinationPath = $this->getDestinationPathForSaving($file);

        $saveMethod = $this->media->isFrame() ? 'saveFrame' : 'saveAudioOrVideo';

        $this->{$saveMethod}($destinationPath);

        if (!$this->getDisk()->isLocal()) {
            $this->moveSavedFileToRemoteDisk($destinationPath, $file);
        }

        return $this->media;
    }

    private function moveSavedFileToRemoteDisk($localSourcePath, File $fileOnRemoteDisk): bool
    {
        $resource = fopen($localSourcePath, 'r');

        return $fileOnRemoteDisk->put($resource) && unlink($localSourcePath);
    }

    private function getDestinationPathForSaving(File $file): string
    {
        if (!$file->getDisk()->isLocal()) {
            $tempName = tempnam(sys_get_temp_dir(), 'laravel-ffmpeg');

            return $tempName . '.' . $file->getExtension();
        }

        return $file->getFullPath();
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

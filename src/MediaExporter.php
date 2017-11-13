<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Format\FormatInterface;

class MediaExporter
{
    protected $media;

    protected $disk;

    protected $format;

    protected $visibility;

    protected $saveMethod = 'saveAudioOrVideo';

    public function __construct(Media $media)
    {
        $this->media = $media;

        $this->disk = $media->getFile()->getDisk();
    }

    public function getMedia(): Media
    {
        return $this->media;
    }

    public function getFormat(): FormatInterface
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

    public function withVisibility(string $visibility = null)
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function save(string $path): Media
    {
        $disk = $this->getDisk();
        $file = $disk->newFile($path);

        $destinationPath = $this->getDestinationPathForSaving($file);

        $this->createDestinationPathForSaving($file);

        $this->{$this->saveMethod}($destinationPath);

        if (!$disk->isLocal()) {
            $this->moveSavedFileToRemoteDisk($destinationPath, $file);
        }

        if ($this->visibility !== null) {
            $disk->setVisibility($path, $this->visibility);
        }

        return $this->media;
    }

    protected function moveSavedFileToRemoteDisk($localSourcePath, File $fileOnRemoteDisk): bool
    {
        return $fileOnRemoteDisk->put($localSourcePath) && @unlink($localSourcePath);
    }

    private function getDestinationPathForSaving(File $file): string
    {
        if (!$file->getDisk()->isLocal()) {
            $tempName = FFMpeg::newTemporaryFile();

            return $tempName . '.' . $file->getExtension();
        }

        return $file->getFullPath();
    }

    private function createDestinationPathForSaving(File $file)
    {
        if (!$file->getDisk()->isLocal()) {
            return false;
        }

        $directory = pathinfo($file->getPath())['dirname'];

        return $file->getDisk()->createDirectory($directory);
    }

    private function saveAudioOrVideo(string $fullPath): MediaExporter
    {
        $this->media->save($this->getFormat(), $fullPath);

        return $this;
    }
}

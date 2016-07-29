<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Format\FormatInterface;
use FFMpeg\Media\MediaTypeInterface;

class Media
{
    protected $file;

    protected $media;

    public function __construct(File $file, MediaTypeInterface $media)
    {
        $this->file  = $file;
        $this->media = $media;
    }

    public function save(FormatInterface $format, File $file): self
    {
        return $this->selfOrArgument(
            $this->media->save($format, $file->getFullPath())
        );
    }

    protected function selfOrArgument($arg)
    {
        return ($arg === $this->media) ? $this : $arg;
    }

    public function __call($method, $parameters)
    {
        return $this->selfOrArgument(
            call_user_func_array([$this->media, $method], $parameters)
        );
    }
}

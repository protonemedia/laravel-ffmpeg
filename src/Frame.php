<?php

namespace Pbmedia\LaravelFFMpeg;

use FFMpeg\Media\Frame as BaseFrame;

/**
 * @method BaseFrame save($pathfile, $accurate = false)
 */
class Frame extends Media
{
    public function export(): MediaExporter
    {
        return new FrameExporter($this);
    }
}

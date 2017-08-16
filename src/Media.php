<?php

namespace Pbmedia\LaravelFFMpeg;

use Closure;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Filters\Audio\SimpleFilter;
use FFMpeg\Filters\FilterInterface;
use FFMpeg\Media\MediaTypeInterface;

/**
 * @method mixed save(\FFMpeg\Format\FormatInterface $format, $outputPathfile)
 */
class Media
{
    protected $file;

    protected $media;

    public function __construct(File $file, MediaTypeInterface $media)
    {
        $this->file  = $file;
        $this->media = $media;
    }

    public function isFrame(): bool
    {
        return $this instanceof Frame;
    }

    public function getFile(): File
    {
        return $this->file;
    }

    public function getDurationInSeconds(): int
    {
        return $this->getDurationInMiliseconds() / 1000;
    }

    public function getFirstStream()
    {
        return $this->media->getStreams()->first();
    }

    public function getDurationInMiliseconds(): float
    {
        $stream = $this->getFirstStream();

        if ($stream->has('duration')) {
            return $stream->get('duration') * 1000;
        }

        $format = $this->media->getFormat();

        if ($format->has('duration')) {
            return $format->get('duration') * 1000;
        }
    }

    public function export(): MediaExporter
    {
        return new MediaExporter($this);
    }

    public function exportForHLS(): HLSPlaylistExporter
    {
        return new HLSPlaylistExporter($this);
    }

    public function getFrameFromString(string $timecode): Frame
    {
        return $this->getFrameFromTimecode(
            TimeCode::fromString($timecode)
        );
    }

    public function getFrameFromSeconds(float $quantity): Frame
    {
        return $this->getFrameFromTimecode(
            TimeCode::fromSeconds($quantity)
        );
    }

    public function getFrameFromTimecode(TimeCode $timecode): Frame
    {
        $frame = $this->media->frame($timecode);

        return new Frame($this->getFile(), $frame);
    }

    public function addFilter(): Media
    {
        $arguments = func_get_args();

        if (isset($arguments[0]) && $arguments[0] instanceof Closure) {
            call_user_func_array($arguments[0], [$this->media->filters()]);
        } else if (isset($arguments[0]) && $arguments[0] instanceof FilterInterface) {
            call_user_func_array([$this->media, 'addFilter'], $arguments);
        } else if (isset($arguments[0]) && is_array($arguments[0])) {
            $this->media->addFilter(new SimpleFilter($arguments[0]));
        } else {
            $this->media->addFilter(new SimpleFilter($arguments));
        }

        return $this;
    }

    protected function selfOrArgument($argument)
    {
        return ($argument === $this->media) ? $this : $argument;
    }

    public function __invoke(): MediaTypeInterface
    {
        return $this->media;
    }

    public function __clone()
    {
        if ($this->media) {
            $clonedFilters = clone $this->media->getFiltersCollection();

            $this->media = clone $this->media;

            $this->media->setFiltersCollection($clonedFilters);
        }
    }

    public function __call($method, $parameters)
    {
        return $this->selfOrArgument(
            call_user_func_array([$this->media, $method], $parameters)
        );
    }
}

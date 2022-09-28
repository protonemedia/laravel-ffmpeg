<?php

namespace ProtoneMedia\LaravelFFMpeg\Drivers;

use FFMpeg\FFProbe\DataMapping\Stream;
use Illuminate\Support\Arr;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Filesystem\MediaCollection;

trait InteractsWithMediaStreams
{
    /**
     * Returns an array with all streams.
     *
     * @return array
     */
    public function getStreams(): array
    {
        if (!$this->isAdvancedMedia()) {
            return iterator_to_array($this->media->getStreams());
        }

        return $this->mediaCollection->map(function ($media) {
            return $this->fresh()->open(MediaCollection::make([$media]))->getStreams();
        })->collapse()->all();
    }

    /**
     * Gets the duration of the media from the first stream or from the format.
     */
    public function getDurationInMiliseconds(): int
    {
        $stream = Arr::first($this->getStreams());

        if ($stream->has('duration')) {
            return $stream->get('duration') * 1000;
        }

        $duration = $this->extractDurationAllformat($this->getVideoStream());

        if ($duration) {
            return $duration;
        }
    }

    public function getDurationInSeconds(): int
    {
        return round($this->getDurationInMiliseconds() / 1000);
    }

    /**
     * Gets the first audio streams of the media.
     */
    public function getAudioStream(): ?Stream
    {
        return Arr::first($this->getStreams(), function (Stream $stream) {
            return $stream->isAudio();
        });
    }

    /**
     * Gets the first video streams of the media.
     */
    public function getVideoStream(): ?Stream
    {
        return Arr::first($this->getStreams(), function (Stream $stream) {
            return $stream->isVideo();
        });
    }

    /**
     * Extract video duration when not came standard property
     *
     * @param  Stream $stream
     * @return mixed
     */
    public function extractDurationAllformat(Stream $stream): mixed
    {
        $duration = '';
        $array = $stream->all();
        array_walk_recursive($array, function ($value, $index) use (&$duration) {
            if (strtolower($index) === 'duration') {
                $duration = $value;
            }
        }, $duration);

        $duration = $this->formatDuration($duration);
        $stream->set('duration', $duration);
        return $duration * 1000;
    }

    private function formatDuration(string $duration): mixed
    {
        $nums = explode(':', $duration);
        if (count($nums) === 2) {
            return (int) $nums[0] * 60 + (float) $nums[1];
        }
        if (count($nums) === 3) {
            return (int) $nums[0] * 3600 + (float) $nums[1] * 60 + (float) $nums[2];
        }
    }
}

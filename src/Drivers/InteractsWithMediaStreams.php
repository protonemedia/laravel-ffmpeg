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
     */
    public function getStreams(): array
    {
        if (! $this->isAdvancedMedia()) {
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
            return intval(round($stream->get('duration') * 1000));
        }

        $format = $this->getFormat();

        if ($format->has('duration')) {
            $duration = $format->get('duration');

            if (! blank($duration)) {
                return $format->get('duration') * 1000;
            }
        }

        $duration = $this->extractDurationFromStream($this->getVideoStream() ?? $this->getAudioStream());

        if ($duration !== null) {
            return $duration;
        }

        throw new UnknownDurationException('Could not determine the duration of the media.');
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
     * Extract video duration when it's not a standard property.
     */
    public function extractDurationFromStream(Stream $stream): ?int
    {
        $duration = $this->findDuration($stream->all());

        if ($duration === null) {
            return null;
        }

        return $this->formatDuration($duration) * 1000;
    }

    /**
     * Recursively search for the duration key.
     */
    public function findDuration(array $array): ?string
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                if (! is_null($duration = $this->findDuration($value))) {
                    return $duration;
                }
            }

            if (strtolower($key) === 'duration') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Convert duration string to seconds.
     */
    public function formatDuration(string $duration): float
    {
        $parts = array_map('floatval', explode(':', $duration));
        $count = count($parts);

        return match ($count) {
            2 => $parts[0] * 60 + $parts[1],
            3 => $parts[0] * 3600 + $parts[1] * 60 + $parts[2],
            default => 0.0,
        };
    }
}

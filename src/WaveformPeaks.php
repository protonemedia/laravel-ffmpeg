<?php

namespace ProtoneMedia\LaravelFFMpeg;

use JsonSerializable;

/**
 * The audio peaks of a waveform, in version 1 of the JSON format.
 *
 * Peaks are signed 16-bit extrema stored as channel-major [min, max] pairs. Times start at zero
 * in the preprocessed or clipped output. The sample rate is the selected input track's rate,
 * which decoding explicitly retains.
 */
readonly class WaveformPeaks implements JsonSerializable
{
    /**
     * Create a new waveform peaks instance.
     */
    public function __construct(
        public int $sampleRate,
        public int $inputChannels,
        public int $audioTrack,
        public string $channelMode,
        public int $frames,
        public ?int $samplesPerBucket,
        public array $bucketFrames,
        public array $channels,
    ) {}

    /**
     * Get the duration of the decoded audio in seconds.
     */
    public function duration(): float
    {
        return $this->frames / $this->sampleRate;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'encoding' => 'signed-16-bit',
            'sampleRate' => $this->sampleRate,
            'inputChannels' => $this->inputChannels,
            'audioTrack' => $this->audioTrack,
            'channelMode' => $this->channelMode,
            'frames' => $this->frames,
            'duration' => $this->duration(),
            'samplesPerBucket' => $this->samplesPerBucket,
            'bucketCount' => count($this->bucketFrames),
            'bucketFrames' => $this->bucketFrames,
            'bucketDurations' => array_map(
                fn ($frames): int|float => $frames / $this->sampleRate,
                $this->bucketFrames,
            ),
            'channels' => $this->channels,
        ];
    }

    /**
     * Convert the object into something JSON serializable.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

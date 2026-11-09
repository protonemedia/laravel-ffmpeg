<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg;

use JsonSerializable;

/**
 * Version 1: signed 16-bit extrema, channel-major [min, max] pairs.
 * Times start at zero in the preprocessed/clipped output. Sample rate is the
 * selected input track's rate; decoding explicitly retains that rate.
 */
readonly class WaveformPeaks implements JsonSerializable
{
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

    public function duration(): float
    {
        return $this->frames / $this->sampleRate;
    }

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
            'bucketDurations' => array_map(fn ($frames): int|float => $frames / $this->sampleRate, $this->bucketFrames),
            'channels' => $this->channels,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

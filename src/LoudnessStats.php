<?php

namespace ProtoneMedia\LaravelFFMpeg;

use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;

/** Validated loudnorm JSON. Non-finite readings are represented by null, never zero. */
readonly class LoudnessStats
{
    private function __construct(
        public ?float $inputIntegrated,
        public ?float $inputTruePeak,
        public ?float $inputRange,
        public ?float $inputThreshold,
        public ?float $outputIntegrated,
        public ?float $outputTruePeak,
        public ?float $outputRange,
        public ?float $outputThreshold,
        public ?float $targetOffset,
        public string $mode,
    ) {}

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        $values = [];

        foreach (['input_i', 'input_tp', 'input_lra', 'input_thresh', 'output_i', 'output_tp', 'output_lra', 'output_thresh', 'target_offset'] as $key) {
            $value = is_array($data) ? ($data[$key] ?? null) : null;

            if (is_string($value) && in_array(strtolower($value), ['-inf', 'inf', '+inf', 'nan'], true)) {
                $values[] = null;
            } elseif ((is_string($value) || is_int($value) || is_float($value)) && is_numeric($value) && is_finite((float) $value)) {
                $values[] = (float) $value;
            } else {
                throw new MediaException('Invalid loudness statistics.', 'validation', 'analysis');
            }
        }

        $mode = $data['normalization_type'] ?? null;

        if (! in_array($mode, ['linear', 'dynamic'], true)) {
            throw new MediaException('Invalid loudness normalization mode.', 'validation', 'analysis');
        }

        return new self(...[...$values, $mode]);
    }

    public function silent(): bool
    {
        return $this->inputIntegrated === null && $this->inputTruePeak === null;
    }

    public function finite(): bool
    {
        return ! in_array(null, [$this->inputIntegrated, $this->inputTruePeak, $this->inputRange, $this->inputThreshold, $this->targetOffset], true);
    }

    /** FFmpeg's measured options have narrower ranges than its report fields. */
    public function usableMeasurements(): bool
    {
        return $this->finite()
            && $this->inputIntegrated >= -99 && $this->inputIntegrated <= 0
            && $this->inputTruePeak >= -99 && $this->inputTruePeak <= 99
            && $this->inputRange >= 0 && $this->inputRange <= 99
            && $this->inputThreshold >= -99 && $this->inputThreshold <= 0
            && abs($this->targetOffset) <= 99;
    }
}

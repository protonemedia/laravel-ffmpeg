<?php

namespace ProtoneMedia\LaravelFFMpeg;

use InvalidArgumentException;

/** Encoding overrides for one zero-based output video track. */
class VideoStreamSettings
{
    private array $settings = [];

    public function codec(string $codec): self
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/D', $codec)) {
            throw new InvalidArgumentException('Invalid FFmpeg encoder name.');
        }
        $this->settings['codec'] = $codec;

        return $this;
    }

    public function bitrate(int|string $bitrate): self
    {
        if (! (is_int($bitrate) && $bitrate > 0)
            && (! is_string($bitrate) || ! preg_match('/^[0-9]+(?:\.[0-9]+)?[kKmMgG]$/D', $bitrate) || (float) $bitrate <= 0)) {
            throw new InvalidArgumentException('Bitrates require positive bits per second or an explicit unit string.');
        }
        $this->settings['bitrate'] = $bitrate;

        return $this;
    }

    public function crf(float $crf): self
    {
        if (! is_finite($crf) || $crf < 0) {
            throw new InvalidArgumentException('CRF must be finite and nonnegative.');
        }
        $this->settings['crf'] = $crf;

        return $this;
    }

    public function preset(string $preset): self
    {
        if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $preset)) {
            throw new InvalidArgumentException('Invalid encoder preset.');
        }
        $this->settings['preset'] = $preset;

        return $this;
    }

    /** @internal Snapshot; later callback mutations cannot change the output. */
    public function settings(): array
    {
        return $this->settings;
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\FilterGraph;

/**
 * Configure the encoding overrides of one zero-based output audio track.
 */
class AudioStreamSettings
{
    /**
     * The encoding overrides of the track.
     */
    protected array $settings = [];

    /**
     * Set the encoder of the track.
     */
    public function codec(string $codec): static
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/D', $codec)) {
            throw new InvalidArgumentException('Invalid FFmpeg encoder name.');
        }

        $this->settings['codec'] = $codec;

        return $this;
    }

    /**
     * Set the target bitrate of the track.
     */
    public function bitrate(int|string $bitrate): static
    {
        if ((! is_int($bitrate) || $bitrate <= 0)
            && (! is_string($bitrate)
                || ! preg_match('/^[0-9]+(?:\.[0-9]+)?[kKmMgG]$/D', $bitrate)
                || (float) $bitrate <= 0)) {
            throw new InvalidArgumentException('Bitrates require positive bits per second or an explicit unit string.');
        }

        $this->settings['bitrate'] = $bitrate;

        return $this;
    }

    /**
     * Set the sample rate of the track in hertz.
     */
    public function sampleRate(int $rate): static
    {
        return $this->options(['-ar', (string) $rate]);
    }

    /**
     * Set the number of channels of the track.
     */
    public function channels(int $channels): static
    {
        return $this->options(['-ac', (string) $channels]);
    }

    /**
     * Set the sample format of the track.
     */
    public function sampleFormat(string $format): static
    {
        return $this->options(['-sample_fmt', $format]);
    }

    /**
     * Set the channel layout of the track.
     */
    public function channelLayout(string $layout): static
    {
        return $this->options(['-channel_layout', $layout]);
    }

    /**
     * Set the encoder compression level of the track.
     */
    public function compressionLevel(int $level): static
    {
        return $this->options(['-compression_level', (string) $level]);
    }

    /**
     * Add a filter to the track, after the output audio filters.
     */
    public function filter(string $expression): static
    {
        FilterGraph::validateExpression($expression);
        $this->settings['filters'][] = $expression;

        return $this;
    }

    /**
     * Set curated, scalar encoder options on the track.
     */
    public function options(array $tokens): static
    {
        CommandCompiler::options($tokens, 'output');
        for ($i = 0; $i < count($tokens); $i += 2) {
            $name = substr($tokens[$i], 1);
            if (! in_array($name, ['ar', 'ac', 'sample_fmt', 'channel_layout', 'compression_level'], true)) {
                throw new InvalidArgumentException('Unsupported audio stream setting.');
            }

            $this->settings[$name] = $tokens[$i + 1];
        }

        return $this;
    }

    /**
     * Get a snapshot of the encoding overrides.
     *
     * Queued exports keep this snapshot, not the configuring callback.
     *
     * @internal
     */
    public function settings(): array
    {
        return $this->settings;
    }
}

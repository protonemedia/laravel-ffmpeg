<?php

namespace ProtoneMedia\LaravelFFMpeg;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\FilterGraph;

/** Encoding overrides for one zero-based output audio track. */
class AudioStreamSettings
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

    public function sampleRate(int $rate): self
    {
        return $this->options(['-ar', (string) $rate]);
    }

    public function channels(int $channels): self
    {
        return $this->options(['-ac', (string) $channels]);
    }

    public function sampleFormat(string $format): self
    {
        return $this->options(['-sample_fmt', $format]);
    }

    public function channelLayout(string $layout): self
    {
        return $this->options(['-channel_layout', $layout]);
    }

    public function compressionLevel(int $level): self
    {
        return $this->options(['-compression_level', (string) $level]);
    }

    public function filter(string $expression): self
    {
        FilterGraph::validateExpression($expression);
        $this->settings['filters'][] = $expression;

        return $this;
    }

    public function options(array $tokens): self
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

    /** @internal Snapshot; callbacks are not retained in queued exports. */
    public function settings(): array
    {
        return $this->settings;
    }
}

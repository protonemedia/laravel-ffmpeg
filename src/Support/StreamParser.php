<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

use FFMpeg\FFProbe\DataMapping\Stream;
use Illuminate\Support\Str;

class StreamParser
{
    private Stream $stream;

    public function __construct(Stream $stream)
    {
        $this->stream = $stream;
    }

    public static function new(Stream $stream): StreamParser
    {
        return new static($stream);
    }

    public function getFrameRate(): ?string
    {
        $frameRate = trim($this->stream->get('avg_frame_rate'));

        if (! $frameRate || Str::endsWith($frameRate, '/0')) {
            return null;
        }

        if (Str::contains($frameRate, '/')) {
            $parts = explode('/', $frameRate);

            if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
                return null;
            }

            $frameRate = $parts[0] / $parts[1];
        }

        return is_numeric($frameRate) ? number_format($frameRate, 3, '.', '') : null;
    }
}

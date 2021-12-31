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
        $frameRate = trim(Str::before(optional($this->stream)->get('avg_frame_rate'), "/1"));

        if (!$frameRate || Str::endsWith($frameRate, '/0')) {
            return null;
        }

        if (Str::contains($frameRate, '/')) {
            [$numerator, $denominator] = explode('/', $frameRate);

            $frameRate = $numerator / $denominator;
        }

        return $frameRate ? number_format($frameRate, 3, '.', '') : null;
    }
}

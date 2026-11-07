<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Media;

/** @internal A typed filter whose file arguments are resolved inside a workspace. */
readonly class ResourceFilter
{
    private function __construct(public string $name, public array $resources, private string $template) {}

    public static function drawText(string $text, Media $font, int $size, int $x, int $y, string $color): self
    {
        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0") || $size <= 0
            || ! preg_match('/^(?:[a-zA-Z]+|0x[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?)$/D', $color)) {
            throw new InvalidArgumentException('Draw text requires UTF-8 without NUL bytes, a positive size, and a color name or 0xRRGGBB color.');
        }

        return new self('drawText', [
            'font' => ['type' => 'file', 'source' => $font],
            'textfile' => ['type' => 'text', 'source' => $text],
        ], 'drawtext=fontfile=%s:textfile=%s:expansion=none:reload=0:fontsize='.$size.':x='.$x.':y='.$y.':fontcolor='.$color);
    }

    public static function burnSubtitles(Media $subtitles): self
    {
        if ($subtitles->inputOptions() !== []) {
            throw new InvalidArgumentException('Subtitle resources do not accept media input options.');
        }

        return new self('burnSubtitles', [
            'subtitles' => ['type' => 'subtitle', 'source' => $subtitles],
        ], 'subtitles=filename=%s');
    }

    public function toFilter(callable $resolve): string
    {
        return sprintf($this->template, ...array_map($resolve, array_keys($this->resources)));
    }
}

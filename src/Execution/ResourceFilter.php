<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Media;

/** @internal A typed filter whose file arguments are resolved inside a workspace. */
readonly class ResourceFilter
{
    protected function __construct(public string $name, public array $resources, protected string $template) {}

    public static function drawText(string $text, Media $font, int $size, int $x, int $y, string $color): self
    {
        CommandCompiler::color($color);

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('The draw text must be valid UTF-8.');
        }

        if (str_contains($text, "\0")) {
            throw new InvalidArgumentException('The draw text must not contain NUL bytes.');
        }

        if ($size <= 0) {
            throw new InvalidArgumentException("The draw text size [{$size}] must be positive.");
        }

        return new self('drawText', [
            'font' => ['type' => 'file', 'source' => $font],
            'textfile' => ['type' => 'text', 'source' => $text],
        ], 'drawtext=fontfile=%s:textfile=%s:expansion=none:reload=0:fontsize='.$size.':x='.$x.':y='.$y.':fontcolor='.$color);
    }

    public static function burnSubtitles(Media $subtitles): self
    {
        self::ensureNoInputOptions($subtitles, 'subtitle');

        return new self('burnSubtitles', [
            'subtitles' => ['type' => 'subtitle', 'source' => $subtitles],
        ], 'subtitles=filename=%s');
    }

    public static function lut3d(Media $look): self
    {
        self::ensureNoInputOptions($look, 'LUT');

        return new self('lut3d', ['look' => ['type' => 'file', 'source' => $look]], 'lut3d=file=%s');
    }

    /**
     * Ensure the given resource media has no input options, which file filters cannot apply.
     */
    protected static function ensureNoInputOptions(Media $media, string $resource): void
    {
        if ($media->inputOptions() !== []) {
            throw new InvalidArgumentException("The {$resource} resource must not have media input options.");
        }
    }

    public function toFilter(callable $resolve): string
    {
        return sprintf($this->template, ...array_map($resolve, array_keys($this->resources)));
    }
}

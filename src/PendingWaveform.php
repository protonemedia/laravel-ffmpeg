<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;

class PendingWaveform extends PendingImages
{
    public function __construct(Media $media, string|Filesystem $disk, int $width, int $height, string|array $colors)
    {
        parent::__construct($media, $disk, '[waveform]');

        $colors = is_string($colors) ? [$colors] : $colors;

        if ($width < 1 || $height < 1 || $colors === [] || ! array_is_list($colors)
            || array_any($colors, fn ($color) => ! is_string($color)
                || ! preg_match('/\A(?:[a-zA-Z]+|(?:#|0x)[a-fA-F0-9]{6}(?:[a-fA-F0-9]{2})?)\z/', $color))) {
            throw new InvalidArgumentException('A waveform requires positive dimensions and a list of color names or RGB/RGBA hexadecimal colors.');
        }

        $this->filterComplex('[0:a:0]showwavespic=s='.$width.'x'.$height.':colors='.implode('|', $colors).'[waveform]');
    }

    protected function configuredOutputs(?string $path = null): array
    {
        // The clip trims audio samples before the picture is drawn.
        return $this->unclippedOutputs($path);
    }

    protected function command(array $outputs, array $paths, ?array $outputPaths = null): array
    {
        if ($this->inputSeekOptions() !== []) {
            throw new InvalidArgumentException('Waveform clips require accurate audio trimming.');
        }

        $command = parent::command($outputs, $paths, $outputPaths);
        $filters = [];

        // Audio filters run before the picture; geometry and other video filters
        // run after it. Both belong in the operation-owned conversion graph.
        foreach (['-filter:a', '-filter:v'] as $flag) {
            $index = array_search($flag, $command, true);

            if ($index !== false) {
                $filters[$flag] = $command[$index + 1];
                array_splice($command, $index, 2);
            }
        }

        $audio = $filters['-filter:a'] ?? '';
        $clip = $this->clipSettings();

        if ($clip !== null) {
            $audio .= ($audio === '' ? '' : ',').'atrim=start='.$this->clipTimestamp($clip['start'])
                .':duration='.$this->clipTimestamp($clip['duration']).',asetpts=PTS-STARTPTS';
        }

        $index = array_search('-filter_complex', $command, true) + 1;
        $command[$index] = str_replace('[0:a:0]', '[0:a:0]'.($audio === '' ? '' : $audio.','), $command[$index]);

        if (isset($filters['-filter:v'])) {
            $command[$index] = substr($command[$index], 0, -strlen('[waveform]')).','.$filters['-filter:v'].'[waveform]';
        }

        array_splice($command, -1, 0, ['-frames:v', '1', '-update', '1', '-f', 'image2']);

        return $command;
    }
}

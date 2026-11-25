<?php

namespace ProtoneMedia\LaravelFFMpeg;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;

/**
 * Configure the encoding overrides of one zero-based output video track.
 */
class VideoStreamSettings
{
    /**
     * The filters that drop the HDR10 mastering display and content light level metadata.
     */
    protected const DELETE_HDR_SIDE_DATA = 'sidedata=mode=delete:type=MASTERING_DISPLAY_METADATA,'
        .'sidedata=mode=delete:type=CONTENT_LIGHT_LEVEL';

    /**
     * The transfer characteristics of HDR video, PQ and HLG.
     */
    protected const HDR_TRANSFERS = ['smpte2084', 'arib-std-b67'];

    /**
     * The encoding overrides of the track.
     */
    protected array $settings = [];

    /**
     * Set the encoder of the track.
     */
    public function codec(string $codec): static
    {
        if (! preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_-]*$/D', $codec)) {
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
        if (! CommandCompiler::isBitrate($bitrate)) {
            throw new InvalidArgumentException('Bitrates require positive bits per second or an explicit unit string.');
        }

        $this->settings['bitrate'] = $bitrate;

        return $this;
    }

    /**
     * Set the constant rate factor of the track.
     */
    public function crf(float $crf): static
    {
        if (! is_finite($crf) || $crf < 0) {
            throw new InvalidArgumentException('CRF must be finite and nonnegative.');
        }

        $this->settings['crf'] = $crf;

        return $this;
    }

    /**
     * Set the encoder preset of the track.
     */
    public function preset(string $preset): static
    {
        if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $preset)) {
            throw new InvalidArgumentException('Invalid encoder preset.');
        }

        $this->settings['preset'] = $preset;

        return $this;
    }

    /**
     * Set curated, scalar encoder options on the track, never arbitrary command arguments.
     */
    public function options(array $tokens): static
    {
        CommandCompiler::options($tokens, 'output');

        for ($i = 0; $i < count($tokens); $i += 2) {
            $name = substr($tokens[$i], 1);

            if (! in_array($name, ['preset', 'tune', 'profile', 'level', 'pix_fmt', 'maxrate', 'minrate', 'bufsize',
                'g', 'keyint_min', 'sc_threshold', 'bf', 'refs', 'x264-params', 'x265-params', 'svtav1-params',
                'cpu-used', 'deadline', 'row-mt', 'rc', 'cq', 'rc-lookahead', 'spatial-aq', 'temporal-aq',
                'aq-strength', 'color_primaries', 'color_trc', 'colorspace', 'color_range'], true)) {
                throw new InvalidArgumentException('Unsupported video stream setting.');
            }

            $this->settings[$name] = $tokens[$i + 1];
        }

        return $this;
    }

    /**
     * Signal the color characteristics of the track.
     *
     * This does not transform pixel values, but an SDR transfer drops the HDR10 metadata.
     */
    public function color(string $primaries, string $transfer, string $space, string $range = 'tv'): static
    {
        $this->options([
            '-color_primaries', $primaries,
            '-color_trc', $transfer,
            '-colorspace', $space,
            '-color_range', $range,
        ]);

        // FFmpeg 9 encoders may prefer frame metadata over codec-context defaults, so we
        // will also tag the frames themselves with the same color characteristics.
        $this->settings['filters'][] = self::colorFilter($primaries, $transfer, $space, $range);

        return $this;
    }

    /**
     * Compile the filters that tag frames with the given color characteristics.
     *
     * The values must pass color option validation first.
     *
     * @internal
     */
    public static function colorFilter(string $primaries, string $transfer, string $space, string $range): string
    {
        $primaries = $primaries === 'unspecified' ? 'unknown' : $primaries;
        $transfer = match ($transfer) {
            'gamma22' => 'bt470m', 'gamma28' => 'bt470bg', 'unspecified' => 'unknown', default => $transfer
        };
        $space = match ($space) {
            'rgb' => 'gbr', 'unspecified' => 'unknown', default => $space
        };
        $range = match ($range) {
            'tv' => 'limited', 'pc' => 'full', 'unspecified' => 'unknown', default => $range
        };

        $filter = "setparams=color_primaries={$primaries}:color_trc={$transfer}:colorspace={$space}:range={$range}";

        // The mastering display and content light level metadata of HDR10 only describe PQ
        // and HLG video, so we will drop them for any other transfer, rather than let the
        // encoder signal SDR tags and HDR10 metadata on the same stream.
        if (in_array($transfer, self::HDR_TRANSFERS, true)) {
            return $filter;
        }

        return "{$filter},".self::DELETE_HDR_SIDE_DATA;
    }

    /**
     * Get a snapshot of the encoding overrides.
     *
     * Later changes made by the configuring callback cannot alter the output.
     *
     * @internal
     */
    public function settings(): array
    {
        return $this->settings;
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\AudioStreamSettings;
use ProtoneMedia\LaravelFFMpeg\VideoStreamSettings;

/**
 * Configure the encoders of the output video and audio tracks.
 *
 * @internal
 */
trait ConfiguresEncoders
{
    /**
     * Override the encoding of one output video track.
     *
     * Tracks count in mapped output order, not input stream order.
     */
    public function videoStream(int $track, callable $configure): static
    {
        $this->assertOutputMutable();
        $this->ensureIndexIsNotNegative('video track', $track);

        if (isset($this->videoStreams[$track])) {
            throw new InvalidArgumentException("The video track [{$track}] is already configured.");
        }

        $settings = new VideoStreamSettings;
        $configure($settings);
        $this->videoStreams[$track] = $settings->settings();

        return $this;
    }

    /**
     * Override the encoding of one output audio track.
     *
     * Tracks count in mapped output order, not input stream order.
     */
    public function audioStream(int $track, callable $configure): static
    {
        $this->assertOutputMutable();
        $this->ensureIndexIsNotNegative('audio track', $track);

        if (isset($this->audioStreams[$track])) {
            throw new InvalidArgumentException("The audio track [{$track}] is already configured.");
        }

        $settings = new AudioStreamSettings;
        $configure($settings);
        $this->audioStreams[$track] = $settings->settings();

        return $this;
    }

    /**
     * Set allowed encoder options for every output video track.
     */
    public function videoOptions(array $tokens): static
    {
        $this->assertOutputMutable();
        $this->video = array_replace($this->video, (new VideoStreamSettings)->options($tokens)->settings());

        return $this;
    }

    /**
     * Set allowed encoder options for every output audio track.
     */
    public function audioOptions(array $tokens): static
    {
        $this->assertOutputMutable();
        $this->audio = array_replace($this->audio, (new AudioStreamSettings)->options($tokens)->settings());

        return $this;
    }

    /**
     * Signal the color characteristics of the output video.
     *
     * This does not transform pixel values.
     */
    public function color(string $primaries, string $transfer, string $space, string $range = 'tv'): static
    {
        $this->videoOptions([
            '-color_primaries', $primaries,
            '-color_trc', $transfer,
            '-colorspace', $space,
            '-color_range', $range,
        ]);

        return $this->videoFilter(VideoStreamSettings::colorFilter($primaries, $transfer, $space, $range));
    }

    /**
     * Set the video encoder, bitrate, CRF and preset.
     */
    public function video(
        ?string $codec = null,
        mixed $bitrate = null,
        ?float $crf = null,
        ?string $preset = null,
    ): static {
        $this->assertOutputMutable();
        $this->codec($codec);
        $this->bitrate($bitrate);

        if ($crf !== null && (! is_finite($crf) || $crf < 0)) {
            throw new InvalidArgumentException('CRF must be a finite, nonnegative encoder-specific value.');
        }

        if ($preset !== null && ! preg_match('/^[a-zA-Z0-9_-]+$/D', $preset)) {
            throw new InvalidArgumentException('Invalid encoder preset.');
        }

        $this->video = array_replace($this->video, collect([
            'codec' => $codec, 'bitrate' => $bitrate, 'crf' => $crf, 'preset' => $preset,
        ])->whereNotNull()->all());

        return $this;
    }

    /**
     * Set the audio encoder and bitrate.
     */
    public function audio(?string $codec = null, mixed $bitrate = null): static
    {
        $this->assertOutputMutable();
        $this->codec($codec);
        $this->bitrate($bitrate);
        $this->audio = array_replace($this->audio, collect([
            'codec' => $codec, 'bitrate' => $bitrate,
        ])->whereNotNull()->all());

        return $this;
    }

    /**
     * Ensure the given encoder name is valid.
     */
    protected function codec(?string $codec): void
    {
        if ($codec !== null && ! preg_match('/^[a-zA-Z0-9_]+$/D', $codec)) {
            throw new InvalidArgumentException('Invalid FFmpeg encoder name.');
        }
    }

    /**
     * Ensure the given bitrate is positive and has a valid unit.
     */
    protected function bitrate(mixed $bitrate): void
    {
        if ($bitrate !== null && (! is_int($bitrate) || $bitrate <= 0)
            && (! is_string($bitrate)
                || ! preg_match('/^[0-9]+(?:\.[0-9]+)?[kKmMgG]$/D', $bitrate)
                || (float) $bitrate <= 0)) {
            throw new InvalidArgumentException(
                'Bitrates require a positive integer in bits per second or an explicit unit string, such as 2500k.',
            );
        }
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Stream;

/**
 * Configure the streams an output maps from its inputs.
 *
 * @internal
 */
trait ConfiguresStreamMaps
{
    /**
     * Map the given input stream specifier or graph label to the output.
     */
    public function map(string $specifier): static
    {
        $this->assertOutputMutable();

        if (! preg_match('/^-?[0-9]+(?::[^\x00\r\n?\[\]]+)?\??$/D', $specifier)
            && ! preg_match('/^\[[^\x00\r\n?\[\]]+\]$/D', $specifier)) {
            throw new InvalidArgumentException(
                'Maps must reference a numeric input index or a graph label in brackets.',
            );
        }

        $this->maps[] = $specifier;

        return $this;
    }

    /**
     * Map a video stream from the given input to the output.
     */
    public function mapVideo(
        Stream|int|null $stream = null,
        ?int $input = null,
        ?int $track = null,
        bool $optional = false,
    ): static {
        $this->assertOutputMutable();

        if (is_int($stream)) {
            if ($input !== null) {
                throw new InvalidArgumentException('Specify an input either positionally or by name, not both.');
            }

            $input = $stream;
            $stream = null;
        }

        $this->ensureIndexIsNotNegative('input', $input);
        $this->ensureIndexIsNotNegative('video track', $track);

        if ($stream instanceof Stream && $track !== null) {
            throw new InvalidArgumentException('A probed stream cannot be combined with a track index.');
        }

        if ($stream instanceof Stream) {
            return $this->mapProbedStream($stream, 'video', $input, $optional);
        }

        return $this->map(($input ?? 0).':v:'.($track ?? 0).($optional ? '?' : ''));
    }

    /**
     * Map an audio stream from the given input to the output.
     */
    public function mapAudio(
        Stream|int|null $stream = null,
        ?int $input = null,
        ?int $track = null,
        ?string $language = null,
        bool $optional = false,
    ): static {
        $this->assertOutputMutable();

        if (is_int($stream)) {
            if ($input !== null) {
                throw new InvalidArgumentException('Specify an input either positionally or by name, not both.');
            }

            $input = $stream;
            $stream = null;
        }

        $this->ensureIndexIsNotNegative('input', $input);
        $this->ensureIndexIsNotNegative('audio track', $track);

        if ($track !== null && $language !== null) {
            throw new InvalidArgumentException('Choose an audio track or a language tag, not both.');
        }

        if ($stream instanceof Stream && ($track !== null || $language !== null)) {
            throw new InvalidArgumentException(
                'A probed stream cannot be combined with a track index or language tag.',
            );
        }

        if ($language !== null && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $language)) {
            throw new InvalidArgumentException("The language tag [{$language}] is invalid.");
        }

        $selector = $language === null ? (string) ($track ?? 0) : "m:language:{$language}";

        if ($stream instanceof Stream) {
            return $this->mapProbedStream($stream, 'audio', $input, $optional);
        }

        return $this->map(($input ?? 0).':a:'.$selector.($optional ? '?' : ''));
    }

    /**
     * Map the given probed stream, resolving its input when the command compiles.
     */
    protected function mapProbedStream(Stream $stream, string $type, ?int $input, bool $optional): static
    {
        if ($stream->get('codec_type') !== $type) {
            throw new InvalidArgumentException("The mapped stream is not a {$type} stream.");
        }

        if ($stream->index() === null || $stream->sourceIdentity() === null) {
            throw new InvalidArgumentException('The mapped stream must come from a probe.');
        }

        $this->maps[] = ['stream' => $stream, 'input' => $input, 'optional' => $optional];

        return $this;
    }

    /**
     * Ensure the given input or track index is not negative.
     */
    protected function ensureIndexIsNotNegative(string $subject, ?int $index): void
    {
        if ($index !== null && $index < 0) {
            throw new InvalidArgumentException("The {$subject} [{$index}] must not be negative.");
        }
    }

    /**
     * Omit the audio from the output.
     */
    public function withoutAudio(): static
    {
        $this->assertOutputMutable();

        $this->omitAudio = true;

        return $this;
    }

    /**
     * Omit the video from the output.
     */
    public function withoutVideo(): static
    {
        $this->assertOutputMutable();

        $this->omitVideo = true;

        return $this;
    }
}

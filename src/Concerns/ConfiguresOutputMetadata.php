<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use InvalidArgumentException;

/**
 * Configure the metadata, dispositions and chapters of an output.
 *
 * @internal
 */
trait ConfiguresOutputMetadata
{
    /**
     * Omit the container metadata, and optionally the chapters, from the output.
     */
    public function withoutMetadata(bool $chapters = false): static
    {
        $this->assertOutputMutable();

        $this->metadataSource = -1;

        if ($chapters) {
            $this->chapterSource = -1;
        }

        return $this;
    }

    /**
     * Copy the container metadata from the given input, or omit it when the input is null.
     */
    public function metadataFrom(?int $input): static
    {
        $this->assertOutputMutable();

        if ($input !== null && $input < 0) {
            throw new InvalidArgumentException(
                'The metadata source must be a nonnegative input index or null to omit metadata.',
            );
        }

        $this->metadataSource = $input ?? -1;

        return $this;
    }

    /**
     * Set a container metadata tag on the output.
     */
    public function metadata(string $key, string $value): static
    {
        $this->assertOutputMutable();
        $this->metadataTag($key, $value);

        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * Set a metadata tag on the given output stream.
     */
    public function streamMetadata(string|int $stream, string $key, string $value): static
    {
        $this->assertOutputMutable();
        $this->outputStream($stream);
        $this->metadataTag($key, $value);

        $this->streamMetadata[] = [$stream, $key, $value];

        return $this;
    }

    /**
     * Set the disposition flags of the given output stream.
     *
     * An empty list clears the flags.
     */
    public function disposition(string|int $stream, string|array $flags): static
    {
        $this->assertOutputMutable();
        $this->outputStream($stream);

        if (is_string($flags)) {
            $flags = $flags === '0' ? [] : explode('+', $flags);
        }

        if (! array_is_list($flags) || array_any($flags, function ($flag): bool {
            $supported = [
                'default', 'dub', 'original', 'comment', 'lyrics', 'karaoke', 'forced',
                'hearing_impaired', 'visual_impaired', 'clean_effects', 'attached_pic',
                'timed_thumbnails', 'non_diegetic', 'captions', 'descriptions', 'metadata',
                'dependent', 'still_image', 'multilayer',
            ];

            return ! is_string($flag) || ! in_array($flag, $supported, true);
        })) {
            throw new InvalidArgumentException(
                'Dispositions require supported flag names, or an empty list to clear them.',
            );
        }

        $this->dispositions[] = [$stream, $flags === [] ? '0' : implode('+', array_unique($flags))];

        return $this;
    }

    /**
     * Ensure the given output stream selector is valid.
     */
    protected function outputStream(string|int $stream): void
    {
        if (! preg_match('/^(?:[0-9]+|[vasdt](?::[0-9]+)?)$/D', (string) $stream)) {
            throw new InvalidArgumentException(
                'Select an output stream by absolute index, type, or type and track index.',
            );
        }
    }

    /**
     * Ensure the given metadata key and value are valid.
     */
    protected function metadataTag(string $key, string $value): void
    {
        if (! preg_match('/^[a-zA-Z0-9_.:-]+$/D', $key)) {
            throw new InvalidArgumentException(
                'Metadata keys may contain only letters, numbers, underscores, periods, colons, and dashes.',
            );
        }

        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException('Metadata values must not contain NUL bytes.');
        }
    }

    /**
     * Copy the chapters from the given input, or omit them when the input is null.
     */
    public function chaptersFrom(?int $input): static
    {
        $this->assertOutputMutable();

        if ($input !== null && $input < 0) {
            throw new InvalidArgumentException(
                'The chapter source must be a nonnegative input index or null to omit chapters.',
            );
        }

        $this->chapterSource = $input ?? -1;

        return $this;
    }
}

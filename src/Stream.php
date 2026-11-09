<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Arr;

/**
 * A media stream as reported by FFprobe.
 */
readonly class Stream
{
    /**
     * Create a new stream instance.
     */
    public function __construct(protected array $data, protected ?string $sourceIdentity = null) {}

    /**
     * Get the global index of the stream.
     */
    public function index(): ?int
    {
        $index = $this->get('index');

        return is_int($index) && $index >= 0 ? $index : null;
    }

    /**
     * Get the identity of the media descriptor that produced this probe result.
     *
     * @internal
     */
    public function sourceIdentity(): ?string
    {
        return $this->sourceIdentity;
    }

    /**
     * Get a value from the raw stream data using "dot" notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    /**
     * Get the raw stream data.
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Get the codec type of the stream, such as video or audio.
     */
    public function type(): ?string
    {
        $type = $this->get('codec_type');

        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * Get the codec name of the stream.
     */
    public function codec(): ?string
    {
        $codec = $this->get('codec_name');

        return is_string($codec) && $codec !== '' ? $codec : null;
    }

    /**
     * Get the value of the given stream tag.
     */
    public function tag(string $key): ?string
    {
        $tags = $this->get('tags');
        $value = is_array($tags) ? ($tags[$key] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    /**
     * Get the language tag of the stream.
     */
    public function language(): ?string
    {
        return $this->tag('language');
    }

    /**
     * Determine if the stream has the default disposition.
     */
    public function isDefault(): bool
    {
        return in_array($this->get('disposition.default'), [1, '1', true], true);
    }

    /**
     * Determine if the stream has the forced disposition.
     */
    public function isForced(): bool
    {
        return in_array($this->get('disposition.forced'), [1, '1', true], true);
    }

    /**
     * Determine if the stream is attached cover art.
     */
    public function isCoverArt(): bool
    {
        return in_array($this->get('disposition.attached_pic'), [1, '1', true], true);
    }

    /**
     * Get the number of audio channels.
     */
    public function channels(): ?int
    {
        return $this->positiveInteger('channels');
    }

    /**
     * Get the audio sample rate in hertz.
     */
    public function sampleRate(): ?int
    {
        return $this->positiveInteger('sample_rate');
    }

    /**
     * Get the bitrate in bits per second.
     */
    public function bitrate(): ?int
    {
        return $this->positiveInteger('bit_rate');
    }

    /**
     * Get the given value as a positive integer.
     */
    protected function positiveInteger(string $key): ?int
    {
        $value = $this->get($key);

        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : $value;
    }

    /**
     * Get the width of the video in pixels.
     */
    public function width(): ?int
    {
        $width = $this->get('width');

        return is_numeric($width) && is_finite((float) $width) && $width > 0 ? (int) $width : null;
    }

    /**
     * Get the height of the video in pixels.
     */
    public function height(): ?int
    {
        $height = $this->get('height');

        return is_numeric($height) && is_finite((float) $height) && $height > 0 ? (int) $height : null;
    }

    /**
     * Get the average frame rate in frames per second.
     */
    public function frameRate(): ?float
    {
        $rate = explode('/', (string) $this->get('avg_frame_rate', ''));

        if (count($rate) > 2 || ! is_numeric($rate[0]) || ! is_numeric($rate[1] ?? 1)
            || ($rate[1] ?? 1) <= 0) {
            return null;
        }

        $value = (float) $rate[0] / (float) ($rate[1] ?? 1);

        return is_finite($value) && $value > 0 ? $value : null;
    }
}

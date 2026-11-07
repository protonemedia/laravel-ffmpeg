<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Support\Arr;

readonly class Stream
{
    public function __construct(private array $data, private ?string $sourceIdentity = null) {}

    public function index(): ?int
    {
        $index = $this->get('index');

        return is_int($index) && $index >= 0 ? $index : null;
    }

    /** @internal Identifies the media descriptor that produced this probe result. */
    public function sourceIdentity(): ?string
    {
        return $this->sourceIdentity;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function type(): ?string
    {
        $type = $this->get('codec_type');

        return is_string($type) && $type !== '' ? $type : null;
    }

    public function codec(): ?string
    {
        $codec = $this->get('codec_name');

        return is_string($codec) && $codec !== '' ? $codec : null;
    }

    public function tag(string $key): ?string
    {
        $tags = $this->get('tags');
        $value = is_array($tags) ? ($tags[$key] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    public function language(): ?string
    {
        return $this->tag('language');
    }

    public function isDefault(): bool
    {
        return in_array($this->get('disposition.default'), [1, '1', true], true);
    }

    public function isForced(): bool
    {
        return in_array($this->get('disposition.forced'), [1, '1', true], true);
    }

    public function isCoverArt(): bool
    {
        return in_array($this->get('disposition.attached_pic'), [1, '1', true], true);
    }

    public function channels(): ?int
    {
        return $this->positiveInteger('channels');
    }

    public function sampleRate(): ?int
    {
        return $this->positiveInteger('sample_rate');
    }

    public function bitrate(): ?int
    {
        return $this->positiveInteger('bit_rate');
    }

    private function positiveInteger(string $key): ?int
    {
        $value = $this->get($key);

        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : $value;
    }

    public function width(): ?int
    {
        $width = $this->get('width');

        return is_numeric($width) && is_finite((float) $width) && $width > 0 ? (int) $width : null;
    }

    public function height(): ?int
    {
        $height = $this->get('height');

        return is_numeric($height) && is_finite((float) $height) && $height > 0 ? (int) $height : null;
    }

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

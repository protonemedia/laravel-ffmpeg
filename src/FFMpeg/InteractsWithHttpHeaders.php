<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use Illuminate\Support\Collection;

trait InteractsWithHttpHeaders
{
    /**
     * @var array
     */
    protected $headers = [];

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setHeaders(array $headers = []): self
    {
        $this->headers = $headers;

        return $this;
    }

    /**
     * Searches in the $input array for the key bu the $path, and then
     * prepend the $values in front of that key.
     *
     * @param array $input
     * @param string $path
     * @param array $values
     * @return array
     */
    protected static function mergeBeforePathInput(array $input, string $path, array $values = []): array
    {
        $key = array_search($path, $input) - 1;

        return static::mergeBeforeKey($input, $key, $values);
    }

    /**
     * Prepend the given $values in front of the $key in $input.
     *
     * @param array $input
     * @param integer $key
     * @param array $values
     * @return array
     */
    protected static function mergeBeforeKey(array $input, int $key, array $values = []): array
    {
        return array_merge(
            array_slice($input, 0, $key),
            $values,
            array_slice($input, $key)
        );
    }

    /**
     * Maps the headers into a key-value string for FFmpeg. Returns
     * an array of arguments to pass into the command.
     *
     * @param array $headers
     * @return array
     */
    public static function compileHeaders(array $headers = []): array
    {
        if (empty($headers)) {
            return [];
        }

        $headers = Collection::make($headers)->map(function ($value, $key) {
            return "{$key}: {$value}";
        })->filter()->push("")->implode("\r\n");

        return ['-headers', $headers];
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use Illuminate\Support\Collection;

trait InteractsWithHttpHeaders
{
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

    protected static function mergeBeforePathInput(array $input, string $path, array $values = []): array
    {
        $key = array_search($path, $input) - 1;

        return array_merge(
            array_slice($input, 0, $key),
            $values,
            array_slice($input, $key)
        );
    }

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

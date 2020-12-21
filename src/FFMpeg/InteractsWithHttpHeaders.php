<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use Illuminate\Support\Collection;

trait InteractsWithHttpHeaders
{
    use InteractsWithInputPath;

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

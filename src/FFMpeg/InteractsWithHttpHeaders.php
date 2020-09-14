<?php

namespace ProtoneMedia\LaravelFFMpeg\FFMpeg;

use FFMpeg\Format\FormatInterface;
use Illuminate\Support\Collection;

trait InteractsWithHttpHeaders
{
    protected $headers = [];

    public function setHeaders(array $headers = []): self
    {
        $this->headers = $headers;

        return $this;
    }

    protected function buildCommand(FormatInterface $format, $outputPathfile)
    {
        $command = parent::buildCommand($format, $outputPathfile);

        if (empty($this->headers)) {
            return $command;
        }

        return Collection::make($command)->map(function ($command, $pass) {
            $inputKey = array_search($this->getPathfile(), $command) - 1;

            $first = array_slice($command, 0, $inputKey);
            $last = array_slice($command, $inputKey);

            return array_merge($first, static::compileHeaders($this->headers), $last);
        })->all();
    }

    public static function compileHeaders(array $headers = []): array
    {
        if (empty($headers)) {
            return [];
        }

        $headers = Collection::make($headers)->map(function ($value, $key) {
            return "{$key}: {$value}";
        })->filter()->push("")->implode("\r\n");

        return [
            '-headers',
            $headers,
        ];
    }
}

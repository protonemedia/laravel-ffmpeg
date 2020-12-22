<?php

namespace ProtoneMedia\LaravelFFMpeg\Support;

class ProcessOutput
{
    private $all;
    private $errors;
    private $out;

    public function __construct(array $all, array $errors, array $out)
    {
        $this->all    = $all;
        $this->errors = $errors;
        $this->out    = $out;
    }

    public function all(): array
    {
        return $this->all;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function out(): array
    {
        return $this->out;
    }
}

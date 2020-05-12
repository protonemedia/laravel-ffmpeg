<?php

namespace Pbmedia\LaravelFFMpeg\Drivers;

use Pbmedia\LaravelFFMpeg\Filesystem\MediaCollection;

interface DriverInterface
{
    public function open(MediaCollection $mediaCollection): self;

    public function openAdvanced(MediaCollection $mediaCollection): self;

    public function fresh(): self;

    public function get();

    public function getMediaCollection(): MediaCollection;

    public function getCommand($format = null, $path = null): string;

    public function addFilter(): self;

    public function addBasicFilter($in, $out, ...$arguments): self;

    public function getFilters(): array;

    public function getDurationInMiliseconds(): int;

    public function getWidth(): int;

    public function getHeight(): int;
}

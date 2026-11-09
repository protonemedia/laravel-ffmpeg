<?php

declare(strict_types=1);

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/** @internal The last configured output is a manifest the operation writes in artifacts(); FFmpeg encodes only the outputs before it. */
interface WritesManifest {}

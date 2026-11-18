<?php

namespace ProtoneMedia\LaravelFFMpeg\Pipeline;

/**
 * Marks the last configured output as a manifest the operation writes in artifacts().
 *
 * FFmpeg encodes only the outputs before it.
 *
 * @internal
 */
interface WritesManifest {}

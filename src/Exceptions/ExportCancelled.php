<?php

namespace ProtoneMedia\LaravelFFMpeg\Exceptions;

use RuntimeException;

/**
 * Thrown when an export is cancelled before it completes.
 */
class ExportCancelled extends RuntimeException {}

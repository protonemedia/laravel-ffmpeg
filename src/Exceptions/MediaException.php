<?php

namespace ProtoneMedia\LaravelFFMpeg\Exceptions;

use RuntimeException;

/**
 * A media operation failure, tagged with the phase and category in which it occurred.
 */
class MediaException extends RuntimeException
{
    /**
     * Create a new media exception instance.
     *
     * @param  list<array{disk: ?string, path: string}>  $published  Objects whose writes succeeded. The disk is
     *                                                               null for filesystem objects.
     * @param  array{disk?: ?string, path?: string}  $destination  The destination that failed, when known.
     */
    public function __construct(
        string $message,
        public readonly string $phase,
        public readonly string $category,
        public readonly string $diagnostics = '',
        public readonly array $published = [],
        public readonly array $destination = [],
    ) {
        // We never chain a previous exception here, since native process and adapter
        // exceptions may contain credentials or full commands.
        parent::__construct($message);
    }

    /**
     * Create a new exception for a failure while staging inputs and resources.
     */
    public static function staging(
        string $message,
        string $category,
        string $diagnostics = '',
        array $published = [],
        array $destination = [],
    ): self {
        return new self($message, 'staging', $category, $diagnostics, $published, $destination);
    }

    /**
     * Create a new exception for a failure while probing inputs.
     */
    public static function probing(
        string $message,
        string $category,
        string $diagnostics = '',
        array $published = [],
        array $destination = [],
    ): self {
        return new self($message, 'probing', $category, $diagnostics, $published, $destination);
    }

    /**
     * Create a new exception for a failure during an analysis pass.
     */
    public static function analysis(
        string $message,
        string $category,
        string $diagnostics = '',
        array $published = [],
        array $destination = [],
    ): self {
        return new self($message, 'analysis', $category, $diagnostics, $published, $destination);
    }

    /**
     * Create a new exception for a failure while encoding.
     */
    public static function encoding(
        string $message,
        string $category,
        string $diagnostics = '',
        array $published = [],
        array $destination = [],
    ): self {
        return new self($message, 'encoding', $category, $diagnostics, $published, $destination);
    }

    /**
     * Create a new exception for a failure while validating generated files.
     */
    public static function validation(
        string $message,
        string $category,
        string $diagnostics = '',
        array $published = [],
        array $destination = [],
    ): self {
        return new self($message, 'validation', $category, $diagnostics, $published, $destination);
    }

    /**
     * Create a new exception for a failure while publishing generated files.
     */
    public static function publication(
        string $message,
        string $category,
        string $diagnostics = '',
        array $published = [],
        array $destination = [],
    ): self {
        return new self($message, 'publication', $category, $diagnostics, $published, $destination);
    }

    /**
     * Create a new exception for a failure while serving an HLS response.
     */
    public static function response(
        string $message,
        string $category,
        string $diagnostics = '',
        array $published = [],
        array $destination = [],
    ): self {
        return new self($message, 'response', $category, $diagnostics, $published, $destination);
    }

    /**
     * Create a new exception for a failure while deleting generated files.
     */
    public static function cleanup(
        string $message,
        string $category,
        string $diagnostics = '',
        array $published = [],
        array $destination = [],
    ): self {
        return new self($message, 'cleanup', $category, $diagnostics, $published, $destination);
    }

    /**
     * Create a new exception for a failure while publishing continuous HLS.
     */
    public static function live(
        string $message,
        string $category,
        string $diagnostics = '',
        array $published = [],
        array $destination = [],
    ): self {
        return new self($message, 'live', $category, $diagnostics, $published, $destination);
    }
}

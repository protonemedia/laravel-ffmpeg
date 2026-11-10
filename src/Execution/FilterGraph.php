<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use InvalidArgumentException;

/**
 * A minimal lexer for filter graph labels and resource placeholders.
 *
 * This is not a full FFmpeg filter grammar. FFmpeg itself validates filter syntax.
 *
 * @internal
 */
class FilterGraph
{
    /**
     * Ensure the given filter expression is safe to pass to FFmpeg.
     */
    public static function validateExpression(string $expression): void
    {
        if ($expression === '') {
            throw new InvalidArgumentException('The filter expression must not be empty.');
        }

        if (str_contains($expression, "\0")) {
            throw new InvalidArgumentException('The filter expression must not contain NUL bytes.');
        }

        if (preg_match('~[a-z][a-z0-9+.-]*://~i', $expression)) {
            throw new InvalidArgumentException('The filter expression must not contain network URLs.');
        }
    }

    /**
     * Replace whole, unquoted named resource values with generated relative filenames.
     */
    public static function resources(string $expression, array $resources, array &$used): string
    {
        self::validateExpression($expression);
        $result = [];

        foreach (self::split($expression, ',;') as [$filter, $separator]) {
            $result[] = self::compileFilterResources($filter, $resources, $used).$separator;
        }

        return implode('', $result);
    }

    /**
     * Replace the resource placeholders of a single filter with their paths.
     */
    protected static function compileFilterResources(string $filter, array $resources, array &$used): string
    {
        preg_match('/^(?:\[[^\]]+\])*\s*([a-zA-Z0-9_]+)(?:@[a-zA-Z0-9_]+)?=/', $filter, $name);
        $quoted = false;
        $escaped = false;
        $compiled = '';

        for ($i = 0; $i < strlen($filter); $i++) {
            $character = $filter[$i];

            if (! $escaped && substr($filter, $i, 2) === '{{') {
                [$placeholder, $resource] = self::resolvePlaceholder($filter, $i, $name[1] ?? '', $quoted, $resources);
                $i += strlen($placeholder) - 1;

                $used[$resource] = true;
                $compiled .= $resources[$resource]['path'];

                continue;
            }

            $compiled .= $character;

            if ($escaped) {
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === "'") {
                $quoted = ! $quoted;
            }
        }

        if (str_contains($compiled, '{{') || str_contains($compiled, '}}')) {
            throw new InvalidArgumentException('Invalid resource placeholder.');
        }

        return $compiled;
    }

    /**
     * Validate the placeholder at the given offset and return its text and resource name.
     *
     * @return array{string, string}
     */
    protected static function resolvePlaceholder(
        string $filter,
        int $offset,
        string $filterName,
        bool $quoted,
        array $resources,
    ): array {
        if (! preg_match('/\A\{\{([a-zA-Z][a-zA-Z0-9_]*)\}\}/', substr($filter, $offset), $match)) {
            throw new InvalidArgumentException('Resource placeholders must be identifiers such as {{font}}.');
        }

        if ($quoted) {
            throw new InvalidArgumentException('Resource placeholders must not be quoted.');
        }

        $option = self::placeholderOption($filter, $offset, $filterName);

        if (! isset($resources[$match[1]])) {
            throw new InvalidArgumentException('Resource placeholders must name a defined resource.');
        }

        $resource = $resources[$match[1]];

        if (($filterName === 'subtitles') !== ($resource['type'] === 'subtitle')
            || ($resource['type'] === 'text' && ($filterName !== 'drawtext' || $option !== 'textfile'))) {
            throw new InvalidArgumentException('The resource type does not match the filter file option.');
        }

        if (in_array($filterName, ['drawtext', 'subtitles'], true)) {
            self::ensureResourceOptionsAreCompatible($filter, $filterName, $resource);
        }

        $end = $offset + strlen($match[0]);

        if (isset($filter[$end]) && ! str_contains(':[]', $filter[$end])) {
            throw new InvalidArgumentException('A resource placeholder must occupy the entire option value.');
        }

        return [$match[0], $match[1]];
    }

    /**
     * Ensure the placeholder at the given offset is the value of a supported file option.
     */
    protected static function placeholderOption(string $filter, int $offset, string $filterName): string
    {
        if (! preg_match('/(?:^|[:=])([a-z]+)=$/', substr($filter, 0, $offset), $key)
            || ! in_array($key[1], match ($filterName) {
                'drawtext' => ['fontfile', 'textfile'], 'lut3d' => ['file'], 'subtitles' => ['filename'], default => [],
            }, true)) {
            throw new InvalidArgumentException(
                'Resource placeholders must be the value of a supported filter file option.'
            );
        }

        return $key[1];
    }

    /**
     * Ensure the filter's other options cannot override or expand the given resource.
     */
    protected static function ensureResourceOptionsAreCompatible(
        string $filter,
        string $filterName,
        array $resource,
    ): void {
        $options = self::resourceOptions($filter);

        if ($filterName === 'subtitles' && isset($options['f'])) {
            throw new InvalidArgumentException('The subtitles filter must not combine filename and f.');
        }

        if ($filterName === 'drawtext' && isset($options['textfile'], $options['text'])) {
            throw new InvalidArgumentException('The drawtext filter must not combine textfile and text.');
        }

        if ($resource['type'] === 'text' && (($options['expansion'] ?? null) !== 'none'
            || ($options['reload'] ?? '0') !== '0')) {
            throw new InvalidArgumentException('Text resources require expansion=none and reload=0.');
        }
    }

    /**
     * Get the named options of a resource-bearing filter.
     *
     * Such filters may only use named options, and each option at most once.
     */
    protected static function resourceOptions(string $filter): array
    {
        $arguments = substr($filter, strpos($filter, '=') + 1);
        $arguments = preg_replace('/(?:\[[a-zA-Z_][a-zA-Z0-9_]*\])+\s*$/', '', $arguments);

        $options = [];

        foreach (self::split($arguments, ':') as [$option]) {
            if (! preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)=(.*)$/sD', $option, $match)) {
                throw new InvalidArgumentException('Resource filter options must be named.');
            }

            if (isset($options[$match[1]])) {
                throw new InvalidArgumentException('Resource filter options must not be repeated.');
            }

            $options[$match[1]] = $match[2];
        }

        return $options;
    }

    /**
     * Ensure every graph label has exactly one producer and one consumer.
     *
     * FFmpeg itself validates the filter syntax and pad arity.
     */
    public static function validate(string $expression, int $inputs, array $mapped): void
    {
        $produced = [];
        $consumed = [];

        foreach (self::split($expression, ',;') as [$chain]) {
            $labels = [];
            $quoted = false;
            $escaped = false;

            for ($i = 0; $i < strlen($chain); $i++) {
                $character = $chain[$i];

                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === "'") {
                    $quoted = ! $quoted;
                } elseif (! $quoted && $character === '[') {
                    $end = strpos($chain, ']', $i);

                    if ($end === false) {
                        throw new InvalidArgumentException('Unclosed graph label.');
                    }

                    $labels[] = [substr($chain, $i + 1, $end - $i - 1), $i, $end];
                    $i = $end;
                }
            }

            foreach ($labels as [$label, $start, $end]) {
                $prefix = trim(substr($chain, 0, $start));
                $isInput = preg_match('/^(?:\[[^\]]+\]\s*)*$/D', $prefix);

                if (preg_match('/^(\d+)(?::.+)?$/D', $label, $match)) {
                    if (! $isInput || (int) $match[1] >= $inputs) {
                        throw new InvalidArgumentException('Graph references an invalid input.');
                    }
                } elseif (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $label)) {
                    throw new InvalidArgumentException('Graph labels must be simple identifiers.');
                } elseif ($isInput) {
                    $consumed[$label] = ($consumed[$label] ?? 0) + 1;
                } else {
                    $produced[$label] = ($produced[$label] ?? 0) + 1;
                }
            }
        }

        foreach ($mapped as $label) {
            $consumed[$label] = ($consumed[$label] ?? 0) + 1;
        }

        foreach (array_unique([...array_keys($produced), ...array_keys($consumed)]) as $label) {
            if (($produced[$label] ?? 0) !== 1 || ($consumed[$label] ?? 0) !== 1) {
                throw new InvalidArgumentException(
                    'Each graph label must have one producer and one consumer. Use split or asplit to branch.'
                );
            }
        }
    }

    /**
     * Split the expression at the given separators outside FFmpeg quotes and backslash escapes.
     *
     * @return list<array{string, string}>
     */
    protected static function split(string $expression, string $separators): array
    {
        $parts = [];
        $start = 0;
        $quoted = false;
        $escaped = false;

        for ($i = 0; $i < strlen($expression); $i++) {
            $character = $expression[$i];

            if ($escaped) {
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === "'") {
                $quoted = ! $quoted;
            } elseif (! $quoted && str_contains($separators, $character)) {
                $parts[] = [substr($expression, $start, $i - $start), $character];
                $start = $i + 1;
            }
        }

        if ($quoted || $escaped) {
            throw new InvalidArgumentException('Unterminated filter quote or escape.');
        }

        $parts[] = [substr($expression, $start), ''];

        return $parts;
    }
}

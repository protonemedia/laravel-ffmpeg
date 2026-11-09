<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use InvalidArgumentException;

/** @internal Minimal label/resource lexer, not an FFmpeg filter grammar. */
class FilterGraph
{
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

    /** Replace whole, unquoted named resource values with generated relative filenames. */
    public static function resources(string $expression, array $resources, array &$used): string
    {
        self::validateExpression($expression);
        $result = [];

        foreach (self::split($expression, ',;') as [$filter, $separator]) {
            preg_match('/^(?:\[[^\]]+\])*\s*([a-zA-Z0-9_]+)(?:@[a-zA-Z0-9_]+)?=/', $filter, $name);
            $quoted = false;
            $escaped = false;
            $compiled = '';

            for ($i = 0; $i < strlen($filter); $i++) {
                $character = $filter[$i];

                if (! $escaped && substr($filter, $i, 2) === '{{') {
                    if (! preg_match('/\A\{\{([a-zA-Z][a-zA-Z0-9_]*)\}\}/', substr($filter, $i), $match)) {
                        throw new InvalidArgumentException('Resource placeholders must be identifiers such as {{font}}.');
                    }

                    if ($quoted) {
                        throw new InvalidArgumentException('Resource placeholders must not be quoted.');
                    }

                    if (! preg_match('/(?:^|[:=])([a-z]+)=$/', substr($filter, 0, $i), $key)
                        || ! in_array($key[1], match ($name[1] ?? '') {
                            'drawtext' => ['fontfile', 'textfile'], 'lut3d' => ['file'], 'subtitles' => ['filename'], default => [],
                        }, true)) {
                        throw new InvalidArgumentException('Resource placeholders must be the value of a supported filter file option.');
                    }

                    if (! isset($resources[$match[1]])) {
                        throw new InvalidArgumentException('Resource placeholders must name a defined resource.');
                    }

                    $resource = $resources[$match[1]];

                    if (($name[1] === 'subtitles') !== ($resource['type'] === 'subtitle')
                        || ($resource['type'] === 'text' && ($name[1] !== 'drawtext' || $key[1] !== 'textfile'))) {
                        throw new InvalidArgumentException('The resource type does not match the filter file option.');
                    }

                    if (in_array($name[1], ['drawtext', 'subtitles'], true)) {
                        $options = self::resourceOptions($filter);

                        if ($name[1] === 'subtitles' && isset($options['f'])) {
                            throw new InvalidArgumentException('The subtitles filter must not combine filename and f.');
                        }

                        if ($name[1] === 'drawtext' && isset($options['textfile'], $options['text'])) {
                            throw new InvalidArgumentException('The drawtext filter must not combine textfile and text.');
                        }

                        if ($resource['type'] === 'text' && (($options['expansion'] ?? null) !== 'none'
                            || ($options['reload'] ?? '0') !== '0')) {
                            throw new InvalidArgumentException('Text resources require expansion=none and reload=0.');
                        }
                    }

                    $i += strlen($match[0]) - 1;

                    if (isset($filter[$i + 1]) && ! str_contains(':[]', $filter[$i + 1])) {
                        throw new InvalidArgumentException('A resource placeholder must occupy the entire option value.');
                    }

                    $used[$match[1]] = true;
                    $compiled .= $resource['path'];

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

            $result[] = $compiled.$separator;
        }

        return implode('', $result);
    }

    /** Resource-bearing filters use named, nonduplicate options only. */
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

    /** Check label ownership; FFmpeg validates filter syntax and pad arity. */
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
                throw new InvalidArgumentException('Each graph label must have one producer and one consumer. Use split or asplit to branch.');
            }
        }
    }

    /** Split only outside FFmpeg quotes and backslash escapes. */
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

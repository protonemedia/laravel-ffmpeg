<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use InvalidArgumentException;

/** @internal Minimal label/resource lexer, not an FFmpeg filter grammar. */
class FilterGraph
{
    public static function validateExpression(string $expression): void
    {
        if ($expression === '' || str_contains($expression, "\0") || preg_match('~[a-z][a-z0-9+.-]*://~i', $expression)) {
            throw new InvalidArgumentException('Filters require a nonempty, developer-trusted expression without network URLs.');
        }
    }

    /** Replace whole, unquoted named resource values with generated relative filenames. */
    public static function resources(string $expression, array $resources, array &$used): string
    {
        self::validateExpression($expression);
        $result = [];

        foreach (self::split($expression, ',;') as [$filter, $separator]) {
            preg_match('/^(?:\[[^\]]+\])*\s*([a-zA-Z0-9_]+)(?:@[a-zA-Z0-9_]+)?=/', $filter, $name);
            $quoted = $escaped = false;
            $compiled = '';

            for ($i = 0; $i < strlen($filter); $i++) {
                $character = $filter[$i];

                if (! $escaped && substr($filter, $i, 2) === '{{') {
                    if (! preg_match('/\A\{\{([a-zA-Z][a-zA-Z0-9_]*)\}\}/', substr($filter, $i), $match)
                        || $quoted || ! preg_match('/(?:^|[:=])([a-z]+)=$/', substr($filter, 0, $i), $key)
                        || ! in_array($key[1], match ($name[1] ?? '') {
                            'drawtext' => ['fontfile', 'textfile'], 'lut3d' => ['file'], default => [],
                        }, true)
                        || ! isset($resources[$match[1]])) {
                        throw new InvalidArgumentException('Resources require a known, whole unquoted fontfile, textfile, or lut3d file value.');
                    }

                    $i += strlen($match[0]) - 1;

                    if (isset($filter[$i + 1]) && ! str_contains(':[]', $filter[$i + 1])) {
                        throw new InvalidArgumentException('A resource placeholder must occupy the entire option value.');
                    }

                    $used[$match[1]] = true;
                    $compiled .= $resources[$match[1]];

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

    /** Check label ownership; FFmpeg validates filter syntax and pad arity. */
    public static function validate(string $expression, int $inputs, array $mapped): void
    {
        $produced = $consumed = [];

        foreach (self::split($expression, ',;') as [$chain]) {
            $labels = [];
            $quoted = $escaped = false;

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
    private static function split(string $expression, string $separators): array
    {
        $parts = [];
        $start = 0;
        $quoted = $escaped = false;

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

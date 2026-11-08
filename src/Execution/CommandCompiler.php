<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use InvalidArgumentException;

/** @internal */
class CommandCompiler
{
    /** Compile already resolved, operation-owned inputs and outputs. */
    public static function compile(string $binary, array $inputs, array $outputs, array $globalOptions = []): array
    {
        $command = [$binary, '-hide_banner', '-nostdin', '-nostats', '-y', '-xerror', '-loglevel', 'warning', '-progress', 'pipe:1', ...$globalOptions];

        foreach ($inputs as $input) {
            $command = [...$command, '-protocol_whitelist', $input['protocols'], ...$input['options'], '-i', $input['path']];
        }

        foreach ($outputs as $output) {
            $command = [...$command, ...$output['options'], $output['path']];
        }

        return $command;
    }

    /** Validate flat tokens without allowing caller-defined option arity. */
    public static function options(array $tokens, string $scope): array
    {
        $allowed = match ($scope) {
            'global' => ['filter_threads' => 'positive', 'filter_complex_threads' => 'positive'],
            'input' => ['probesize' => 'positive', 'analyzeduration' => 'unsigned', 'thread_queue_size' => 'positive'],
            'output' => [
                'f' => 'format', 'preset' => 'word', 'tune' => 'word', 'profile' => 'word', 'level' => 'word',
                'pix_fmt' => 'word', 'maxrate' => 'rate', 'minrate' => 'rate', 'bufsize' => 'rate',
                'g' => 'positive', 'keyint_min' => 'positive', 'sc_threshold' => 'unsigned', 'bf' => 'unsigned',
                'refs' => 'positive', 'ar' => 'positive', 'ac' => 'positive', 'movflags' => 'word',
                'fps_mode' => 'fps', 'threads' => 'positive', 'disposition' => 'word', 'streamid' => 'streamid',
                'tag' => 'word', 'q' => 'unsigned', 'r' => 'rate',
                'c' => 'subtitle-codec',
            ],
            default => throw new InvalidArgumentException('Invalid FFmpeg option scope.'),
        };

        if (! array_is_list($tokens) || count($tokens) % 2 !== 0) {
            throw new InvalidArgumentException('Options must be a list of supported option/value pairs.');
        }

        $seen = [];

        for ($i = 0; $i < count($tokens); $i += 2) {
            $flag = $tokens[$i];
            $value = $tokens[$i + 1];

            if (! is_string($flag) || ! preg_match('/^-([a-z_]+)(?::([vasdt])(?::([0-9]+))?)?$/D', $flag, $match)
                || ! isset($allowed[$match[1]]) || ($scope !== 'output' && isset($match[2]))
                || ! is_string($value) || str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new InvalidArgumentException('Unsupported or misplaced FFmpeg option.');
            }

            $name = $match[1];

            if (isset($seen[$flag]) && $name !== 'streamid') {
                throw new InvalidArgumentException('Duplicate FFmpeg option.');
            }

            $valid = match ($allowed[$name]) {
                'positive' => ctype_digit($value) && (float) $value > 0,
                'unsigned' => ctype_digit($value),
                'rate' => (bool) preg_match('/^[0-9]+(?:\.[0-9]+)?(?:[kKmMgG]|\/[1-9][0-9]*)?$/D', $value) && (float) $value > 0,
                'word' => (bool) preg_match('/^[a-zA-Z0-9_.,+\-]+$/D', $value),
                'fps' => in_array($value, ['passthrough', 'cfr', 'vfr', 'auto'], true),
                'format' => ! isset($match[2]) && in_array($value, ['mp4', 'mov', 'matroska', 'webm', 'mpegts', 'mp3', 'adts', 'ogg', 'wav', 'flac', 'avi', 'nut'], true),
                'subtitle-codec' => ($match[2] ?? null) === 's' && (bool) preg_match('/\A[a-zA-Z0-9_]+\z/', $value),
                'streamid' => ! isset($match[2]) && (bool) preg_match('/^[0-9]+:[0-9]+$/D', $value),
            };

            if (! $valid) {
                throw new InvalidArgumentException('Invalid FFmpeg option value.');
            }

            $seen[$flag] = true;
        }

        return $tokens;
    }
}

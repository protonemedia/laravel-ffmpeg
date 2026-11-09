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
            'global' => ['filter_threads' => 'positive', 'filter_complex_threads' => 'positive',
                'init_hw_device' => 'hardware-init', 'filter_hw_device' => 'device-name'],
            'input' => ['probesize' => 'positive', 'analyzeduration' => 'unsigned', 'thread_queue_size' => 'positive',
                'hwaccel' => 'hardware', 'hwaccel_device' => 'device', 'hwaccel_output_format' => 'word', 'ignore_loop' => 'boolean'],
            'output' => [
                'f' => 'format', 'preset' => 'word', 'tune' => 'word', 'profile' => 'word', 'level' => 'word',
                'pix_fmt' => 'word', 'maxrate' => 'rate', 'minrate' => 'rate', 'bufsize' => 'rate',
                'g' => 'positive', 'keyint_min' => 'positive', 'sc_threshold' => 'unsigned', 'bf' => 'unsigned',
                'refs' => 'positive', 'ar' => 'positive', 'ac' => 'positive', 'movflags' => 'word',
                'fps_mode' => 'fps', 'threads' => 'positive', 'disposition' => 'word', 'streamid' => 'streamid',
                'tag' => 'word', 'q' => 'unsigned', 'r' => 'rate',
                'c' => 'subtitle-codec',
                'x264-params' => 'x264', 'x265-params' => 'x265',
                'cpu-used' => 'unsigned', 'deadline' => 'deadline', 'row-mt' => 'boolean',
                'rc' => 'rc', 'cq' => 'quality', 'rc-lookahead' => 'unsigned',
                'spatial-aq' => 'boolean', 'temporal-aq' => 'boolean', 'aq-strength' => 'unsigned',
                'color_primaries' => 'primaries', 'color_trc' => 'transfer', 'colorspace' => 'colorspace', 'color_range' => 'range',
                'compression_level' => 'unsigned', 'sample_fmt' => 'sample-format', 'channel_layout' => 'layout',
                'bsf' => 'bitstream', 'avoid_negative_ts' => 'negative-ts', 'max_muxing_queue_size' => 'positive',
                'muxdelay' => 'decimal', 'muxpreload' => 'decimal', 'flush_packets' => 'boolean',
                'video_track_timescale' => 'positive', 'frag_duration' => 'positive', 'min_frag_duration' => 'positive',
                'cluster_time_limit' => 'positive', 'cluster_size_limit' => 'positive', 'write_crc32' => 'boolean',
            ],
            default => throw new InvalidArgumentException('Invalid FFmpeg option scope.'),
        };

        if (! array_is_list($tokens) || count($tokens) % 2 !== 0) {
            throw new InvalidArgumentException('Options must be a list of supported option/value pairs.');
        }

        $seen = [];
        $devices = [];

        for ($i = 0; $i < count($tokens); $i += 2) {
            $flag = $tokens[$i];
            $value = $tokens[$i + 1];

            if (! is_string($flag) || ! preg_match('/^-([a-z0-9_-]+)(?::([vasdt])(?::([0-9]+))?)?$/D', $flag, $match)
                || ! isset($allowed[$match[1]]) || ($scope !== 'output' && isset($match[2]))
                || ! is_string($value) || str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new InvalidArgumentException('Unsupported or misplaced FFmpeg option.');
            }

            $name = $match[1];

            $videoOnly = ['x264-params', 'x265-params', 'cpu-used', 'deadline', 'row-mt', 'rc', 'cq',
                'rc-lookahead', 'spatial-aq', 'temporal-aq', 'aq-strength', 'color_primaries', 'color_trc', 'colorspace', 'color_range'];
            $audioOnly = ['compression_level', 'sample_fmt', 'channel_layout'];
            $fileOnly = ['avoid_negative_ts', 'muxdelay', 'muxpreload', 'flush_packets', 'video_track_timescale',
                'frag_duration', 'min_frag_duration', 'cluster_time_limit', 'cluster_size_limit', 'write_crc32'];
            if (isset($match[2]) && (in_array($name, $fileOnly, true)
                || (in_array($name, $videoOnly, true) && $match[2] !== 'v')
                || (in_array($name, $audioOnly, true) && $match[2] !== 'a'))) {
                throw new InvalidArgumentException('Unsupported stream scope for FFmpeg option.');
            }

            if (isset($seen[$flag]) && ! in_array($name, ['streamid', 'init_hw_device'], true)) {
                throw new InvalidArgumentException('Duplicate FFmpeg option.');
            }

            $valid = match ($allowed[$name]) {
                'positive' => ctype_digit($value) && (float) $value > 0,
                'unsigned' => ctype_digit($value),
                'boolean' => in_array($value, ['0', '1'], true),
                'decimal' => (bool) preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $value) && is_finite((float) $value),
                'quality' => is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 && (float) $value <= 63,
                'device-name' => (bool) preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*\z/', $value),
                'hardware-init' => (bool) preg_match('/\A(?:cuda|vaapi|qsv|vulkan|videotoolbox|d3d11va)=[a-zA-Z][a-zA-Z0-9_]*(?::(?:[0-9]+|\/dev\/dri\/renderD[0-9]+)|@[a-zA-Z][a-zA-Z0-9_]*)?\z/', $value),
                'x264', 'x265' => self::encoderParameters($value, $allowed[$name]),
                'deadline' => in_array($value, ['best', 'good', 'realtime'], true),
                'rc' => in_array($value, ['constqp', 'vbr', 'cbr'], true),
                'primaries' => in_array($value, ['bt709', 'bt470m', 'bt470bg', 'smpte170m', 'smpte240m', 'film', 'bt2020', 'smpte428', 'smpte431', 'smpte432', 'unspecified'], true),
                'transfer' => in_array($value, ['bt709', 'gamma22', 'gamma28', 'smpte170m', 'smpte240m', 'linear', 'iec61966-2-1', 'bt2020-10', 'bt2020-12', 'smpte2084', 'arib-std-b67', 'unspecified'], true),
                'colorspace' => in_array($value, ['rgb', 'bt709', 'bt470bg', 'smpte170m', 'smpte240m', 'bt2020nc', 'bt2020c', 'unspecified'], true),
                'range' => in_array($value, ['tv', 'pc', 'limited', 'full', 'unspecified'], true),
                'sample-format' => in_array($value, ['u8', 's16', 's32', 's64', 'flt', 'dbl', 'u8p', 's16p', 's32p', 's64p', 'fltp', 'dblp'], true),
                'layout' => in_array($value, ['mono', 'stereo', '2.1', '3.0', '4.0', 'quad', '5.0', '5.1', '5.1(side)', '7.1', '7.1(wide)'], true),
                'bitstream' => ! isset($match[2]) || in_array($match[2], ['v', 'a'], true)
                    ? array_all(explode(',', $value), fn ($filter) => in_array($filter, ['aac_adtstoasc', 'h264_mp4toannexb', 'hevc_mp4toannexb', 'extract_extradata', 'remove_extra', 'vp9_superframe', 'av1_frame_merge', 'null'], true)) : false,
                'negative-ts' => in_array($value, ['auto', 'disabled', 'make_non_negative', 'make_zero'], true),
                'hardware' => in_array($value, ['cuda', 'vaapi', 'qsv', 'vulkan', 'videotoolbox', 'd3d11va', 'dxva2'], true),
                // Device and word values may contain hyphens but never start with one, so no value can pass for an option token.
                'device' => (bool) preg_match('/\A[a-zA-Z0-9_\/.:][a-zA-Z0-9_\/.:\-]*\z/', $value),
                'rate' => (bool) preg_match('/^[0-9]+(?:\.[0-9]+)?(?:[kKmMgG]|\/[1-9][0-9]*)?$/D', $value) && (float) $value > 0,
                'word' => (bool) preg_match('/^[a-zA-Z0-9_.,+][a-zA-Z0-9_.,+\-]*$/D', $value),
                'fps' => in_array($value, ['passthrough', 'cfr', 'vfr', 'auto'], true),
                'format' => ! isset($match[2]) && in_array($value, ['mp4', 'mov', 'matroska', 'webm', 'mpegts', 'mp3', 'adts', 'ogg', 'wav', 'flac', 'avi', 'nut'], true),
                'subtitle-codec' => ($match[2] ?? null) === 's' && (bool) preg_match('/\A[a-zA-Z0-9_]+\z/', $value),
                'streamid' => ! isset($match[2]) && (bool) preg_match('/^[0-9]+:[0-9]+$/D', $value),
            };

            if (! $valid) {
                throw new InvalidArgumentException('Invalid FFmpeg option value.');
            }

            if ($name === 'init_hw_device') {
                preg_match('/=([a-zA-Z][a-zA-Z0-9_]*)(?:[^@]*@([a-zA-Z][a-zA-Z0-9_]*))?/', $value, $device);
                if (isset($devices[$device[1]]) || (isset($device[2]) && ! isset($devices[$device[2]]))) {
                    throw new InvalidArgumentException('Hardware device names must be unique and derived from an earlier declaration.');
                }
                $devices[$device[1]] = true;
            }
            $seen[$flag] = true;
        }

        if ($scope === 'global') {
            $index = array_search('-filter_hw_device', $tokens, true);
            if ($index !== false && ! isset($devices[$tokens[$index + 1]])) {
                throw new InvalidArgumentException('Filter hardware device must name a declared device.');
            }
        }

        return $tokens;
    }

    private static function encoderParameters(string $value, string $encoder): bool
    {
        // No filenames, stats, zones, arbitrary library options, or nested syntax.
        $allowed = ['keyint', 'min-keyint', 'scenecut', 'bframes', 'b-adapt', 'ref', 'rc-lookahead',
            'aq-mode', 'aq-strength', 'qcomp', 'psy-rd', 'deblock', 'vbv-maxrate', 'vbv-bufsize',
            'vbv-init', 'crf', 'qp', 'open-gop', 'repeat-headers', 'aud', 'annexb', 'weightp', 'weightb'];
        if ($encoder === 'x265') {
            $allowed = [...$allowed, 'pools', 'frame-threads', 'wpp', 'sao', 'rect', 'amp', 'rd', 'ctu', 'log-level'];
        }
        $seen = [];
        foreach (explode(':', $value) as $parameter) {
            if (! preg_match('/\A([a-z0-9-]+)=(-?[0-9]+(?:\.[0-9]+)?)\z/', $parameter, $parts)
                || ! in_array($parts[1], $allowed, true) || isset($seen[$parts[1]]) || ! is_finite((float) $parts[2])) {
                return false;
            }
            $seen[$parts[1]] = true;
        }

        return true;
    }
}

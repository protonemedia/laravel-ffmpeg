<?php

namespace ProtoneMedia\LaravelFFMpeg\Execution;

use InvalidArgumentException;

/**
 * Compiles FFmpeg commands and validates the options callers may pass.
 *
 * @internal
 */
class CommandCompiler
{
    /**
     * Compile the FFmpeg command for already resolved, operation-owned inputs and outputs.
     */
    public static function compile(string $binary, array $inputs, array $outputs, array $globalOptions = []): array
    {
        $command = [
            $binary, '-hide_banner', '-nostdin', '-nostats', '-y', '-xerror', '-loglevel', 'warning',
            '-progress', 'pipe:1', ...$globalOptions,
        ];

        foreach ($inputs as $input) {
            $command = [
                ...$command, '-protocol_whitelist', $input['protocols'], ...$input['options'], '-i', $input['path'],
            ];
        }

        foreach ($outputs as $output) {
            $command = [...$command, ...$output['options'], $output['path']];
        }

        return $command;
    }

    /**
     * Ensure the given value is a color name or a hexadecimal RGB or RGBA color.
     *
     * @throws InvalidArgumentException
     */
    public static function color(string $color): string
    {
        if (! preg_match('/\A(?:[a-zA-Z]+|(?:#|0x)[a-fA-F0-9]{6}(?:[a-fA-F0-9]{2})?)\z/', $color)) {
            throw new InvalidArgumentException(
                "The color [{$color}] must be a color name or a hexadecimal RGB or RGBA color."
            );
        }

        return $color;
    }

    /**
     * Parse numeric seconds or HH:MM:SS with up to six fractional digits.
     */
    public static function seconds(float|string $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (! preg_match('/\A([0-9]{2,}):([0-5][0-9]):([0-5][0-9](?:\.[0-9]{1,6})?)\z/', $value, $parts)) {
            throw new InvalidArgumentException(
                'Clip times must be numeric seconds or HH:MM:SS with up to six fractional digits.'
            );
        }

        return (float) $parts[1] * 3600 + (int) $parts[2] * 60 + (float) $parts[3];
    }

    /**
     * Format seconds as an FFmpeg duration with microsecond precision and no trailing zeros.
     */
    public static function timestamp(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 6, '.', ''), '0'), '.');
    }

    /**
     * Validate a flat list of option and value tokens of the given scope.
     *
     * Every option takes exactly one value, so callers cannot change an option's arity.
     */
    public static function options(array $tokens, string $scope): array
    {
        $allowed = self::allowedOptions($scope);

        if (! array_is_list($tokens) || count($tokens) % 2 !== 0) {
            throw new InvalidArgumentException('Options must be a list of supported option/value pairs.');
        }

        $seen = [];
        $devices = [];

        for ($i = 0; $i < count($tokens); $i += 2) {
            $flag = $tokens[$i];
            $value = $tokens[$i + 1];

            [$name, $stream] = self::parseFlag($flag, $allowed, $scope);
            self::ensureSingleLineValue($name, $value);
            self::ensureStreamSpecifierIsSupported($name, $stream);

            if (isset($seen[$flag]) && ! in_array($name, ['streamid', 'init_hw_device'], true)) {
                throw new InvalidArgumentException("The FFmpeg option [{$name}] must not be repeated.");
            }

            if (! self::validValue($allowed[$name], $value, $stream)) {
                throw new InvalidArgumentException("The FFmpeg option [{$name}] has an invalid value.");
            }

            if ($name === 'init_hw_device') {
                $devices = self::declareHardwareDevice($value, $devices);
            }

            $seen[$flag] = true;
        }

        if ($scope === 'global') {
            self::ensureFilterDeviceIsDeclared($tokens, $devices);
        }

        return $tokens;
    }

    /**
     * Get the supported options of the given scope and the rule for each value.
     */
    protected static function allowedOptions(string $scope): array
    {
        return match ($scope) {
            'global' => [
                'filter_threads' => 'positive', 'filter_complex_threads' => 'positive',
                'init_hw_device' => 'hardware-init', 'filter_hw_device' => 'device-name',
            ],
            'input' => [
                'probesize' => 'positive', 'analyzeduration' => 'unsigned', 'thread_queue_size' => 'positive',
                'hwaccel' => 'hardware', 'hwaccel_device' => 'device', 'hwaccel_output_format' => 'word',
                'ignore_loop' => 'boolean',
            ],
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
                'color_primaries' => 'primaries', 'color_trc' => 'transfer', 'colorspace' => 'colorspace',
                'color_range' => 'range',
                'compression_level' => 'unsigned', 'sample_fmt' => 'sample-format', 'channel_layout' => 'layout',
                'bsf' => 'bitstream', 'avoid_negative_ts' => 'negative-ts', 'max_muxing_queue_size' => 'positive',
                'muxdelay' => 'decimal', 'muxpreload' => 'decimal', 'flush_packets' => 'boolean',
                'video_track_timescale' => 'positive', 'frag_duration' => 'positive', 'min_frag_duration' => 'positive',
                'cluster_time_limit' => 'positive', 'cluster_size_limit' => 'positive', 'write_crc32' => 'boolean',
            ],
            default => throw new InvalidArgumentException('Invalid FFmpeg option scope.'),
        };
    }

    /**
     * Parse the given flag into a supported option name and optional stream type.
     *
     * @return array{string, string|null}
     */
    protected static function parseFlag(mixed $flag, array $allowed, string $scope): array
    {
        if (! is_string($flag) || ! preg_match('/^-([a-z0-9_-]+)(?::([vasdt])(?::([0-9]+))?)?$/D', $flag, $match)
            || ! isset($allowed[$match[1]])) {
            throw new InvalidArgumentException("The FFmpeg option is not supported in the {$scope} scope.");
        }

        $name = $match[1];

        if ($scope !== 'output' && isset($match[2])) {
            throw new InvalidArgumentException(
                "The FFmpeg option [{$name}] does not accept a stream specifier in the {$scope} scope."
            );
        }

        return [$name, $match[2] ?? null];
    }

    /**
     * Ensure the given option value is a single-line string.
     */
    protected static function ensureSingleLineValue(string $name, mixed $value): void
    {
        if (! is_string($value)
            || str_contains($value, "\0")
            || str_contains($value, "\n")
            || str_contains($value, "\r")) {
            throw new InvalidArgumentException("The FFmpeg option [{$name}] value must be a single-line string.");
        }
    }

    /**
     * Ensure the given option accepts the stream type it targets.
     */
    protected static function ensureStreamSpecifierIsSupported(string $name, ?string $stream): void
    {
        $videoOnly = ['x264-params', 'x265-params', 'cpu-used', 'deadline', 'row-mt', 'rc', 'cq',
            'rc-lookahead', 'spatial-aq', 'temporal-aq', 'aq-strength', 'color_primaries', 'color_trc', 'colorspace',
            'color_range'];
        $audioOnly = ['compression_level', 'sample_fmt', 'channel_layout'];
        $fileOnly = ['avoid_negative_ts', 'muxdelay', 'muxpreload', 'flush_packets', 'video_track_timescale',
            'frag_duration', 'min_frag_duration', 'cluster_time_limit', 'cluster_size_limit', 'write_crc32'];
        if ($stream !== null && (in_array($name, $fileOnly, true)
            || (in_array($name, $videoOnly, true) && $stream !== 'v')
            || (in_array($name, $audioOnly, true) && $stream !== 'a'))) {
            throw new InvalidArgumentException(
                "The FFmpeg option [{$name}] does not accept the [{$stream}] stream specifier."
            );
        }
    }

    /**
     * Determine if the given value satisfies the option's value rule.
     *
     * @param  (
     *     'positive'|'unsigned'|'boolean'|'decimal'|'quality'|'device-name'|'hardware-init'|'x264'|'x265'
     *     |'deadline'|'rc'|'primaries'|'transfer'|'colorspace'|'range'|'sample-format'|'layout'|'bitstream'
     *     |'negative-ts'|'hardware'|'device'|'rate'|'word'|'fps'|'format'|'subtitle-codec'|'streamid'
     * )  $type
     */
    protected static function validValue(string $type, string $value, ?string $stream): bool
    {
        return match ($type) {
            'positive' => ctype_digit($value) && (float) $value > 0,
            'unsigned' => ctype_digit($value),
            'boolean' => in_array($value, ['0', '1'], true),
            'decimal' => (bool) preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $value) && is_finite((float) $value),
            'quality' => is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 && (float) $value <= 63,
            'device-name' => (bool) preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*\z/', $value),
            'hardware-init' => (bool) preg_match(
                '/\A(?:cuda|vaapi|qsv|vulkan|videotoolbox|d3d11va)=[a-zA-Z][a-zA-Z0-9_]*(?::(?:[0-9]+|\/dev\/dri\/renderD[0-9]+)|@[a-zA-Z][a-zA-Z0-9_]*)?\z/',
                $value,
            ),
            'x264', 'x265' => self::encoderParameters($value, $type),
            'deadline' => in_array($value, ['best', 'good', 'realtime'], true),
            'rc' => in_array($value, ['constqp', 'vbr', 'cbr'], true),
            'primaries' => in_array($value, [
                'bt709', 'bt470m', 'bt470bg', 'smpte170m', 'smpte240m', 'film', 'bt2020', 'smpte428', 'smpte431',
                'smpte432', 'unspecified',
            ], true),
            'transfer' => in_array($value, [
                'bt709', 'gamma22', 'gamma28', 'smpte170m', 'smpte240m', 'linear', 'iec61966-2-1', 'bt2020-10',
                'bt2020-12', 'smpte2084', 'arib-std-b67', 'unspecified',
            ], true),
            'colorspace' => in_array($value, [
                'rgb', 'bt709', 'bt470bg', 'smpte170m', 'smpte240m', 'bt2020nc', 'bt2020c', 'unspecified',
            ], true),
            'range' => in_array($value, ['tv', 'pc', 'limited', 'full', 'unspecified'], true),
            'sample-format' => in_array($value, [
                'u8', 's16', 's32', 's64', 'flt', 'dbl', 'u8p', 's16p', 's32p', 's64p', 'fltp', 'dblp',
            ], true),
            'layout' => in_array($value, [
                'mono', 'stereo', '2.1', '3.0', '4.0', 'quad', '5.0', '5.1', '5.1(side)', '7.1', '7.1(wide)',
            ], true),
            'bitstream' => ($stream === null || in_array($stream, ['v', 'a'], true))
                && array_all(explode(',', $value), fn ($filter): bool => in_array($filter, [
                    'aac_adtstoasc', 'h264_mp4toannexb', 'hevc_mp4toannexb', 'extract_extradata', 'remove_extra',
                    'vp9_superframe', 'av1_frame_merge', 'null',
                ], true)),
            'negative-ts' => in_array($value, ['auto', 'disabled', 'make_non_negative', 'make_zero'], true),
            'hardware' => in_array($value, [
                'cuda', 'vaapi', 'qsv', 'vulkan', 'videotoolbox', 'd3d11va', 'dxva2',
            ], true),
            // Device and word values may contain hyphens but never start with one, so a
            // value can never be mistaken for an option token.
            'device' => (bool) preg_match('/\A[a-zA-Z0-9_\/.:][a-zA-Z0-9_\/.:\-]*\z/', $value),
            'rate' => (bool) preg_match('/^[0-9]+(?:\.[0-9]+)?(?:[kKmMgG]|\/[1-9][0-9]*)?$/D', $value)
                && (float) $value > 0,
            'word' => (bool) preg_match('/^[a-zA-Z0-9_.,+][a-zA-Z0-9_.,+\-]*$/D', $value),
            'fps' => in_array($value, ['passthrough', 'cfr', 'vfr', 'auto'], true),
            'format' => $stream === null && in_array($value, [
                'mp4', 'mov', 'matroska', 'webm', 'mpegts', 'mp3', 'adts', 'ogg', 'wav', 'flac', 'avi', 'nut',
            ], true),
            'subtitle-codec' => $stream === 's' && (bool) preg_match('/\A[a-zA-Z0-9_]+\z/', $value),
            'streamid' => $stream === null && (bool) preg_match('/^[0-9]+:[0-9]+$/D', $value),
        };
    }

    /**
     * Record the hardware device declared by the given value.
     *
     * @param  array<string, true>  $devices
     * @return array<string, true>
     */
    protected static function declareHardwareDevice(string $value, array $devices): array
    {
        preg_match('/=([a-zA-Z][a-zA-Z0-9_]*)(?:[^@]*@([a-zA-Z][a-zA-Z0-9_]*))?/', $value, $device);
        if (isset($devices[$device[1]])) {
            throw new InvalidArgumentException('Hardware device names must be unique.');
        }

        if (isset($device[2]) && ! isset($devices[$device[2]])) {
            throw new InvalidArgumentException('Hardware devices must derive from an earlier declaration.');
        }

        $devices[$device[1]] = true;

        return $devices;
    }

    /**
     * Ensure the filter hardware device names a declared device.
     *
     * @param  array<string, true>  $devices
     */
    protected static function ensureFilterDeviceIsDeclared(array $tokens, array $devices): void
    {
        $index = array_search('-filter_hw_device', $tokens, true);
        if ($index !== false && ! isset($devices[$tokens[$index + 1]])) {
            throw new InvalidArgumentException('Filter hardware device must name a declared device.');
        }
    }

    /**
     * Determine if the given x264 or x265 parameter string uses only allowed numeric parameters.
     */
    protected static function encoderParameters(string $value, string $encoder): bool
    {
        // The allow list deliberately excludes filenames, stats, zones, arbitrary
        // library options and nested syntax.
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

<?php

namespace ProtoneMedia\LaravelFFMpeg\Concerns;

use Closure;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\HlsEncryption;
use ProtoneMedia\LaravelFFMpeg\ExportOutput;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
use ProtoneMedia\LaravelFFMpeg\Media;
use ProtoneMedia\LaravelFFMpeg\PendingExport;
use ProtoneMedia\LaravelFFMpeg\Stream;

/**
 * Validates and rewrites the media playlists and segments the encoder generated.
 */
trait PackagesHlsSegments
{
    /**
     * The MPEG-TS clock rate in ticks per second.
     */
    protected const int MPEG_CLOCK_RATE = 90000;

    /**
     * The number of ticks after which the 33-bit MPEG-TS clock wraps.
     */
    protected const int MPEG_CLOCK_WRAP = 8589934592;

    /**
     * Write the single-segment playlist of a subtitle rendition and return its path.
     */
    protected function writeSubtitlePlaylist(int $index, ExportOutput $output, string $path): string
    {
        $clip = $output->clipSettings();
        $duration = $clip === null
            ? $this->presentationDuration
            : min($clip['duration'], $this->presentationDuration - $clip['start']);

        if ($duration <= 0) {
            throw MediaException::validation('An HLS subtitle rendition has no presentation duration.', 'artifact');
        }

        $path = dirname($path)."/playlist-{$index}.m3u8";

        $this->writePlaylist($path, [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-TARGETDURATION:'.max(1, (int) ceil($duration)),
            '#EXT-X-MEDIA-SEQUENCE:0',
            ...($this->playlistType === 'live' ? [] : ['#EXT-X-PLAYLIST-TYPE:'.strtoupper($this->playlistType)]),
            '#EXTINF:'.sprintf('%.6f', $duration).',',
            "segment-{$index}-00000000.vtt",
            '#EXT-X-ENDLIST',
        ]);

        return $path;
    }

    /**
     * Validate and rewrite one variant playlist, returning its artifacts and stream information.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    protected function packageVariant(int $index, ExportOutput $output, string $path, ?HlsEncryption $encryption): array
    {
        $name = array_keys($this->variants)[$index];
        $lines = $this->playlistLines($path);
        $target = $this->ensurePlaylistIsComplete($lines);
        $media = $this->rewriteMediaLines(
            ['index' => $index, 'output' => $output, 'path' => $path, 'lines' => $lines],
            $encryption,
        );

        $this->ensureSegmentInventoryIsComplete($index, $path, $media);

        $destination = $output->destination()['path'];
        $attributes = $this->streamAttributes($index, $path);
        $info = [
            'peak' => $media['peak'],
            'average' => (int) ceil(8 * $media['bytes'] / $media['total']),
            'attributes' => $attributes,
            'path' => $destination,
            'start' => $media['start'],
            'subtitle' => $media['subtitle'],
            'duration' => $media['total'],
        ];
        $this->provenance[$destination] = [
            'version' => 9,
            'start' => $media['start'],
            'duration' => $media['total'],
            'clip' => $output->clipSettings(),
            'segmentType' => $this->segmentType,
            'subtitle' => $this->isSubtitle($index),
            'streamType' => $media['streamType'],
        ];
        $media['lines'][$target] = '#EXT-X-TARGETDURATION:'.max(1, (int) ceil($media['longest']));

        $this->writePlaylist($path, $this->withKeyTags($media['lines'], $media['keyTags']), $destination, $name);

        $this->variantFiles[$name][$destination] = true;

        return [$media['segments'], $info];
    }

    /**
     * Ensure FFmpeg produced a complete playlist and return the index of its target duration line.
     *
     * @param  list<string>  $lines
     */
    protected function ensurePlaylistIsComplete(array $lines): int
    {
        $targets = array_keys(array_filter(
            $lines,
            fn (string $line): bool => str_starts_with($line, '#EXT-X-TARGETDURATION:'),
        ));
        $types = array_values(array_filter(
            $lines,
            fn (string $line): bool => str_starts_with($line, '#EXT-X-PLAYLIST-TYPE:'),
        ));
        $expectedTypes = [];

        if ($this->playlistType !== 'live') {
            $expectedTypes = ['#EXT-X-PLAYLIST-TYPE:'.strtoupper($this->playlistType)];
        }

        if ($lines[0] !== '#EXTM3U' || end($lines) !== '#EXT-X-ENDLIST'
            || count($targets) !== 1 || count(array_keys($lines, '#EXTM3U', true)) !== 1
            || count(array_keys($lines, '#EXT-X-ENDLIST', true)) !== 1
            || $types !== $expectedTypes) {
            throw MediaException::validation(
                "FFmpeg did not produce a complete {$this->playlistType} playlist.",
                'artifact',
            );
        }

        return $targets[0];
    }

    /**
     * Validate the playlist lines and point their initialization and segment references at the destinations.
     *
     * The given media holds the variant's output index, output, local playlist path and playlist lines.
     *
     * @param  array{index: int, output: ExportOutput, path: string, lines: list<string>}  $media
     * @return array<string, mixed>
     */
    protected function rewriteMediaLines(array $media, ?HlsEncryption $encryption): array
    {
        $media += [
            'segments' => [],
            'duration' => null,
            'longest' => 0,
            'count' => 0,
            'bytes' => 0,
            'total' => 0,
            'peak' => 0,
            'owned' => [],
            'keyTags' => [],
            'init' => null,
            'start' => null,
            'streamType' => 'subtitle',
            'subtitle' => null,
        ];

        foreach ($media['lines'] as $lineIndex => $line) {
            if (str_starts_with($line, '#EXTINF:')) {
                $media['duration'] = $this->extinfDuration($line, $media['duration']);
            } elseif (str_starts_with($line, '#EXT-X-MAP:')) {
                $media = $this->rewriteInitialization($lineIndex, $media);
            } elseif (str_starts_with($line, '#')) {
                if (! preg_match(
                    '/\A(?:#EXTM3U|#EXT-X-VERSION:[0-9]+|#EXT-X-TARGETDURATION:[0-9]+|#EXT-X-MEDIA-SEQUENCE:0|#EXT-X-PLAYLIST-TYPE:(?:VOD|EVENT)|#EXT-X-ENDLIST)\z/',
                    $line,
                )) {
                    throw MediaException::validation('Unexpected HLS playlist directive.', 'artifact');
                }
            } else {
                $media = $this->rewriteSegment($lineIndex, $media, $encryption);
            }
        }

        return $media;
    }

    /**
     * Parse the duration of an EXTINF line that must follow a referenced segment.
     */
    protected function extinfDuration(string $line, ?float $pending): float
    {
        if ($pending !== null
            || ! preg_match('/\A#EXTINF:([0-9]+(?:\.[0-9]+)?),\z/', $line, $match)
            || ! is_finite($duration = (float) $match[1]) || $duration <= 0) {
            throw MediaException::validation('Invalid HLS segment duration.', 'artifact');
        }

        return $duration;
    }

    /**
     * Validate the variant's initialization file and point its map at the destination.
     *
     * @param  array<string, mixed>  $media
     * @return array<string, mixed>
     */
    protected function rewriteInitialization(int $lineIndex, array $media): array
    {
        ['index' => $index, 'output' => $output, 'path' => $path] = $media;

        if ($this->segmentType !== 'fmp4' || $media['init'] !== null || $media['count'] !== 0
            || $media['lines'][$lineIndex] !== "#EXT-X-MAP:URI=\"init-{$index}.mp4\"") {
            throw MediaException::validation(
                'An HLS playlist references an unexpected initialization file.',
                'artifact',
            );
        }

        $name = array_keys($this->variants)[$index];
        $media['init'] = dirname($path)."/init-{$index}.mp4";

        $this->export->validateArtifact($media['init'], 'hls-init');

        $destination = $output->destination();
        $destination['path'] = $this->variantPaths[$name]['init']
            ?? (dirname($destination['path']) === '.' ? '' : dirname($destination['path']).'/').'init.mp4';
        $media['lines'][$lineIndex] = '#EXT-X-MAP:URI="'
            .HlsPresentation::relativeUrl($destination['path'], $output->destination()['path']).'"';
        $this->variantFiles[$name][$destination['path']] = true;
        $media['segments'][] = [
            'path' => $media['init'],
            'output' => $index,
            'role' => 'hls-init',
            'destination' => $destination,
        ];

        return $media;
    }

    /**
     * Validate, encrypt and measure the next segment and point its line at the destination.
     *
     * @param  array<string, mixed>  $media
     * @return array<string, mixed>
     */
    protected function rewriteSegment(int $lineIndex, array $media, ?HlsEncryption $encryption): array
    {
        ['index' => $index, 'output' => $output, 'path' => $path] = $media;
        $name = array_keys($this->variants)[$index];
        $extension = $this->segmentExtension($this->isSubtitle($index));
        $filename = "segment-{$index}-".sprintf('%08d', $media['count']).".{$extension}";

        $this->ensureSegmentIsOwned($lineIndex, $media, $filename);

        $local = dirname($path)."/{$filename}";
        $size = $this->export->validateArtifact($local, 'hls-segment');
        $media['owned'][] = $local;
        $encrypted = $this->isSubtitle($index)
            ? null
            : $encryption?->segment($local, $media['count'], $output->destination()['path'], $name);

        if ($encrypted !== null) {
            $media['keyTags'][$lineIndex] = $encrypted['tag'];
            $size = $this->export->validateArtifact($encrypted['path'], 'hls-segment');
        }

        $media = $this->withSegmentStatistics($media, $size);
        $destination = $this->segmentDestination($output, $name, $extension, $media['count']++);
        $media['lines'][$lineIndex] = HlsPresentation::relativeUrl(
            $destination['path'],
            $output->destination()['path'],
        );
        $this->variantFiles[$name][$destination['path']] = true;
        $probePath = $media['count'] === 1 && $media['init'] !== null
            ? PendingExport::fragmentProbe($media['init'], $local, "{$local}.probe.mp4")
            : $local;

        if ($this->isSubtitle($index)) {
            $media['subtitle'] = $local;
        } elseif ($media['count'] === 1) {
            [$media['streamType'], $media['start']] = $this->probeFirstSegment($probePath);
        }

        $media['segments'][] = [
            'path' => $encrypted['path'] ?? $local,
            'probePath' => $probePath,
            'output' => $index,
            'role' => 'hls-segment',
            'destination' => $destination,
            'probe' => $media['count'] === 1,
        ];
        $media['duration'] = null;

        return $media;
    }

    /**
     * Ensure the playlist line names the next segment FFmpeg wrote, after its duration and initialization.
     *
     * @param  array<string, mixed>  $media
     */
    protected function ensureSegmentIsOwned(int $lineIndex, array $media, string $filename): void
    {
        if ($media['duration'] === null || $media['lines'][$lineIndex] !== $filename
            || ($this->segmentType === 'fmp4' && ! $this->isSubtitle($media['index']) && $media['init'] === null)) {
            throw MediaException::validation(
                'An HLS playlist references an unowned or unordered segment.',
                'artifact',
            );
        }
    }

    /**
     * Add the size and duration of the current segment to the variant's totals.
     *
     * @param  array<string, mixed>  $media
     * @return array<string, mixed>
     */
    protected function withSegmentStatistics(array $media, int $size): array
    {
        $duration = $media['duration'];
        $media['bytes'] += $size;
        $media['total'] += $duration;
        $media['peak'] = max($media['peak'], (int) ceil(8 * $size / $duration));
        $media['longest'] = max($media['longest'], $duration);

        return $media;
    }

    /**
     * Get the destination of the given segment number from the variant's segment pattern.
     */
    protected function segmentDestination(ExportOutput $output, int|string $name, string $extension, int $number): array
    {
        $pattern = $this->variantPaths[$name]['segments']
            ?? dirname($output->destination()['path'])."/segment-%08d.{$extension}";
        $directory = dirname($pattern);
        $destination = $output->destination();
        $destination['path'] = ($directory === '.' ? '' : "{$directory}/")
            .sprintf(basename($pattern), $number);

        return $destination;
    }

    /**
     * Get the segment file extension of a subtitle or media output.
     */
    protected function segmentExtension(bool $subtitle): string
    {
        return match (true) {
            $subtitle => 'vtt',
            $this->segmentType === 'fmp4' => 'm4s',
            default => 'ts',
        };
    }

    /**
     * Probe the first segment for its primary stream type and start time.
     *
     * @return array{0: string, 1: mixed}
     */
    protected function probeFirstSegment(string $path): array
    {
        $metadata = $this->media->probeArtifact($path, $this->workspace);
        $stream = $metadata->video() ?? $metadata->audio();

        return [$metadata->video() instanceof Stream ? 'video' : 'audio', $stream?->get('start_time')];
    }

    /**
     * Ensure the playlist references every segment and initialization file the encoder wrote.
     *
     * @param  array<string, mixed>  $media
     */
    protected function ensureSegmentInventoryIsComplete(int $index, string $path, array $media): void
    {
        $extension = $this->segmentExtension($this->isSubtitle($index));
        $actual = glob(dirname($path)."/segment-{$index}-*.{$extension}") ?: [];

        sort($actual);

        $initializations = collect(glob(dirname($path).'/init-*.mp4') ?: [])
            ->filter(fn (string $file): bool => (bool) preg_match('/\Ainit-'.$index.'(?:-|\.)/', basename($file)))
            ->values()
            ->all();

        if ($media['count'] === 0 || $media['duration'] !== null || $actual !== $media['owned']
            || $initializations !== ($media['init'] === null ? [] : [$media['init']])) {
            throw MediaException::validation('The HLS segment inventory is incomplete.', 'artifact');
        }
    }

    /**
     * Insert each key tag before the segment line it encrypts.
     *
     * @param  array<int, string>  $lines
     * @param  array<int, string>  $keyTags
     * @return list<string>
     */
    protected function withKeyTags(array $lines, array $keyTags): array
    {
        $rendered = [];

        foreach ($lines as $lineIndex => $line) {
            if (isset($keyTags[$lineIndex])) {
                $rendered[] = $keyTags[$lineIndex];
            }

            $rendered[] = $line;
        }

        return $rendered;
    }

    /**
     * Align the subtitle rendition with its group's presentation clock and return the added bandwidth.
     *
     * @param  array<int|string, array<string, mixed>>  $streamInfo
     */
    protected function timestampSubtitleRendition(int|string $name, string $group, array $streamInfo): int
    {
        $starts = [];

        foreach ($this->variants as $variantName => $variant) {
            if (! isset($this->renditions[$variantName]) && $variant->groups()['SUBTITLES'] === $group) {
                $start = $streamInfo[$variantName]['start'];

                if (! is_numeric($start) || ! is_finite((float) $start)) {
                    throw MediaException::validation(
                        'Cannot establish the HLS subtitle presentation clock.',
                        'artifact',
                    );
                }

                $starts[] = (int) round((float) $start * self::MPEG_CLOCK_RATE);
            }
        }

        if ($starts !== [] && max($starts) - min($starts) > 1) {
            throw MediaException::validation(
                'A shared subtitle group requires aligned variant presentation clocks.',
                'artifact',
            );
        }

        $added = $this->timestampWebVtt($streamInfo[$name]['subtitle'], $starts[0] ?? 0);

        return (int) ceil(8 * $added / $streamInfo[$name]['duration']);
    }

    /**
     * Add the MPEG-TS timestamp map to the WebVTT file and get the number of bytes added.
     */
    protected function timestampWebVtt(string $path, int $ticks): int
    {
        $input = @fopen($path, 'rb');
        $output = @fopen("{$path}.timed", 'wb');

        try {
            if ($input === false || $output === false || fgets($input) !== "WEBVTT\n") {
                throw MediaException::validation('Invalid generated WebVTT header.', 'artifact');
            }

            // HLS clocks are unsigned 33-bit MPEG timestamps, even with fragmented MP4, so we
            // will wrap the ticks into that range before writing the timestamp map.
            $ticks = (($ticks % self::MPEG_CLOCK_WRAP) + self::MPEG_CLOCK_WRAP) % self::MPEG_CLOCK_WRAP;
            $header = "X-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:{$ticks}\n";

            if (fwrite($output, "WEBVTT\n{$header}") !== 7 + strlen($header)
                || stream_copy_to_stream($input, $output) !== filesize($path) - 7) {
                throw MediaException::validation('Cannot prepare timestamped WebVTT.', 'filesystem');
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }

            if (is_resource($output)) {
                fclose($output);
            }
        }

        if (! chmod("{$path}.timed", 0600) || ! rename("{$path}.timed", $path)) {
            throw MediaException::validation('Cannot finalize timestamped WebVTT.', 'filesystem');
        }

        return strlen($header);
    }

    /**
     * Read the non-empty lines of a generated playlist within the 16 MiB limit.
     *
     * @return list<string>
     */
    protected function playlistLines(string $path): array
    {
        $bytes = @file_get_contents($path, length: Media::MAX_MANIFEST_BYTES + 1);

        if ($bytes === false || trim($bytes) === '' || strlen($bytes) > Media::MAX_MANIFEST_BYTES || str_contains($bytes, "\0")) {
            throw MediaException::validation(
                'Cannot read the generated HLS playlist within its 16 MiB limit.',
                'artifact',
            );
        }

        return array_values(array_filter(
            explode("\n", str_replace("\r\n", "\n", rtrim($bytes, "\r\n"))),
            fn ($line): bool => $line !== '',
        ));
    }

    /**
     * Write a private playlist after the playlist callback, if any, has added its lines.
     *
     * The callback only runs for playlists published to a destination, so intermediate playlists skip it.
     */
    protected function writePlaylist(
        string $path,
        array $lines,
        ?string $destination = null,
        ?string $variant = null,
    ): void {
        $bytes = implode("\n", $lines)."\n";

        if (strlen($bytes) > Media::MAX_MANIFEST_BYTES) {
            throw MediaException::validation('Finalized HLS playlists must fit within the 16 MiB limit.', 'artifact');
        }

        if ($destination !== null && $this->playlistCallback instanceof Closure) {
            $modified = ($this->playlistCallback)($bytes, $destination, $variant);

            if (! is_string($modified) || strlen($modified) > Media::MAX_MANIFEST_BYTES) {
                throw MediaException::validation(
                    'Playlist hooks must return text within the 16 MiB limit.',
                    'artifact',
                );
            }

            $bytes = str_replace("\r\n", "\n", $modified);
            $modified = array_values(array_filter(
                explode("\n", rtrim($bytes, "\n")),
                fn ($line): bool => $line !== '',
            ));

            if (! str_starts_with($bytes, "#EXTM3U\n")
                || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $bytes)
                || ! preg_match('//u', $bytes)) {
                throw MediaException::validation(
                    'Playlist hooks must return a UTF-8 playlist without control characters.',
                    'artifact',
                );
            }

            $position = 0;

            foreach ($modified as $line) {
                if ($line === ($lines[$position] ?? null)) {
                    $position++;
                } elseif (! str_starts_with($line, '#') || (str_starts_with($line, '#EXT')
                    && (! preg_match('/\A#EXT-X-(?:START|SESSION-DATA|DATERANGE|PROGRAM-DATE-TIME):/', $line)
                        || preg_match('/\bURI\s*=/i', $line)))) {
                    throw MediaException::validation(
                        'Playlist hooks may only add comments and non-resource START, SESSION-DATA, DATERANGE, or PROGRAM-DATE-TIME tags.',
                        'artifact',
                    );
                }
            }

            if ($position !== count($lines)) {
                throw MediaException::validation(
                    'Playlist hooks must preserve generated lines in order.',
                    'artifact',
                );
            }
        }

        if (@file_put_contents($path, $bytes) !== strlen($bytes) || ! chmod($path, 0600)) {
            throw MediaException::validation('Cannot finalize the generated HLS playlist.', 'filesystem');
        }
    }
}

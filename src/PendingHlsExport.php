<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

class PendingHlsExport extends PendingExport
{
    protected string $outputRole = 'hls-playlist';

    private array $variants = [];

    public function __construct(Media $media, string|Filesystem $disk, private readonly float $segmentDuration)
    {
        parent::__construct($media, $disk);

        if (! is_finite($segmentDuration) || $segmentDuration < 0.001 || $segmentDuration > 86400) {
            throw new InvalidArgumentException('HLS segment duration must be between 0.001 and 86400 seconds.');
        }

        $this->video('libx264')->audio('aac');
    }

    public function variant(string $name, callable $configure): self
    {
        $this->assertOutputMutable();

        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/', $name) || isset($this->variants[$name])) {
            throw new InvalidArgumentException('HLS variant names must be unique simple path components.');
        }

        $variant = new HlsVariant;
        $configure($variant);
        $this->variants[$name] = $variant;

        return $this;
    }

    public function output(string $path, callable $configure): self
    {
        throw new InvalidArgumentException('Configure HLS outputs with variant(), not output().');
    }

    protected function configuredOutputs(?string $path = null): array
    {
        if ($this->variants === []) {
            throw new InvalidArgumentException('An HLS export requires at least one variant.');
        }

        $master = (new ExportOutput($path ?? 'master.m3u8', $this->filesystem()))->configure(array_replace(
            $this->outputConfiguration(), ['maps' => [], 'omitAudio' => false, 'omitVideo' => false],
        ));
        $path = $master->destination()['path'];

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'm3u8') {
            throw new InvalidArgumentException('The HLS master destination must have an m3u8 extension.');
        }

        $directory = dirname($path) === '.' ? '' : dirname($path).'/';
        $outputs = [];
        foreach ($this->variants as $name => $variant) {
            $outputs[] = (new ExportOutput($directory.$name.'/index.m3u8', $this->filesystem()))->configure($variant->inherit($this->outputConfiguration()));
        }
        $outputs[] = $master;

        return $outputs;
    }

    public function save(?string $path = null): HlsExportResult
    {
        if ($path === null) {
            throw new InvalidArgumentException('An HLS export requires a master playlist destination.');
        }

        $outputs = $this->configuredOutputs($path);
        $this->inspect();
        foreach ($this->variants as $variant) {
            $variant->freeze();
        }

        $result = parent::save($path);

        return new HlsExportResult($result->files()->all(), $result->processes()->all(),
            array_combine(array_keys($this->variants), array_map(fn ($output) => $output->destination()['path'], array_slice($outputs, 0, -1))));
    }

    protected function command(array $outputs, array $paths, ?array $outputPaths = null): array
    {
        $encoding = array_slice($outputs, 0, -1);
        foreach ($encoding as $output) {
            if (in_array('-f', $output->outputConfiguration()['outputOptions'], true)) {
                throw new InvalidArgumentException('HLS owns the output muxer.');
            }
        }

        $command = parent::command($encoding, $paths, $outputPaths);
        $result = [];
        foreach ($command as $token) {
            $index = array_search($token, $outputPaths ?? array_map(fn ($i) => '[output:'.$i.']', array_keys($encoding)), true);
            if ($index !== false) {
                $settings = $encoding[$index]->outputConfiguration();
                if (! $settings['omitVideo'] && ($settings['video']['codec'] ?? null) !== 'copy') {
                    array_push($result, '-force_key_frames:v', 'expr:gte(t,n_forced*'.$this->clipTimestamp($this->segmentDuration).')', '-flags:v', '+cgop');
                }
                array_push($result, '-f', 'hls', '-hls_time', $this->clipTimestamp($this->segmentDuration),
                    '-hls_list_size', '0', '-hls_playlist_type', 'vod', '-hls_segment_type', 'mpegts', '-start_number', '0',
                    '-hls_segment_filename', 'segment-'.$index.'-%08d.ts', '-master_pl_name', 'master-'.$index.'.m3u8');
            }
            $result[] = $token;
        }

        return $result;
    }

    protected function outputPaths(array $outputs, Workspace $workspace): array
    {
        return array_map(fn ($index) => $workspace->path.'/playlist-'.$index.'.m3u8', array_keys($outputs));
    }

    protected function artifacts(array $outputs, array $paths): array
    {
        $segments = $playlists = [];
        $masterLines = ['#EXTM3U', '#EXT-X-VERSION:3'];
        foreach (array_slice($outputs, 0, -1) as $index => $output) {
            $lines = $this->playlistLines($paths[$index]);
            $targets = array_keys(array_filter($lines, fn ($line) => str_starts_with($line, '#EXT-X-TARGETDURATION:')));
            if ($lines[0] !== '#EXTM3U' || end($lines) !== '#EXT-X-ENDLIST'
                || count($targets) !== 1 || count(array_keys($lines, '#EXTM3U', true)) !== 1
                || count(array_keys($lines, '#EXT-X-ENDLIST', true)) !== 1
                || count(array_keys($lines, '#EXT-X-PLAYLIST-TYPE:VOD', true)) !== 1) {
                throw new MediaException('FFmpeg did not produce a complete VOD playlist.', Progress::VALIDATION, 'artifact');
            }

            $duration = null;
            $longest = 0;
            $count = $bytes = $total = $peak = 0;
            $owned = [];
            foreach ($lines as &$line) {
                if (str_starts_with($line, '#EXTINF:')) {
                    if ($duration !== null || ! preg_match('/\A#EXTINF:([0-9]+(?:\.[0-9]+)?),\z/', $line, $match)
                        || ! is_finite($duration = (float) $match[1]) || $duration <= 0) {
                        throw new MediaException('Invalid HLS segment duration.', Progress::VALIDATION, 'artifact');
                    }
                } elseif (str_starts_with($line, '#')) {
                    if (! preg_match('/\A(?:#EXTM3U|#EXT-X-VERSION:[0-9]+|#EXT-X-TARGETDURATION:[0-9]+|#EXT-X-MEDIA-SEQUENCE:0|#EXT-X-PLAYLIST-TYPE:VOD|#EXT-X-ENDLIST)\z/', $line)) {
                        throw new MediaException('Unexpected HLS playlist directive.', Progress::VALIDATION, 'artifact');
                    }
                } else {
                    $filename = 'segment-'.$index.'-'.sprintf('%08d', $count).'.ts';
                    if ($duration === null || $line !== $filename) {
                        throw new MediaException('An HLS playlist references an unowned or unordered segment.', Progress::VALIDATION, 'artifact');
                    }
                    $local = dirname($paths[$index]).'/'.$filename;
                    $size = $this->validateArtifact($local, 'hls-segment');
                    $owned[] = $local;
                    $bytes += $size;
                    $total += $duration;
                    $peak = max($peak, (int) ceil(8 * $size / $duration));
                    $longest = max($longest, $duration);
                    $destination = $output->destination();
                    $line = 'segment-'.sprintf('%08d', $count++).'.ts';
                    $destination['path'] = dirname($destination['path']).'/'.$line;
                    $segments[] = ['path' => $local, 'output' => $index, 'role' => 'hls-segment', 'destination' => $destination, 'probe' => $count === 1];
                    $duration = null;
                }
            }
            unset($line);
            $actual = glob(dirname($paths[$index]).'/segment-'.$index.'-*.ts') ?: [];
            sort($actual);
            if ($count === 0 || $duration !== null || $actual !== $owned) {
                throw new MediaException('The HLS segment inventory is incomplete.', Progress::VALIDATION, 'artifact');
            }

            $info = $this->playlistLines(dirname($paths[$index]).'/master-'.$index.'.m3u8');
            $attributes = '';
            foreach ($info as $line) {
                if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                    $attributes = trim(preg_replace('/(?:^|,)(?:AVERAGE-)?BANDWIDTH=[0-9]+/', '', substr($line, 18)), ',');
                }
            }
            $masterLines[] = '#EXT-X-STREAM-INF:BANDWIDTH='.$peak.',AVERAGE-BANDWIDTH='.(int) ceil(8 * $bytes / $total)
                .($attributes === '' ? '' : ','.$attributes);
            $masterLines[] = array_keys($this->variants)[$index].'/index.m3u8';
            $lines[$targets[0]] = '#EXT-X-TARGETDURATION:'.max(1, (int) ceil($longest));
            $this->writePlaylist($paths[$index], $lines);
            $playlists[] = ['path' => $paths[$index], 'output' => $index, 'role' => 'hls-playlist', 'destination' => $output->destination()];
        }

        $master = array_key_last($outputs);
        $this->writePlaylist($paths[$master], $masterLines);
        $artifacts = [...$segments, ...$playlists, [
            'path' => $paths[$master], 'output' => $master, 'role' => 'hls-master', 'destination' => $outputs[$master]->destination(),
        ]];
        $this->validateDestinations(array_map(fn ($artifact) => new ExportOutput($artifact['destination']['path'], $outputs[$artifact['output']]->filesystem()), $artifacts));

        return $artifacts;
    }

    private function playlistLines(string $path): array
    {
        $bytes = @file_get_contents($path, length: 16777217);
        if ($bytes === false || trim($bytes) === '' || strlen($bytes) > 16777216 || str_contains($bytes, "\0")) {
            throw new MediaException('Cannot read the generated HLS playlist within its 16 MiB limit.', Progress::VALIDATION, 'artifact');
        }

        return array_values(array_filter(explode("\n", str_replace("\r\n", "\n", rtrim($bytes, "\r\n"))), fn ($line) => $line !== ''));
    }

    private function writePlaylist(string $path, array $lines): void
    {
        $bytes = implode("\n", $lines)."\n";
        if (@file_put_contents($path, $bytes) !== strlen($bytes) || ! chmod($path, 0600)) {
            throw new MediaException('Cannot finalize the generated HLS playlist.', Progress::VALIDATION, 'filesystem');
        }
    }
}

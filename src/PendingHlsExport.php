<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

class PendingHlsExport extends PendingExport
{
    protected string $outputRole = 'hls-playlist';

    private array $variants = [];

    private array $variantPaths = [];

    private array $variantFiles = [];

    private ?Closure $playlistCallback = null;

    public function __construct(Media $media, string|Filesystem $disk, private readonly float $segmentDuration)
    {
        parent::__construct($media, $disk);

        if (! is_finite($segmentDuration) || $segmentDuration < 0.001 || $segmentDuration > 86400) {
            throw new InvalidArgumentException('HLS segment duration must be between 0.001 and 86400 seconds.');
        }

        $this->video('libx264')->audio('aac');
    }

    public function variant(string $name, callable $configure, ?string $playlist = null, ?string $segments = null): self
    {
        $this->assertOutputMutable();

        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/', $name) || isset($this->variants[$name])) {
            throw new InvalidArgumentException('HLS variant names must be unique simple path components.');
        }

        $paths = compact('playlist', 'segments');
        foreach ($paths as $type => $path) {
            if ($path === null) {
                continue;
            }
            $path = (new ExportOutput($path, $this->filesystem()))->destination()['path'];
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== ($type === 'playlist' ? 'm3u8' : 'ts')
                || ($type === 'segments' && (! preg_match('/\A[^%]*%(?:0[1-9])?d[^%]*\z/', $path)
                    || str_contains(dirname($path), '%')))) {
                throw new InvalidArgumentException('HLS paths require an m3u8 playlist or a ts filename with one %d or %01d through %09d placeholder.');
            }
            $paths[$type] = $path;
        }

        $variant = new HlsVariant;
        $configure($variant);
        $this->variants[$name] = $variant;
        $this->variantPaths[$name] = $paths;

        return $this;
    }

    public function playlistUsing(callable $callback): self
    {
        $this->assertOutputMutable();
        $this->playlistCallback = Closure::fromCallable($callback);

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
            $outputs[] = (new ExportOutput($this->variantPaths[$name]['playlist'] ?? $directory.$name.'/index.m3u8', $this->filesystem()))->configure($variant->inherit($this->outputConfiguration()));
        }
        $outputs[] = $master;

        return $outputs;
    }

    public function save(?string $path = null): HlsExportResult
    {
        $this->assertOutputMutable();
        if ($path === null) {
            throw new InvalidArgumentException('An HLS export requires a master playlist destination.');
        }

        $this->configuredOutputs($path);
        $this->inspect();
        foreach ($this->variants as $variant) {
            $variant->freeze();
        }

        $this->variantFiles = [];
        $result = parent::save($path);

        return new HlsExportResult($result->files()->all(), $result->processes()->all(), $this->variantFiles);
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
            $name = array_keys($this->variants)[$index];
            $pattern = $this->variantPaths[$name]['segments'] ?? dirname($output->destination()['path']).'/segment-%08d.ts';
            $directory = dirname($pattern);
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
                    $destination['path'] = ($directory === '.' ? '' : $directory.'/').sprintf(basename($pattern), $count++);
                    $line = $this->relativeUrl($destination['path'], $output->destination()['path']);
                    $this->variantFiles[$name][$destination['path']] = true;
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
            $masterLines[] = $this->relativeUrl($output->destination()['path'], $outputs[array_key_last($outputs)]->destination()['path']);
            $lines[$targets[0]] = '#EXT-X-TARGETDURATION:'.max(1, (int) ceil($longest));
            $this->writePlaylist($paths[$index], $lines, $output->destination()['path'], $name);
            $this->variantFiles[$name][$output->destination()['path']] = true;
            $playlists[] = ['path' => $paths[$index], 'output' => $index, 'role' => 'hls-playlist', 'destination' => $output->destination()];
        }

        $master = array_key_last($outputs);
        $this->writePlaylist($paths[$master], $masterLines, $outputs[$master]->destination()['path'], null);
        $artifacts = [...$segments, ...$playlists, [
            'path' => $paths[$master], 'output' => $master, 'role' => 'hls-master', 'destination' => $outputs[$master]->destination(),
        ]];
        $this->validateDestinations(array_map(fn ($artifact) => new ExportOutput($artifact['destination']['path'], $outputs[$artifact['output']]->filesystem()), $artifacts));

        return $artifacts;
    }

    private function relativeUrl(string $target, string $playlist): string
    {
        $from = dirname($playlist) === '.' ? [] : explode('/', dirname($playlist));
        $to = explode('/', $target);
        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return implode('/', [...array_fill(0, count($from), '..'), ...array_map('rawurlencode', $to)]);
    }

    private function playlistLines(string $path): array
    {
        $bytes = @file_get_contents($path, length: 16777217);
        if ($bytes === false || trim($bytes) === '' || strlen($bytes) > 16777216 || str_contains($bytes, "\0")) {
            throw new MediaException('Cannot read the generated HLS playlist within its 16 MiB limit.', Progress::VALIDATION, 'artifact');
        }

        return array_values(array_filter(explode("\n", str_replace("\r\n", "\n", rtrim($bytes, "\r\n"))), fn ($line) => $line !== ''));
    }

    private function writePlaylist(string $path, array $lines, string $destination, ?string $variant): void
    {
        $bytes = implode("\n", $lines)."\n";
        if ($this->playlistCallback !== null) {
            $modified = ($this->playlistCallback)($bytes, $destination, $variant);
            if (! is_string($modified) || strlen($modified) > 16777216) {
                throw new MediaException('Playlist hooks must return text within the 16 MiB limit.', Progress::VALIDATION, 'artifact');
            }
            $bytes = str_replace("\r\n", "\n", $modified);
            $modified = array_values(array_filter(explode("\n", rtrim($bytes, "\n")), fn ($line) => $line !== ''));
            if (! str_starts_with($bytes, "#EXTM3U\n") || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $bytes) || ! preg_match('//u', $bytes)) {
                throw new MediaException('Playlist hooks must return a UTF-8 playlist without control characters.', Progress::VALIDATION, 'artifact');
            }
            $position = 0;
            foreach ($modified as $line) {
                if ($line === ($lines[$position] ?? null)) {
                    $position++;
                } elseif (! str_starts_with($line, '#') || (str_starts_with($line, '#EXT')
                    && (! preg_match('/\A#EXT-X-(?:START|SESSION-DATA|DATERANGE|PROGRAM-DATE-TIME):/', $line) || preg_match('/\bURI\s*=/i', $line)))) {
                    throw new MediaException('Playlist hooks may only add comments and non-resource START, SESSION-DATA, DATERANGE, or PROGRAM-DATE-TIME tags.', Progress::VALIDATION, 'artifact');
                }
            }
            if ($position !== count($lines)) {
                throw new MediaException('Playlist hooks must preserve generated lines in order.', Progress::VALIDATION, 'artifact');
            }
        }
        if (@file_put_contents($path, $bytes) !== strlen($bytes) || ! chmod($path, 0600)) {
            throw new MediaException('Cannot finalize the generated HLS playlist.', Progress::VALIDATION, 'filesystem');
        }
    }
}

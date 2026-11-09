<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ItemNotFoundException;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPlaylist;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
use RuntimeException;
use Throwable;

/** Compose trusted, completed v9 inventories. This never concatenates or rewrites media. */
class PendingHlsMerge
{
    private ?string $disk = null;

    public function __construct(private readonly array $presentations)
    {
        if (count($presentations) < 2) {
            throw new InvalidArgumentException('HLS composition requires at least two completed presentations.');
        }
    }

    public function toDisk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    public function save(string $path): HlsExportResult
    {
        $files = $keys = $variants = $provenance = $lines = $destinations = [];
        $clock = null;
        $clocks = $sizes = $timelines = $paths = [];
        $diskName = $this->disk;
        $version = 3;
        foreach (array_values($this->presentations) as $index => $input) {
            $label = array_keys($this->presentations)[$index];
            if (is_string($label) && ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/', $label)) {
                throw new InvalidArgumentException('Composition aliases must be simple names.');
            }
            $presentation = match (true) {
                $input instanceof HlsExportResult => $input->playback(),
                $input instanceof HlsPresentation => $input,
                is_array($input) => HlsPresentation::fromArray($input),
                default => throw new InvalidArgumentException('Compose only trusted v9 presentation inventories.'),
            };
            $inventory = $presentation->toArray();
            $master = $this->owned(fn () => $presentation->file());
            if ($master['role'] !== 'hls-master' || $inventory['variants'] === []) {
                throw new InvalidArgumentException('Composition requires a v9 master with variant provenance.');
            }
            $diskName ??= $master['disk'];
            foreach ($inventory['files'] as $file) {
                $id = HlsPresentation::id($file['disk'], $file['path']);
                if (isset($destinations[$id])) {
                    throw new InvalidArgumentException('Composed inventories must have disjoint destinations.');
                }
                $destinations[$id] = true;
                $paths[$file['disk']][] = $file['path'];
                if ($file['role'] !== 'hls-key' && $file['disk'] !== $diskName) {
                    throw new InvalidArgumentException('Composed playlists and media must share the destination disk.');
                }
                $storage = Storage::disk($file['disk']);
                $sizes[$id] = $storage->exists($file['path']) ? $storage->size($file['path']) : 0;
                if ($sizes[$id] <= 0 || ($file['role'] === 'hls-key' && $sizes[$id] !== 16)) {
                    throw new InvalidArgumentException('A completed HLS inventory artifact is missing or invalid.');
                }
                if ($file['role'] === 'hls-master') {
                    continue;
                }
                if ($file['role'] === 'hls-playlist') {
                    $metadata = $inventory['provenance'][$file['path']] ?? [];
                    if (($metadata['version'] ?? null) !== 9 || ! is_numeric($metadata['duration'] ?? null)
                        || ! is_finite((float) $metadata['duration']) || $metadata['duration'] <= 0
                        || ! in_array($metadata['segmentType'] ?? null, ['mpegts', 'fmp4'], true)) {
                        throw new InvalidArgumentException('Composition requires retained v9 VOD clock provenance.');
                    }
                    $text = $this->owned(fn () => $presentation->render($file, fn ($id) => 'owned/'.$id));
                    preg_match_all('/^#EXTINF:([0-9]+(?:\.[0-9]+)?),/m', $text, $durations);
                    if (substr_count($text, '#EXT-X-ENDLIST') !== 1 || substr_count($text, '#EXT-X-PLAYLIST-TYPE:VOD') !== 1
                        || str_contains($text, '#EXT-X-DISCONTINUITY') || str_contains($text, '#EXT-X-BYTERANGE')
                        || $durations[1] === [] || abs(array_sum($durations[1]) - $metadata['duration']) > 0.00001) {
                        throw new InvalidArgumentException('Composition requires unchanged completed VOD playlists.');
                    }
                    $clock ??= $metadata;
                    if ($metadata['segmentType'] !== $clock['segmentType'] || $metadata['clip'] !== $clock['clip']
                        || abs($metadata['duration'] - $clock['duration']) > 0.1) {
                        throw new InvalidArgumentException('Composed VOD presentations require compatible packaging, clips, and duration.');
                    }
                    if (! ($metadata['subtitle'] ?? false)) {
                        if (! is_numeric($metadata['start'] ?? null) || ! is_finite((float) $metadata['start'])) {
                            throw new InvalidArgumentException('Cannot establish the HLS presentation clock.');
                        }
                        $streamType = $metadata['streamType'] ?? '';
                        if (! in_array($streamType, ['video', 'audio'], true)) {
                            throw new InvalidArgumentException('Composition requires retained stream clock types.');
                        }
                        $clocks[$streamType] ??= $metadata['start'];
                        $timeline = [];
                        $elapsed = 0.0;
                        foreach ($durations[1] as $duration) {
                            $timeline[] = $elapsed += (float) $duration;
                        }
                        $timelines[$streamType] ??= $timeline;
                        if (count($timeline) !== count($timelines[$streamType])) {
                            throw new InvalidArgumentException('Composed VOD renditions require aligned segment boundaries.');
                        }
                        foreach ($timeline as $segment => $end) {
                            if (abs($end - $timelines[$streamType][$segment]) > 0.05) {
                                throw new InvalidArgumentException('Composed VOD renditions require aligned segment boundaries.');
                            }
                        }
                        if (abs($metadata['start'] - $clocks[$streamType]) > 0.05) {
                            throw new InvalidArgumentException('Composed VOD presentations require aligned presentation clocks.');
                        }
                    }
                }
                $files[$id] = $file;
            }
            foreach ($inventory['variants'] as $name => $ids) {
                $name = (is_string($label) ? $label.'-' : '').$name;
                if (isset($variants[$name]) || array_diff($ids, array_keys($files)) !== []) {
                    throw new InvalidArgumentException('Composition variant names conflict or reference an unowned artifact.');
                }
                $variants[$name] = $ids;
            }
            $keys += $inventory['keys'];
            $provenance += $inventory['provenance'];
            // The response parser validates every master reference before anything is published.
            $masterText = $this->owned(fn () => $presentation->render($master, function ($id) use ($presentation, $path) {
                return $this->relativeUrl($presentation->file($id)['path'], $path);
            }));
            foreach (explode("\n", $masterText) as $line) {
                if (str_starts_with($line, '#EXT-X-VERSION:')) {
                    $version = max($version, (int) substr($line, 15));
                } elseif (str_starts_with($line, '#EXT-X-MEDIA:') || str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                    $start = strpos($line, ':') + 1;
                    $attributes = HlsPlaylist::attributes(substr($line, $start))
                        ?? throw new InvalidArgumentException('Composition cannot safely combine this master directive.');
                    // Prefix only the group attribute values, from the end so earlier offsets stay valid.
                    foreach (array_reverse(array_intersect_key($attributes, array_flip(['GROUP-ID', 'AUDIO', 'SUBTITLES']))) as [$value, $offset]) {
                        if (str_starts_with($value, '"')) {
                            $line = substr_replace($line, '"p'.$index.'-', $start + $offset, 1);
                        }
                    }
                    $lines[] = $line;
                } elseif ($line !== '' && $line[0] !== '#') {
                    $lines[] = $line;
                } elseif (str_starts_with($line, '#EXT') && $line !== '#EXTM3U') {
                    throw new InvalidArgumentException('Composition cannot safely combine this master directive.');
                }
            }
        }

        // A fresh versioned key is the sole write, after every input and conflict check.
        $destination = (new ExportOutput($path, $diskName))->destination()['path'];
        if ($destination !== $path || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'm3u8') {
            throw new InvalidArgumentException('Composition requires a canonical m3u8 destination.');
        }
        $destination = substr($path, 0, -5).'.'.bin2hex(random_bytes(16)).'.m3u8';
        $storage = Storage::disk($diskName);
        $paths[$diskName][] = $destination;
        foreach ($paths as $diskPaths) {
            $ownedPaths = array_fill_keys($diskPaths, true);
            foreach ($diskPaths as $diskPath) {
                for ($parent = dirname($diskPath); $parent !== '.'; $parent = dirname($parent)) {
                    if (isset($ownedPaths[$parent])) {
                        throw new InvalidArgumentException('Composition paths contain conflicting artifact prefixes.');
                    }
                }
            }
        }
        $text = implode("\n", ['#EXTM3U', '#EXT-X-VERSION:'.$version, ...$lines, '']);
        if (strlen($text) > 16777216 || $storage->exists($destination)) {
            throw new InvalidArgumentException('The composition master exceeds its budget or already exists.');
        }
        $master = ['disk' => $diskName, 'path' => $destination, 'role' => 'hls-master'];
        $files[] = $master;
        $presentation = new HlsPresentation(array_values($files), HlsPresentation::id($diskName, $destination), $keys, $variants, provenance: $provenance);
        $sizes[HlsPresentation::id($diskName, $destination)] = strlen($text);
        $generated = array_map(fn ($file) => new GeneratedFile(Storage::disk($file['disk']), $file['disk'], $file['path'],
            $sizes[HlsPresentation::id($file['disk'], $file['path'])], $file['role'], $file === $master), array_values($files));
        $published = [];
        try {
            if (! $storage->put($destination, $text)) {
                throw new RuntimeException('Publication failed.');
            }
            $published[] = ['disk' => $diskName, 'path' => $destination];
            if ($storage->size($destination) !== strlen($text)) {
                throw new RuntimeException('Published size mismatch.');
            }
        } catch (Throwable) {
            throw new MediaException('Cannot publish the composed HLS master.', 'publication', 'transfer',
                published: $published, destination: ['disk' => $diskName, 'path' => $destination]);
        }

        return new HlsExportResult($generated, [], presentation: $presentation);
    }

    /** Report unowned references and invalid playlists as invalid composition input; storage failures stay MediaExceptions. */
    private function owned(Closure $read): mixed
    {
        try {
            return $read();
        } catch (ItemNotFoundException $exception) {
            throw new InvalidArgumentException('Composition inventories must reference only files they own.', previous: $exception);
        } catch (MediaException $exception) {
            if ($exception->category !== 'artifact') {
                throw $exception;
            }
            throw new InvalidArgumentException('Composition requires valid HLS playlists: '.$exception->getMessage(), previous: $exception);
        }
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
}

<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPlaylist;
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
use RuntimeException;
use Throwable;

/**
 * A composition of trusted, completed v9 HLS inventories under one new master playlist.
 *
 * The composition never concatenates or rewrites media.
 */
class PendingHlsMerge
{
    use Macroable;

    /**
     * The disk to write the composed master to.
     */
    protected ?string $disk = null;

    // The composition state below belongs to a single save() call and is reset before it returns.

    /**
     * The disk the composed master is written to.
     */
    protected ?string $destinationDisk = null;

    /**
     * The composed inventory files, keyed by resource ID.
     *
     * @var array<string|int, array>
     */
    protected array $files = [];

    /**
     * The verified file sizes, keyed by resource ID.
     *
     * @var array<string, int>
     */
    protected array $sizes = [];

    /**
     * The composed paths, keyed by disk.
     *
     * @var array<string, list<string>>
     */
    protected array $paths = [];

    /**
     * The resource IDs already claimed by a composed inventory.
     *
     * @var array<string, true>
     */
    protected array $destinations = [];

    /**
     * The key references of the composed inventories.
     */
    protected array $keys = [];

    /**
     * The playlist provenance of the composed inventories.
     */
    protected array $provenance = [];

    /**
     * The composed variants, keyed by their aliased names.
     */
    protected array $variants = [];

    /**
     * The directive and reference lines of the composed master.
     *
     * @var list<string>
     */
    protected array $lines = [];

    /**
     * The highest HLS version declared by a composed master.
     */
    protected int $version = 3;

    /**
     * The provenance of the first composed playlist that the others must match.
     */
    protected ?array $clock = null;

    /**
     * The presentation start time of the first rendition of each stream type.
     */
    protected array $clocks = [];

    /**
     * The segment end times of the first rendition of each stream type.
     */
    protected array $timelines = [];

    /**
     * Create a new pending HLS merge instance.
     */
    public function __construct(protected readonly array $presentations)
    {
        if (count($presentations) < 2) {
            throw new InvalidArgumentException('HLS composition requires at least two completed presentations.');
        }
    }

    /**
     * Set the disk to write the composed master to.
     */
    public function toDisk(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Write the composed master playlist to the given path.
     *
     * The master is written to a fresh versioned key beside the given path.
     */
    public function save(string $path): HlsExportResult
    {
        $this->resetComposition();
        $this->destinationDisk = $this->disk;

        try {
            foreach (array_values($this->presentations) as $index => $input) {
                $this->addPresentation($index, array_keys($this->presentations)[$index], $input, $path);
            }

            // The master is written to a fresh versioned key, and that write is the only one we
            // make. It happens after every input and path conflict has been checked.
            $destination = $this->versionedDestination($path);
            $storage = Storage::disk($this->destinationDisk);
            $this->paths[$this->destinationDisk][] = $destination;
            $this->ensurePathsDoNotConflict();

            $text = $this->renderMaster();
            if ($storage->exists($destination)) {
                throw new InvalidArgumentException('The composition master destination already exists.');
            }

            [$presentation, $generated] = $this->describeComposition($destination, $text);
            $this->publishMaster($storage, $destination, $text);

            return new HlsExportResult($generated, [], presentation: $presentation);
        } finally {
            $this->resetComposition();
        }
    }

    /**
     * Reset the composition state to its defaults.
     */
    protected function resetComposition(): void
    {
        $this->destinationDisk = null;
        $this->files = [];
        $this->sizes = [];
        $this->paths = [];
        $this->destinations = [];
        $this->keys = [];
        $this->provenance = [];
        $this->variants = [];
        $this->lines = [];
        $this->version = 3;
        $this->clock = null;
        $this->clocks = [];
        $this->timelines = [];
    }

    /**
     * Validate a presentation and add its artifacts, variants and master lines to the composition.
     */
    protected function addPresentation(int $index, int|string $label, mixed $input, string $path): void
    {
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
        $master = $this->owned(fn (): array => $presentation->file());
        if ($master['role'] !== 'hls-master') {
            throw new InvalidArgumentException('Each composed presentation must start at a v9 HLS master playlist.');
        }

        if ($inventory['variants'] === []) {
            throw new InvalidArgumentException('Each composed presentation requires variant provenance.');
        }

        $this->destinationDisk ??= $master['disk'];
        foreach ($inventory['files'] as $file) {
            $this->addFile($presentation, $inventory['provenance'], $file);
        }

        $this->addVariants($label, $inventory['variants']);
        $this->keys += $inventory['keys'];
        $this->provenance += $inventory['provenance'];
        // Rendering the master through the response parser validates every one of its
        // references, so nothing is published when a reference is invalid.
        $this->addMasterLines($presentation, $master, $index, $path);
    }

    /**
     * Validate an inventory artifact on storage and add it to the composition.
     */
    protected function addFile(HlsPresentation $presentation, array $provenance, array $file): void
    {
        $id = HlsPresentation::id($file['disk'], $file['path']);
        if (isset($this->destinations[$id])) {
            throw new InvalidArgumentException('Composed inventories must have disjoint destinations.');
        }

        $this->destinations[$id] = true;
        $this->paths[$file['disk']][] = $file['path'];
        if ($file['role'] !== 'hls-key' && $file['disk'] !== $this->destinationDisk) {
            throw new InvalidArgumentException('Composed playlists and media must share the destination disk.');
        }

        $storage = Storage::disk($file['disk']);
        $this->sizes[$id] = $storage->exists($file['path']) ? $storage->size($file['path']) : 0;
        if ($this->sizes[$id] <= 0 || ($file['role'] === 'hls-key' && $this->sizes[$id] !== 16)) {
            throw new InvalidArgumentException('A completed HLS inventory artifact is missing or invalid.');
        }

        if ($file['role'] === 'hls-master') {
            return;
        }

        if ($file['role'] === 'hls-playlist') {
            $metadata = $provenance[$file['path']] ?? [];
            $durations = $this->completedPlaylistDurations($presentation, $file, $metadata);
            $this->ensureClockMatches($metadata);
            if (! ($metadata['subtitle'] ?? false)) {
                $this->ensureStreamIsAligned($metadata, $durations);
            }
        }

        $this->files[$id] = $file;
    }

    /**
     * Ensure a playlist is an unchanged, completed v9 VOD playlist and return its segment durations.
     *
     * @return list<string>
     */
    protected function completedPlaylistDurations(HlsPresentation $presentation, array $file, array $metadata): array
    {
        if (($metadata['version'] ?? null) !== 9 || ! is_numeric($metadata['duration'] ?? null)
            || ! is_finite((float) $metadata['duration']) || $metadata['duration'] <= 0
            || ! in_array($metadata['segmentType'] ?? null, ['mpegts', 'fmp4'], true)) {
            throw new InvalidArgumentException('Composition requires retained v9 VOD clock provenance.');
        }

        $text = $this->owned(fn (): string => $presentation->render($file, fn ($id): string => "owned/{$id}"));
        preg_match_all('/^#EXTINF:([0-9]+(?:\.[0-9]+)?),/m', $text, $durations);
        if (substr_count($text, '#EXT-X-ENDLIST') !== 1 || substr_count($text, '#EXT-X-PLAYLIST-TYPE:VOD') !== 1
            || str_contains($text, '#EXT-X-DISCONTINUITY') || str_contains($text, '#EXT-X-BYTERANGE')
            || $durations[1] === [] || abs(array_sum($durations[1]) - $metadata['duration']) > 0.00001) {
            throw new InvalidArgumentException('Composition requires unchanged completed VOD playlists.');
        }

        return $durations[1];
    }

    /**
     * Ensure a playlist shares the packaging, clip and duration of the first composed playlist.
     */
    protected function ensureClockMatches(array $metadata): void
    {
        $this->clock ??= $metadata;
        if ($metadata['segmentType'] !== $this->clock['segmentType']) {
            throw new InvalidArgumentException('Composed VOD presentations require the same segment packaging.');
        }

        if ($metadata['clip'] !== $this->clock['clip']) {
            throw new InvalidArgumentException('Composed VOD presentations require the same clip.');
        }

        if (abs($metadata['duration'] - $this->clock['duration']) > 0.1) {
            throw new InvalidArgumentException(
                'Composed VOD presentations require durations within 0.1 seconds of each other.'
            );
        }
    }

    /**
     * Ensure a rendition's clock and segment boundaries align with the first of its stream type.
     *
     * @param  list<string>  $durations
     */
    protected function ensureStreamIsAligned(array $metadata, array $durations): void
    {
        if (! is_numeric($metadata['start'] ?? null) || ! is_finite((float) $metadata['start'])) {
            throw new InvalidArgumentException('Cannot establish the HLS presentation clock.');
        }

        $streamType = $metadata['streamType'] ?? '';
        if (! in_array($streamType, ['video', 'audio'], true)) {
            throw new InvalidArgumentException('Composition requires retained stream clock types.');
        }

        $this->clocks[$streamType] ??= $metadata['start'];
        $timeline = [];
        $elapsed = 0.0;
        foreach ($durations as $duration) {
            $timeline[] = $elapsed += (float) $duration;
        }

        $this->timelines[$streamType] ??= $timeline;
        if (count($timeline) !== count($this->timelines[$streamType])) {
            throw new InvalidArgumentException('Composed VOD renditions require aligned segment boundaries.');
        }

        foreach ($timeline as $segment => $end) {
            if (abs($end - $this->timelines[$streamType][$segment]) > 0.05) {
                throw new InvalidArgumentException('Composed VOD renditions require aligned segment boundaries.');
            }
        }

        if (abs($metadata['start'] - $this->clocks[$streamType]) > 0.05) {
            throw new InvalidArgumentException('Composed VOD presentations require aligned presentation clocks.');
        }
    }

    /**
     * Add a presentation's variants under their aliased names.
     */
    protected function addVariants(int|string $label, array $variants): void
    {
        foreach ($variants as $name => $ids) {
            $name = (is_string($label) ? $label.'-' : '').$name;
            if (isset($this->variants[$name])) {
                throw new InvalidArgumentException(
                    'Composed variant names must be unique; use aliases to prefix them.'
                );
            }

            if (array_diff($ids, array_keys($this->files)) !== []) {
                throw new InvalidArgumentException('A composed variant references an unowned artifact.');
            }

            $this->variants[$name] = $ids;
        }
    }

    /**
     * Add a presentation's master directives and references to the composed master.
     */
    protected function addMasterLines(HlsPresentation $presentation, array $master, int $index, string $path): void
    {
        $masterText = $this->owned(fn (): string => $presentation->render(
            $master,
            fn ($id): string => HlsPresentation::relativeUrl($presentation->file($id)['path'], $path),
        ));
        foreach (explode("\n", $masterText) as $line) {
            if (str_starts_with($line, '#EXT-X-VERSION:')) {
                $this->version = max($this->version, (int) substr($line, 15));
            } elseif (str_starts_with($line, '#EXT-X-MEDIA:') || str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $this->lines[] = $this->prefixGroupAttributes($line, $index);
            } elseif ($line !== '' && $line[0] !== '#') {
                $this->lines[] = $line;
            } elseif (str_starts_with($line, '#EXT') && $line !== '#EXTM3U') {
                throw new InvalidArgumentException('Composition cannot safely combine this master directive.');
            }
        }
    }

    /**
     * Prefix a master directive's group attribute values with the presentation index.
     */
    protected function prefixGroupAttributes(string $line, int $index): string
    {
        $start = strpos($line, ':') + 1;
        $attributes = HlsPlaylist::attributes(substr($line, $start))
            ?? throw new InvalidArgumentException('Composition cannot safely combine this master directive.');
        // We will only prefix the group attribute values, and we will work from the end of the
        // line so the offsets of the earlier attributes stay valid as the line grows.
        foreach (array_reverse(array_intersect_key(
            $attributes,
            array_flip(['GROUP-ID', 'AUDIO', 'SUBTITLES']),
        )) as [$value, $offset]) {
            if (str_starts_with($value, '"')) {
                $line = substr_replace($line, "\"p{$index}-", $start + $offset, 1);
            }
        }

        return $line;
    }

    /**
     * Ensure the destination is a canonical m3u8 path and return a fresh versioned key beside it.
     */
    protected function versionedDestination(string $path): string
    {
        $destination = new ExportOutput($path, $this->destinationDisk)->destination()['path'];
        if ($destination !== $path) {
            throw new InvalidArgumentException('The composition destination must be a canonical path.');
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'm3u8') {
            throw new InvalidArgumentException('The composition destination must have an m3u8 extension.');
        }

        return substr($path, 0, -5).'.'.bin2hex(random_bytes(16)).'.m3u8';
    }

    /**
     * Ensure no composed path is a parent directory of another on the same disk.
     */
    protected function ensurePathsDoNotConflict(): void
    {
        foreach ($this->paths as $diskPaths) {
            $ownedPaths = array_fill_keys($diskPaths, true);
            foreach ($diskPaths as $diskPath) {
                for ($parent = dirname($diskPath); $parent !== '.'; $parent = dirname($parent)) {
                    if (isset($ownedPaths[$parent])) {
                        throw new InvalidArgumentException('Composition paths contain conflicting artifact prefixes.');
                    }
                }
            }
        }
    }

    /**
     * Render the composed master playlist.
     */
    protected function renderMaster(): string
    {
        $text = implode("\n", ['#EXTM3U', "#EXT-X-VERSION:{$this->version}", ...$this->lines, '']);
        if (strlen($text) > Media::MAX_MANIFEST_BYTES) {
            throw new InvalidArgumentException('The composition master must fit within the 16 MiB limit.');
        }

        return $text;
    }

    /**
     * Describe the composed presentation and its generated files, including the new master.
     *
     * @return array{0: HlsPresentation, 1: list<GeneratedFile>}
     */
    protected function describeComposition(string $destination, string $text): array
    {
        $master = ['disk' => $this->destinationDisk, 'path' => $destination, 'role' => 'hls-master'];
        $this->files[] = $master;
        $presentation = new HlsPresentation(
            array_values($this->files),
            HlsPresentation::id($this->destinationDisk, $destination),
            $this->keys,
            $this->variants,
            provenance: $this->provenance,
        );
        $this->sizes[HlsPresentation::id($this->destinationDisk, $destination)] = strlen($text);
        $generated = collect($this->files)
            ->values()
            ->map(fn (array $file): GeneratedFile => new GeneratedFile(
                Storage::disk($file['disk']),
                $file['disk'],
                $file['path'],
                $this->sizes[HlsPresentation::id($file['disk'], $file['path'])],
                $file['role'],
                $file === $master,
            ))
            ->all();

        return [$presentation, $generated];
    }

    /**
     * Write the composed master and verify its published size.
     */
    protected function publishMaster(Filesystem $storage, string $destination, string $text): void
    {
        $published = [];
        try {
            if (! $storage->put($destination, $text)) {
                throw new RuntimeException('Publication failed.');
            }

            $published[] = ['disk' => $this->destinationDisk, 'path' => $destination];
            if ($storage->size($destination) !== strlen($text)) {
                throw new RuntimeException('Published size mismatch.');
            }
        } catch (Throwable) {
            throw MediaException::publication(
                'Cannot publish the composed HLS master.',
                'transfer',
                published: $published,
                destination: ['disk' => $this->destinationDisk, 'path' => $destination],
            );
        }
    }

    /**
     * Run the given read and report unowned references and invalid playlists as invalid composition input.
     *
     * Storage failures are rethrown as the original MediaException.
     */
    protected function owned(Closure $read): mixed
    {
        try {
            return $read();
        } catch (ItemNotFoundException $exception) {
            throw new InvalidArgumentException(
                'Composition inventories must reference only files they own.',
                $exception->getCode(),
                previous: $exception,
            );
        } catch (MediaException $exception) {
            if ($exception->category !== 'artifact') {
                throw $exception;
            }

            throw new InvalidArgumentException(
                "Composition requires valid HLS playlists: {$exception->getMessage()}",
                $exception->getCode(),
                previous: $exception,
            );
        }
    }
}

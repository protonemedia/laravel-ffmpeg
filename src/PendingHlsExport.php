<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\HlsEncryption;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\MeasuresDuration;
use ProtoneMedia\LaravelFFMpeg\Pipeline\PackagesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesCommand;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesOutputs;
use ProtoneMedia\LaravelFFMpeg\Pipeline\SplitsTwoPass;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ValidatesSelections;
use ProtoneMedia\LaravelFFMpeg\Pipeline\WritesManifest;
use ProtoneMedia\LaravelFFMpeg\Queue\ExportPlan;
use SensitiveParameter;

/**
 * An HLS export of one or more variants and alternate renditions behind a master playlist.
 */
class PendingHlsExport implements Exportable, MeasuresDuration, PackagesArtifacts, ShapesCommand, ShapesOutputs, SplitsTwoPass, ValidatesSelections, WritesManifest
{
    use Concerns\AudioCodec;
    use Concerns\AudioFilters;
    use Concerns\Clips;
    use Concerns\Fades;
    use Concerns\GlobalOptions;
    use Concerns\ManagesHlsRenditions;
    use Concerns\Mapping;
    use Concerns\Metadata;
    use Concerns\OutputOptions;
    use Concerns\PackagesHlsSegments;
    use Concerns\ResourceFilters;
    use Concerns\Resources;
    use Concerns\RunsExport;
    use Concerns\VideoCodec;
    use Concerns\VideoFilters;
    use Concerns\WritesHlsMasterPlaylist;
    use Conditionable, Macroable;

    /**
     * The export pipeline this builder configures.
     */
    protected readonly PendingExport $export;

    /**
     * The variants and alternate renditions, keyed by name.
     */
    protected array $variants = [];

    /**
     * The custom playlist, segment and initialization paths, keyed by variant name.
     */
    protected array $variantPaths = [];

    /**
     * The destination paths each variant owns, collected during the save.
     */
    protected array $variantFiles = [];

    /**
     * The callback that may add lines to each generated playlist.
     */
    protected ?Closure $playlistCallback = null;

    /**
     * The AES-128 encryption of the segments.
     */
    protected ?HlsEncryption $encryption = null;

    /**
     * The key references of each playlist, collected during the save.
     */
    protected array $keyReferences = [];

    /**
     * The segment container, either "mpegts" or "fmp4".
     */
    protected string $segmentType = 'mpegts';

    /**
     * The playlist type, either "vod", "event" or "live".
     */
    protected string $playlistType = 'vod';

    /**
     * The alternate renditions, keyed by their variant name.
     */
    protected array $renditions = [];

    /**
     * The presentation duration in seconds that subtitle playlists are written for.
     */
    protected ?float $presentationDuration = null;

    /**
     * The clock provenance of each playlist, collected during the save.
     */
    protected array $provenance = [];

    /**
     * The workspace of the current execution.
     */
    protected ?Workspace $workspace = null;

    /**
     * Create a new pending HLS export instance.
     */
    public function __construct(
        protected readonly Media $media,
        string|Filesystem $disk,
        protected readonly float $segmentDuration,
    ) {
        if (! is_finite($segmentDuration) || $segmentDuration < 0.001 || $segmentDuration > 86400) {
            throw new InvalidArgumentException(
                'The HLS segment duration must be between 0.001 and 86400 seconds.'
            );
        }

        $this->export = new PendingExport($media, $disk, $this, 'hls-playlist');
        $this->export->video('libx264')->audio('aac');
    }

    /**
     * Add an input and get its stable FFmpeg input index.
     *
     * Reusing a staged input never removes an input.
     */
    public function addInput(Media $media): int
    {
        return $this->export->addInput($media);
    }

    /**
     * Set the complex filter graph of the export.
     */
    public function filterComplex(string $expression): static
    {
        $this->export->filterComplex($expression);

        return $this;
    }

    /**
     * Overlay a PNG, JPEG or GIF after all video filters, including each variant's resize.
     *
     * @param  (callable(Watermark): mixed)|null  $configure  Runs immediately; its return value is ignored.
     */
    public function watermark(Media $image, ?callable $configure = null): static
    {
        $this->export->watermark($image, $configure);

        return $this;
    }

    /**
     * Encode the variants in two passes.
     */
    public function twoPass(): static
    {
        $this->export->twoPass();

        return $this;
    }

    /**
     * Get the destination disk of the export.
     *
     * Parallel queueing writes every part to this one shared named disk.
     *
     * @internal
     */
    public function filesystem(): string|Filesystem
    {
        return $this->export->filesystem();
    }

    /**
     * Set the playlist type to "vod", "event" or "live".
     */
    public function playlistType(string $type): static
    {
        $this->export->assertOutputMutable();
        $type = strtolower($type);
        if (! in_array($type, ['vod', 'event', 'live'], true)) {
            throw new InvalidArgumentException("The HLS playlist type [{$type}] must be vod, event, or live.");
        }

        $this->playlistType = $type;

        return $this;
    }

    /**
     * Package the segments as fragmented MP4 with one owned initialization file per rendition.
     *
     * AES-128 encryption is only supported for MPEG-TS segments.
     */
    public function fmp4(): static
    {
        $this->export->assertOutputMutable();
        if ($this->encryption instanceof HlsEncryption) {
            throw new InvalidArgumentException('AES-128 encryption is supported only for MPEG-TS HLS.');
        }

        $this->segmentType = 'fmp4';

        return $this;
    }

    /**
     * Set the callback that may add lines to each generated playlist.
     *
     * @param  callable(string, string, string|null): string  $callback
     */
    public function playlistUsing(callable $callback): static
    {
        $this->export->assertOutputMutable();
        $this->playlistCallback = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Encrypt the MPEG-TS segments with AES-128 keys stored on the given disk.
     *
     * @param  callable(string, string, string, int): string  $keyUrl
     */
    public function encrypt(
        string|Filesystem $keysDisk,
        callable $keyUrl,
        #[SensitiveParameter] ?string $key = null,
        ?int $rotateEvery = null,
        string $directory = 'hls-keys',
    ): static {
        $this->export->assertOutputMutable();
        if ($this->segmentType === 'fmp4') {
            throw new InvalidArgumentException('AES-128 encryption is supported only for MPEG-TS HLS.');
        }

        $this->encryption = new HlsEncryption($keysDisk, $keyUrl, $key, $rotateEvery, $directory);

        return $this;
    }

    /**
     * Get one playlist output per variant followed by the master output.
     *
     * @internal
     */
    public function outputs(array $default, ?string $path): array
    {
        if (count($this->variants) === count($this->renditions)) {
            throw new InvalidArgumentException('An HLS export requires at least one variant.');
        }

        if ($this->encryption instanceof HlsEncryption
            && array_any($this->renditions, fn ($item): bool => $item['type'] === 'SUBTITLES')) {
            throw new InvalidArgumentException('Encrypted alternate subtitle renditions are not supported.');
        }

        $master = new ExportOutput($path ?? 'master.m3u8', $this->export->filesystem())->configure(array_replace(
            $this->export->outputConfiguration(), ['maps' => [], 'omitAudio' => false, 'omitVideo' => false],
        ));
        $path = $master->destination()['path'];

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'm3u8') {
            throw new InvalidArgumentException('The HLS master destination must have an m3u8 extension.');
        }

        $directory = dirname($path) === '.' ? '' : dirname($path).'/';
        $outputs = [];
        foreach ($this->variants as $name => $variant) {
            $outputs[] = $this->variantOutput($name, $variant, $directory);
        }

        $outputs[] = $master;
        $this->ensureRenditionClipsMatch($outputs);

        return $outputs;
    }

    /**
     * Split the export into parts that can be encoded in parallel.
     *
     * Variants that share alternate groups stay in one part so each rendition is encoded once.
     *
     * @internal
     */
    public function queueParts(string $path, string $prefix): array
    {
        $this->outputs([], $path);
        if ($this->playlistType !== 'vod') {
            throw new InvalidArgumentException(
                'Parallel HLS composition requires VOD playlists; use a single queued export for event or live playlists.'
            );
        }

        if ($this->playlistCallback instanceof Closure) {
            throw new InvalidArgumentException(
                'Parallel HLS does not accept custom playlist callbacks; use a single queued export.'
            );
        }

        if (in_array('-filter_complex', $this->export->inspect(), true)) {
            throw new InvalidArgumentException(
                'Parallel HLS does not accept complex graphs; use a single queued export.'
            );
        }

        $remaining = collect($this->variants)->diffKeys($this->renditions)->keys()->all();
        foreach ($this->renditions as $rendition) {
            if (! array_any(
                $remaining,
                fn (int|string $name): bool => $this->variants[$name]->groups()[$rendition['type']]
                    === $rendition['group'],
            )) {
                throw new InvalidArgumentException(
                    'Parallel HLS requires every alternate rendition group to be referenced by a variant.'
                );
            }
        }

        $parts = [];
        while ($remaining !== []) {
            $members = [array_shift($remaining)];
            do {
                $previous = count($members);
                $groups = [];
                foreach ($members as $member) {
                    foreach ($this->variants[$member]->groups() as $type => $group) {
                        if ($group !== null) {
                            $groups["{$type}:{$group}"] = true;
                        }
                    }
                }

                foreach ($remaining as $index => $name) {
                    foreach ($this->variants[$name]->groups() as $type => $group) {
                        if ($group !== null && isset($groups["{$type}:{$group}"])) {
                            $members[] = $name;
                            unset($remaining[$index]);
                            break;
                        }
                    }
                }
            } while (count($members) !== $previous);

            foreach ($this->renditions as $id => $rendition) {
                if (isset($groups["{$rendition['type']}:{$rendition['group']}"])) {
                    $members[] = $id;
                }
            }

            $part = new ExportPlan($this)->restore();
            $part->variants = collect($part->variants)->only($members)->all();
            $part->renditions = collect($part->renditions)->only($members)->all();
            $directory = "{$prefix}/parts/".count($parts);
            $part->variantPaths = collect($part->variantPaths)
                ->only($members)
                ->map(fn ($paths): array => array_map(
                    fn ($value): ?string => $value === null ? null : "{$directory}/custom/{$value}",
                    $paths,
                ))
                ->all();
            $parts[] = [$part, "{$directory}/master.m3u8"];
        }

        return $parts;
    }

    /**
     * Run the export and publish the master playlist and its variants.
     */
    public function save(?string $path = null): HlsExportResult
    {
        $this->export->assertOutputMutable();
        if ($path === null) {
            throw new InvalidArgumentException('An HLS export requires a master playlist destination.');
        }

        $this->outputs([], $path);
        $this->export->inspect();
        foreach ($this->variants as $variant) {
            $variant->freeze();
        }

        $this->variantFiles = [];
        $this->keyReferences = [];
        $this->provenance = [];
        try {
            $result = $this->export->save($path);
        } finally {
            // The workspace belongs to a single execution, and a saved builder may still be
            // queued afterwards, so we will release it here.
            $this->workspace = null;
        }

        [$keys, $files] = $result->files()->partition(fn ($file): bool => $file->role === 'hls-key');

        $renditions = [];
        foreach ($this->renditions as $id => $rendition) {
            $renditions[$rendition['type']][$rendition['group']][$rendition['name']] = $id;
        }

        return new HlsExportResult(
            [...$files, ...$keys],
            $result->processes()->all(),
            $this->variantFiles,
            $renditions,
            $this->keyReferences,
            variantGroups: array_map(
                fn ($variant): array => array_filter($variant->groups(), fn ($group): bool => $group !== null),
                $this->variants,
            ),
            provenance: $this->provenance,
        );
    }

    /**
     * Get the command with the HLS muxer options in front of each playlist output.
     *
     * @internal
     */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        foreach ($outputs as $output) {
            if (in_array('-f', $output->outputConfiguration()['outputOptions'], true)) {
                throw new InvalidArgumentException('HLS owns the output muxer.');
            }
        }

        $result = [];
        foreach ($default as $token) {
            $index = array_search(
                $token,
                $outputPaths ?? array_map(fn (int|string $i): string => "[output:{$i}]", array_keys($outputs)),
                true,
            );
            if ($index !== false) {
                if ($this->isSubtitle($index)) {
                    $result[] = '-c:s';
                    $result[] = 'webvtt';
                    $result[] = '-f';
                    $result[] = 'webvtt';
                    $result[] = $token;

                    continue;
                }

                $settings = $outputs[$index]->outputConfiguration();
                if (! isset($this->renditions[array_keys($this->variants)[$index]])
                    && ! $settings['omitVideo'] && ($settings['video']['codec'] ?? null) !== 'copy') {
                    $result[] = '-force_key_frames:v';
                    $result[] = 'expr:gte(t,n_forced*'.CommandCompiler::timestamp($this->segmentDuration).')';
                    $result[] = '-flags:v';
                    $result[] = '+cgop';
                }

                $result[] = '-f';
                $result[] = 'hls';
                $result[] = '-hls_time';
                $result[] = CommandCompiler::timestamp($this->segmentDuration);
                $result[] = '-hls_list_size';
                $result[] = '0';
                $result[] = '-hls_segment_type';
                $result[] = $this->segmentType;
                $result[] = '-start_number';
                $result[] = '0';
                $result[] = '-hls_segment_filename';
                $result[] = "segment-{$index}-%08d.".($this->segmentType === 'fmp4' ? 'm4s' : 'ts');
                $result[] = '-master_pl_name';
                $result[] = "master-{$index}.m3u8";
                if ($this->playlistType !== 'live') {
                    $result[] = '-hls_playlist_type';
                    $result[] = $this->playlistType;
                }

                if ($this->segmentType === 'fmp4') {
                    $result[] = '-hls_fmp4_init_filename';
                    $result[] = "init-{$index}.mp4";
                }
            }

            $result[] = $token;
        }

        return $result;
    }

    /**
     * Ensure each rendition maps one stream of its type and grouped variants map no muxed audio.
     *
     * @internal
     */
    public function selections(array $expected): void
    {
        foreach (array_keys($this->variants) as $index => $name) {
            if (isset($this->renditions[$name])) {
                $type = $this->renditions[$name]['type'] === 'AUDIO' ? 'audio' : 'subtitle';
                if ($expected[$index] !== [$type]) {
                    throw MediaException::probing(
                        'An HLS rendition must map exactly one stream of its declared type.',
                        'mapping',
                    );
                }
            } elseif ($this->variants[$name]->groups()['AUDIO'] !== null
                && in_array('audio', $expected[$index], true)) {
                throw MediaException::probing(
                    'Variants with an alternate audio group must not map muxed audio.',
                    'mapping',
                );
            }
        }
    }

    /**
     * Get the variant outputs that take part in two-pass encoding.
     *
     * Alternate renditions are only packaged, never two-pass encoded.
     *
     * @internal
     */
    public function twoPassOutputs(array $default, array $outputs): array
    {
        return collect($this->variants)
            ->keys()
            ->reject(fn (int|string $name): bool => isset($this->renditions[$name]))
            ->keys()
            ->all();
    }

    /**
     * Get the first-pass command without the HLS muxer options.
     *
     * @internal
     */
    public function analysisCommand(array $command): array
    {
        $analysis = [];
        for ($index = 0; $index < count($command); $index++) {
            if (in_array($command[$index], [
                '-f', '-hls_time', '-hls_list_size', '-hls_playlist_type', '-hls_segment_type',
                '-start_number', '-hls_segment_filename', '-master_pl_name', '-hls_fmp4_init_filename',
            ], true)) {
                $index++;
            } else {
                $analysis[] = $command[$index];
            }
        }

        return $analysis;
    }

    /**
     * Get the private path the encoder writes each playlist or subtitle segment to.
     *
     * @internal
     */
    public function outputPaths(array $default, array $outputs, Workspace $workspace): array
    {
        $this->workspace = $workspace;

        return array_map(
            fn (int|string $index): string => "{$workspace->path}/"
                .($this->isSubtitle($index) ? "segment-{$index}-00000000.vtt" : "playlist-{$index}.m3u8"),
            array_keys($outputs),
        );
    }

    /**
     * Determine if the output at the given index is a subtitle rendition.
     */
    protected function isSubtitle(int $index): bool
    {
        return ($this->renditions[array_keys($this->variants)[$index] ?? '']['type'] ?? null) === 'SUBTITLES';
    }

    /**
     * Remember the presentation duration and ensure subtitle renditions have one.
     *
     * Subtitle playlists need the presentation duration later, in artifacts().
     *
     * @internal
     */
    public function duration(array $metadata, ?float $default): ?float
    {
        $this->presentationDuration = $default;
        if (array_any($this->renditions, fn ($item): bool => $item['type'] === 'SUBTITLES')
            && ($this->presentationDuration === null || $this->presentationDuration <= 0)) {
            throw new InvalidArgumentException(
                'Subtitle renditions require a known presentation duration without a complex timing graph.'
            );
        }

        return $this->presentationDuration;
    }

    /**
     * Validate and rewrite every playlist, write the master and get every artifact to publish.
     *
     * @internal
     */
    public function artifacts(array $default, array $outputs, array $paths): array
    {
        $encryption = $this->encryption instanceof HlsEncryption ? clone $this->encryption : null;
        $segments = [];
        $playlists = [];
        $streamInfo = [];
        foreach (array_slice($outputs, 0, -1) as $index => $output) {
            // A subtitle rendition is encoded as one WebVTT file without a playlist, so we
            // will write its playlist ourselves and then validate it exactly like the
            // playlists the HLS muxer produced for every other variant.
            if ($this->isSubtitle($index)) {
                $paths[$index] = $this->writeSubtitlePlaylist($index, $output, $paths[$index]);
            }

            $name = array_keys($this->variants)[$index];
            [$variantSegments, $streamInfo[$name]] = $this->packageVariant(
                $index,
                $output,
                $paths[$index],
                $encryption,
            );
            array_push($segments, ...$variantSegments);
            $playlists[] = [
                'path' => $paths[$index],
                'output' => $index,
                'role' => 'hls-playlist',
                'destination' => $output->destination(),
            ];
        }

        $master = array_key_last($outputs);
        $masterPath = $outputs[$master]->destination()['path'];
        $this->writePlaylist(
            $paths[$master],
            $this->masterPlaylistLines($streamInfo, $masterPath),
            $masterPath,
            null,
        );
        $this->keyReferences = $encryption?->references() ?? [];
        $artifacts = [...($encryption?->artifacts() ?? []), ...$segments, ...$playlists, [
            'path' => $paths[$master],
            'output' => $master,
            'role' => 'hls-master',
            'destination' => $outputs[$master]->destination(),
        ]];
        $this->export->validateDestinations(array_map(
            fn (array $artifact): ExportOutput => new ExportOutput(
                $artifact['destination']['path'],
                ($artifact['target'] ?? $outputs[$artifact['output']])->filesystem(),
            ),
            $artifacts,
        ));

        return $artifacts;
    }
}

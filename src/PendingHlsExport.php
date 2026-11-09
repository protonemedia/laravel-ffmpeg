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
use ProtoneMedia\LaravelFFMpeg\Http\HlsPresentation;
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
    /**
     * The MPEG-TS clock rate in ticks per second.
     */
    protected const int MPEG_CLOCK_RATE = 90000;

    /**
     * The number of ticks after which the 33-bit MPEG-TS clock wraps.
     */
    protected const int MPEG_CLOCK_WRAP = 8589934592;

    use Concerns\AudioCodec;
    use Concerns\AudioFilters;
    use Concerns\Clips;
    use Concerns\Fades;
    use Concerns\GlobalOptions;
    use Concerns\Mapping;
    use Concerns\Metadata;
    use Concerns\OutputOptions;
    use Concerns\ResourceFilters;
    use Concerns\Resources;
    use Concerns\RunsExport;
    use Concerns\VideoCodec;
    use Concerns\VideoFilters;
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
    public function filterComplex(string $expression): self
    {
        $this->export->filterComplex($expression);

        return $this;
    }

    /**
     * Overlay a PNG, JPEG or GIF after all video filters, including each variant's resize.
     *
     * @param  (callable(Watermark): mixed)|null  $configure  Runs immediately; its return value is ignored.
     */
    public function watermark(Media $image, ?callable $configure = null): self
    {
        $this->export->watermark($image, $configure);

        return $this;
    }

    /**
     * Encode the variants in two passes.
     */
    public function twoPass(): self
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
    public function playlistType(string $type): self
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
    public function fmp4(): self
    {
        $this->export->assertOutputMutable();
        if ($this->encryption instanceof HlsEncryption) {
            throw new InvalidArgumentException('AES-128 encryption is supported only for MPEG-TS HLS.');
        }

        $this->segmentType = 'fmp4';

        return $this;
    }

    /**
     * Define a variant of the master playlist.
     *
     * @param  callable(HlsVariant): mixed  $configure
     */
    public function variant(
        string $name,
        callable $configure,
        ?string $playlist = null,
        ?string $segments = null,
        ?string $init = null,
    ): self {
        $this->export->assertOutputMutable();

        $this->ensureSimpleName($name, 'HLS variant name');

        if (isset($this->variants[$name])) {
            throw new InvalidArgumentException("The HLS variant [{$name}] is already defined.");
        }

        $paths = ['playlist' => $playlist, 'segments' => $segments, 'init' => $init];
        foreach ($paths as $type => $path) {
            if ($path === null) {
                continue;
            }

            $path = new ExportOutput($path, $this->export->filesystem())->destination()['path'];
            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), match ($type) {
                'playlist' => ['m3u8'], 'init' => ['mp4'], default => ['ts', 'm4s', 'vtt'],
            }, true)) {
                throw new InvalidArgumentException(match ($type) {
                    'playlist' => 'The HLS variant playlist must have an m3u8 extension.',
                    'init' => 'The HLS initialization file must have an mp4 extension.',
                    default => 'The HLS segment path must have a ts, m4s, or vtt extension.',
                });
            }

            if ($type === 'segments'
                && (! preg_match('/\A[^%]*%(?:0[1-9])?d[^%]*\z/', $path) || str_contains(dirname($path), '%'))) {
                throw new InvalidArgumentException(
                    'The HLS segment filename must contain one %d or %01d through %09d placeholder.'
                );
            }

            $paths[$type] = $path;
        }

        $variant = new HlsVariant;
        $configure($variant);
        $this->variants[$name] = $variant;
        $this->variantPaths[$name] = $paths;

        return $this;
    }

    /**
     * Set the callback that may add lines to each generated playlist.
     *
     * @param  callable(string, string, string|null): string  $callback
     */
    public function playlistUsing(callable $callback): self
    {
        $this->export->assertOutputMutable();
        $this->playlistCallback = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * Define an alternate audio rendition.
     *
     * The rendition is encoded once and any number of variants may reference it through HlsVariant::audioGroup().
     */
    public function audioRendition(
        string $group,
        string $name,
        callable $configure,
        ?string $language = null,
        bool $default = false,
        bool $autoselect = true,
        ?string $channels = null,
        ?string $playlist = null,
        ?string $segments = null,
        ?string $init = null,
    ): self {
        return $this->rendition(
            'AUDIO',
            $group,
            $name,
            $configure,
            $language,
            $default,
            $autoselect,
            false,
            $channels,
            $playlist,
            $segments,
            $init,
        );
    }

    /**
     * Define an alternate subtitle rendition as one WebVTT segment for the whole presentation.
     *
     * The subtitle stream is selected with an explicit subtitle map.
     */
    public function subtitleRendition(
        string $group,
        string $name,
        callable $configure,
        ?string $language = null,
        bool $default = false,
        bool $autoselect = true,
        bool $forced = false,
        ?string $playlist = null,
        ?string $segments = null,
    ): self {
        return $this->rendition(
            'SUBTITLES',
            $group,
            $name,
            $configure,
            $language,
            $default,
            $autoselect,
            $forced,
            null,
            $playlist,
            $segments,
            null,
        );
    }

    /**
     * Define an alternate rendition of the given type as a variant with its own name.
     */
    protected function rendition(
        string $type,
        string $group,
        string $name,
        callable $configure,
        ?string $language,
        bool $default,
        bool $autoselect,
        bool $forced,
        ?string $channels,
        ?string $playlist,
        ?string $segments,
        ?string $init,
    ): self {
        $this->export->assertOutputMutable();
        $this->ensureSimpleName($group, 'HLS rendition group');

        if ($name === '' || ! preg_match('//u', $name) || preg_match('/["\\\\\x00-\x1F\x7F-\x9F]/u', $name)) {
            throw new InvalidArgumentException(
                'The HLS rendition name must be non-empty UTF-8 text without quotes, backslashes, or control characters.'
            );
        }

        if ($language !== null && ! preg_match('/\A[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*\z/', $language)) {
            throw new InvalidArgumentException('The HLS rendition language must be a language tag.');
        }

        if ($channels !== null && ! preg_match('/\A[1-9][0-9]*(?:\/[A-Z0-9]+)?\z/', $channels)) {
            throw new InvalidArgumentException(
                'The HLS rendition channels must be a positive channel count with an optional parameter list.'
            );
        }

        if ($default && ! $autoselect) {
            throw new InvalidArgumentException('A default HLS rendition must also be autoselected.');
        }

        foreach ($this->renditions as $rendition) {
            if ($rendition['type'] !== $type || $rendition['group'] !== $group) {
                continue;
            }

            if ($rendition['name'] === $name) {
                throw new InvalidArgumentException(
                    "The HLS rendition group [{$group}] already has a rendition with this name."
                );
            }

            if ($default && $rendition['default']) {
                throw new InvalidArgumentException(
                    "The HLS rendition group [{$group}] already has a default rendition."
                );
            }
        }

        $id = 'rendition-'.strtolower($type)."-{$group}-".count($this->renditions);
        $this->variant($id, $configure, $playlist, $segments, $init);
        $this->renditions[$id] = [
            'type' => $type,
            'group' => $group,
            'name' => $name,
            'language' => $language,
            'default' => $default,
            'autoselect' => $autoselect,
            'forced' => $forced,
            'channels' => $channels,
        ];

        return $this;
    }

    /**
     * Ensure the given name is a simple path component that cannot traverse directories.
     */
    protected function ensureSimpleName(string $name, string $subject): void
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/', $name)) {
            throw new InvalidArgumentException("The {$subject} must be a simple path component.");
        }
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
    ): self {
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
     * Configure the playlist output of the given variant or alternate rendition.
     */
    protected function variantOutput(int|string $name, HlsVariant $variant, string $directory): ExportOutput
    {
        $rendition = $this->renditions[$name] ?? null;
        $subtitle = ($rendition['type'] ?? null) === 'SUBTITLES';
        $this->ensureVariantPathsMatchPackaging($name, $subtitle);

        $settings = $variant->inherit($this->export->outputConfiguration());
        $settings = $rendition === null
            ? $this->variantSettings($variant, $settings)
            : $this->renditionSettings($variant, $settings, $subtitle);

        return new ExportOutput(
            $this->variantPaths[$name]['playlist'] ?? "{$directory}{$name}/index.m3u8",
            $this->export->filesystem(),
        )->configure($settings);
    }

    /**
     * Ensure the custom segment and initialization paths match the selected packaging.
     */
    protected function ensureVariantPathsMatchPackaging(int|string $name, bool $subtitle): void
    {
        $segments = $this->variantPaths[$name]['segments'];
        $extension = $subtitle ? 'vtt' : ($this->segmentType === 'fmp4' ? 'm4s' : 'ts');
        if ($segments !== null && strtolower(pathinfo($segments, PATHINFO_EXTENSION)) !== $extension) {
            throw new InvalidArgumentException(
                "The HLS segment path must have the [{$extension}] extension of the selected packaging."
            );
        }

        if ($this->variantPaths[$name]['init'] !== null && $this->segmentType !== 'fmp4') {
            throw new InvalidArgumentException('The HLS initialization file requires fMP4 packaging.');
        }
    }

    /**
     * Restrict the output settings of an alternate rendition to its own stream.
     */
    protected function renditionSettings(HlsVariant $variant, array $settings, bool $subtitle): array
    {
        if ($settings['maps'] === []) {
            throw new InvalidArgumentException('An HLS alternate rendition requires an explicit map.');
        }

        if (array_any($variant->groups(), fn ($group): bool => $group !== null)) {
            throw new InvalidArgumentException('An HLS alternate rendition cannot reference rendition groups.');
        }

        $settings['omitVideo'] = false;
        $settings['omitAudio'] = false;
        $settings['video'] = [];
        $settings['videoFilters'] = [];
        if ($subtitle) {
            $settings['audio'] = [];
            $settings['audioFilters'] = [];
        }

        return $settings;
    }

    /**
     * Validate the variant's rendition groups and drop audio they replace.
     */
    protected function variantSettings(HlsVariant $variant, array $settings): array
    {
        foreach ($variant->groups() as $type => $group) {
            if ($group !== null && ! array_any(
                $this->renditions,
                fn ($item): bool => $item['type'] === $type && $item['group'] === $group,
            )) {
                throw new InvalidArgumentException("The HLS rendition group [{$group}] is not defined.");
            }
        }

        if ($variant->groups()['AUDIO'] !== null) {
            $settings['omitAudio'] = $settings['maps'] === [];
            $settings['audio'] = [];
            $settings['audioFilters'] = [];
        }

        return $settings;
    }

    /**
     * Ensure every variant and the renditions it references share one clip.
     *
     * @param  list<ExportOutput>  $outputs
     */
    protected function ensureRenditionClipsMatch(array $outputs): void
    {
        foreach ($this->variants as $name => $variant) {
            if (isset($this->renditions[$name])) {
                continue;
            }

            foreach ($variant->groups() as $type => $group) {
                foreach ($this->renditions as $id => $rendition) {
                    if ($group !== null && $rendition['type'] === $type && $rendition['group'] === $group
                        && $outputs[array_search($name, array_keys($this->variants), true)]->clipSettings()
                            !== $outputs[array_search($id, array_keys($this->variants), true)]->clipSettings()) {
                        throw new InvalidArgumentException(
                            'Variants and their reusable renditions require the same clip.'
                        );
                    }
                }
            }
        }
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

        $remaining = array_diff(array_keys($this->variants), array_keys($this->renditions));
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
            $part->variants = array_intersect_key($part->variants, array_flip($members));
            $part->renditions = array_intersect_key($part->renditions, array_flip($members));
            $directory = "{$prefix}/parts/".count($parts);
            $part->variantPaths = array_map(
                fn ($paths): array => array_map(
                    fn ($value): ?string => $value === null ? null : "{$directory}/custom/{$value}",
                    $paths,
                ),
                array_intersect_key($part->variantPaths, array_flip($members)),
            );
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
        return array_values(array_filter(
            array_keys(array_values($this->variants)),
            fn (int $index): bool => ! isset($this->renditions[array_keys($this->variants)[$index]]),
        ));
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
        $this->export->validateDestinations(array_map(fn (array $artifact): ExportOutput => new ExportOutput(
            $artifact['destination']['path'],
            ($artifact['target'] ?? $outputs[$artifact['output']])->filesystem(),
        ), $artifacts));

        return $artifacts;
    }

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
        ], $output->destination()['path'], null, false);

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
        $media = $this->rewriteMediaLines($index, $output, $path, $lines, $encryption);
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
        if ($lines[0] !== '#EXTM3U' || end($lines) !== '#EXT-X-ENDLIST'
            || count($targets) !== 1 || count(array_keys($lines, '#EXTM3U', true)) !== 1
            || count(array_keys($lines, '#EXT-X-ENDLIST', true)) !== 1
            || $types !== ($this->playlistType === 'live'
                ? []
                : ['#EXT-X-PLAYLIST-TYPE:'.strtoupper($this->playlistType)])) {
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
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    protected function rewriteMediaLines(
        int $index,
        ExportOutput $output,
        string $path,
        array $lines,
        ?HlsEncryption $encryption,
    ): array {
        $media = [
            'lines' => $lines,
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
        foreach ($lines as $lineIndex => $line) {
            if (str_starts_with($line, '#EXTINF:')) {
                $media['duration'] = $this->extinfDuration($line, $media['duration']);
            } elseif (str_starts_with($line, '#EXT-X-MAP:')) {
                $media = $this->rewriteInitialization($index, $output, $path, $lineIndex, $media);
            } elseif (str_starts_with($line, '#')) {
                if (! preg_match(
                    '/\A(?:#EXTM3U|#EXT-X-VERSION:[0-9]+|#EXT-X-TARGETDURATION:[0-9]+|#EXT-X-MEDIA-SEQUENCE:0|#EXT-X-PLAYLIST-TYPE:(?:VOD|EVENT)|#EXT-X-ENDLIST)\z/',
                    $line,
                )) {
                    throw MediaException::validation('Unexpected HLS playlist directive.', 'artifact');
                }
            } else {
                $media = $this->rewriteSegment($index, $output, $path, $lineIndex, $media, $encryption);
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
    protected function rewriteInitialization(
        int $index,
        ExportOutput $output,
        string $path,
        int $lineIndex,
        array $media,
    ): array {
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
    protected function rewriteSegment(
        int $index,
        ExportOutput $output,
        string $path,
        int $lineIndex,
        array $media,
        ?HlsEncryption $encryption,
    ): array {
        $name = array_keys($this->variants)[$index];
        $extension = $this->segmentExtension($index);
        $filename = "segment-{$index}-".sprintf('%08d', $media['count']).".{$extension}";
        if ($media['duration'] === null || $media['lines'][$lineIndex] !== $filename
            || ($this->segmentType === 'fmp4' && ! $this->isSubtitle($index) && $media['init'] === null)) {
            throw MediaException::validation(
                'An HLS playlist references an unowned or unordered segment.',
                'artifact',
            );
        }

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

        $duration = $media['duration'];
        $media['bytes'] += $size;
        $media['total'] += $duration;
        $media['peak'] = max($media['peak'], (int) ceil(8 * $size / $duration));
        $media['longest'] = max($media['longest'], $duration);
        $pattern = $this->variantPaths[$name]['segments']
            ?? dirname($output->destination()['path'])."/segment-%08d.{$extension}";
        $directory = dirname($pattern);
        $destination = $output->destination();
        $destination['path'] = ($directory === '.' ? '' : "{$directory}/")
            .sprintf(basename($pattern), $media['count']++);
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
     * Get the segment file extension of the output at the given index.
     */
    protected function segmentExtension(int $index): string
    {
        return $this->isSubtitle($index) ? 'vtt' : ($this->segmentType === 'fmp4' ? 'm4s' : 'ts');
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
        $actual = glob(dirname($path)."/segment-{$index}-*.{$this->segmentExtension($index)}") ?: [];
        sort($actual);
        $initializations = array_values(array_filter(
            glob(dirname($path).'/init-*.mp4') ?: [],
            fn (string $file): bool => (bool) preg_match('/\Ainit-'.$index.'(?:-|\.)/', basename($file)),
        ));
        if ($media['count'] === 0 || $media['duration'] !== null || $actual !== $media['owned']
            || $initializations !== ($media['init'] === null ? [] : [$media['init']])) {
            throw MediaException::validation('The HLS segment inventory is incomplete.', 'artifact');
        }
    }

    /**
     * Get the stream attributes FFmpeg reported for the variant, without its bandwidth.
     */
    protected function streamAttributes(int $index, string $path): string
    {
        $info = $this->isSubtitle($index) ? [] : $this->playlistLines(dirname($path)."/master-{$index}.m3u8");
        $attributes = '';
        foreach ($info as $line) {
            if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                $attributes = trim(preg_replace('/(?:^|,)(?:AVERAGE-)?BANDWIDTH=[0-9]+/', '', substr($line, 18)), ',');
            }
        }

        return $attributes;
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
     * Build the master playlist lines, timestamping subtitle renditions along the way.
     *
     * @param  array<int|string, array<string, mixed>>  $streamInfo
     * @return list<string>
     */
    protected function masterPlaylistLines(array $streamInfo, string $masterPath): array
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:'.($this->segmentType === 'fmp4' ? '7' : '3')];
        foreach ($this->renditions as $name => $rendition) {
            if ($rendition['type'] === 'SUBTITLES') {
                $overhead = $this->timestampSubtitleRendition($name, $rendition['group'], $streamInfo);
                $streamInfo[$name]['peak'] += $overhead;
                $streamInfo[$name]['average'] += $overhead;
            }

            $lines[] = $this->mediaTag($rendition)
                .',URI="'.HlsPresentation::relativeUrl($streamInfo[$name]['path'], $masterPath).'"';
        }

        foreach ($this->variants as $name => $variant) {
            if (isset($this->renditions[$name])) {
                continue;
            }

            array_push($lines, ...$this->streamLines($name, $variant, $streamInfo, $masterPath));
        }

        return $lines;
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
     * Build the EXT-X-MEDIA tag of an alternate rendition, without its URI.
     */
    protected function mediaTag(array $rendition): string
    {
        $tag = "#EXT-X-MEDIA:TYPE={$rendition['type']},GROUP-ID=\"{$rendition['group']}\""
            .",NAME=\"{$rendition['name']}\""
            .',DEFAULT='.($rendition['default'] ? 'YES' : 'NO')
            .',AUTOSELECT='.($rendition['autoselect'] ? 'YES' : 'NO');
        if ($rendition['language'] !== null) {
            $tag .= ",LANGUAGE=\"{$rendition['language']}\"";
        }

        if ($rendition['type'] === 'SUBTITLES') {
            $tag .= ',FORCED='.($rendition['forced'] ? 'YES' : 'NO');
        } elseif ($rendition['channels'] !== null) {
            $tag .= ",CHANNELS=\"{$rendition['channels']}\"";
        }

        return $tag;
    }

    /**
     * Build the EXT-X-STREAM-INF tag and URI of a variant, including its rendition groups.
     *
     * @param  array<int|string, array<string, mixed>>  $streamInfo
     * @return array{0: string, 1: string}
     */
    protected function streamLines(int|string $name, HlsVariant $variant, array $streamInfo, string $masterPath): array
    {
        $info = $streamInfo[$name];
        $groups = '';
        foreach ($variant->groups() as $type => $group) {
            if ($group === null) {
                continue;
            }

            $groups .= ",{$type}=\"{$group}\"";
            $members = array_keys(array_filter(
                $this->renditions,
                fn (array $item): bool => $item['type'] === $type && $item['group'] === $group,
            ));
            $info['peak'] += max(array_map(fn (int|string $member): int => $streamInfo[$member]['peak'], $members));
            $info['average'] += max(array_map(
                fn (int|string $member): int => $streamInfo[$member]['average'],
                $members,
            ));
            if ($type === 'AUDIO') {
                $info['attributes'] = $this->withAudioCodecs($info['attributes'], [$name, ...$members], $streamInfo);
            }
        }

        return [
            "#EXT-X-STREAM-INF:BANDWIDTH={$info['peak']},AVERAGE-BANDWIDTH={$info['average']}"
                .($info['attributes'] === '' ? '' : ",{$info['attributes']}").$groups,
            HlsPresentation::relativeUrl($info['path'], $masterPath),
        ];
    }

    /**
     * Replace the CODECS attribute with the codecs of the variant and its audio renditions.
     *
     * @param  list<int|string>  $members
     * @param  array<int|string, array<string, mixed>>  $streamInfo
     */
    protected function withAudioCodecs(string $attributes, array $members, array $streamInfo): string
    {
        $codecs = [];
        foreach ($members as $member) {
            if (preg_match('/(?:^|,)CODECS="([^"]+)"/', $streamInfo[$member]['attributes'], $match)) {
                array_push($codecs, ...explode(',', $match[1]));
            }
        }

        $attributes = trim(preg_replace('/(?:^|,)CODECS="[^"]+"/', '', $attributes), ',');
        if ($codecs !== []) {
            $attributes .= ($attributes === '' ? '' : ',').'CODECS="'.implode(',', array_unique($codecs)).'"';
        }

        return $attributes;
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
     */
    protected function writePlaylist(
        string $path,
        array $lines,
        string $destination,
        ?string $variant,
        bool $hook = true,
    ): void {
        $bytes = implode("\n", $lines)."\n";
        if (strlen($bytes) > Media::MAX_MANIFEST_BYTES) {
            throw MediaException::validation('Finalized HLS playlists must fit within the 16 MiB limit.', 'artifact');
        }

        if ($hook && $this->playlistCallback instanceof Closure) {
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

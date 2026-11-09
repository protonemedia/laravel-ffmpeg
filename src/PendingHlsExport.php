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

class PendingHlsExport implements Exportable, MeasuresDuration, PackagesArtifacts, ShapesCommand, ShapesOutputs, SplitsTwoPass, ValidatesSelections, WritesManifest
{
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

    protected readonly PendingExport $export;

    protected array $variants = [];

    protected array $variantPaths = [];

    protected array $variantFiles = [];

    protected ?Closure $playlistCallback = null;

    protected ?HlsEncryption $encryption = null;

    protected array $keyReferences = [];

    protected string $segmentType = 'mpegts';

    protected string $playlistType = 'vod';

    protected array $renditions = [];

    protected ?float $presentationDuration = null;

    protected array $provenance = [];

    protected ?Workspace $workspace = null;

    public function __construct(protected readonly Media $media, string|Filesystem $disk, protected readonly float $segmentDuration)
    {
        if (! is_finite($segmentDuration) || $segmentDuration < 0.001 || $segmentDuration > 86400) {
            throw new InvalidArgumentException('HLS segment duration must be between 0.001 and 86400 seconds.');
        }

        $this->export = new PendingExport($media, $disk, $this, 'hls-playlist');
        $this->export->video('libx264')->audio('aac');
    }

    /** Returns the stable FFmpeg input index; staging reuse never removes an input. */
    public function addInput(Media $media): int
    {
        return $this->export->addInput($media);
    }

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

    public function twoPass(): self
    {
        $this->export->twoPass();

        return $this;
    }

    /** @internal Parallel queueing writes every part to one shared named disk. */
    public function filesystem(): string|Filesystem
    {
        return $this->export->filesystem();
    }

    public function playlistType(string $type): self
    {
        $this->export->assertOutputMutable();
        $type = strtolower($type);
        if (! in_array($type, ['vod', 'event', 'live'], true)) {
            throw new InvalidArgumentException('HLS playlist type must be vod, event, or live.');
        }

        $this->playlistType = $type;

        return $this;
    }

    /** Use fragmented MP4 with one owned initialization file per rendition. AES-128 is MPEG-TS only. */
    public function fmp4(): self
    {
        $this->export->assertOutputMutable();
        if ($this->encryption instanceof HlsEncryption) {
            throw new InvalidArgumentException('AES-128 encryption is supported only for MPEG-TS HLS.');
        }

        $this->segmentType = 'fmp4';

        return $this;
    }

    public function variant(string $name, callable $configure, ?string $playlist = null, ?string $segments = null, ?string $init = null): self
    {
        $this->export->assertOutputMutable();

        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/', $name) || isset($this->variants[$name])) {
            throw new InvalidArgumentException('HLS variant names must be unique simple path components.');
        }

        $paths = ['playlist' => $playlist, 'segments' => $segments, 'init' => $init];
        foreach ($paths as $type => $path) {
            if ($path === null) {
                continue;
            }

            $path = new ExportOutput($path, $this->export->filesystem())->destination()['path'];
            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), match ($type) {
                'playlist' => ['m3u8'], 'init' => ['mp4'], default => ['ts', 'm4s', 'vtt'],
            }, true)
                || ($type === 'segments' && (! preg_match('/\A[^%]*%(?:0[1-9])?d[^%]*\z/', $path)
                    || str_contains(dirname($path), '%')))) {
                throw new InvalidArgumentException('HLS requires m3u8 playlists, mp4 initialization files, and ts, m4s, or vtt segments with one %d or %01d through %09d filename placeholder.');
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
        $this->export->assertOutputMutable();
        $this->playlistCallback = Closure::fromCallable($callback);

        return $this;
    }

    /** Encode once, reference from any number of variants via HlsVariant::audioGroup(). */
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
        return $this->rendition('AUDIO', $group, $name, $configure, $language, $default, $autoselect, false, $channels, $playlist, $segments, $init);
    }

    /** A full-presentation WebVTT segment, selected with an explicit subtitle map. */
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
        return $this->rendition('SUBTITLES', $group, $name, $configure, $language, $default, $autoselect, $forced, null, $playlist, $segments, null);
    }

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
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/', $group)
            || $name === '' || ! preg_match('//u', $name) || preg_match('/["\\\\\x00-\x1F\x7F-\x9F]/u', $name)
            || ($language !== null && ! preg_match('/\A[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*\z/', $language))
            || ($channels !== null && ! preg_match('/\A[1-9][0-9]*(?:\/[A-Z0-9]+)?\z/', $channels))
            || ($default && ! $autoselect)) {
            throw new InvalidArgumentException('Invalid HLS rendition attributes; DEFAULT requires AUTOSELECT.');
        }

        foreach ($this->renditions as $rendition) {
            if ($rendition['type'] === $type && $rendition['group'] === $group
                && ($rendition['name'] === $name || ($default && $rendition['default']))) {
                throw new InvalidArgumentException('Rendition names and the default must be unique within each group.');
            }
        }

        $id = 'rendition-'.strtolower($type).'-'.$group.'-'.count($this->renditions);
        $this->variant($id, $configure, $playlist, $segments, $init);
        $this->renditions[$id] = ['type' => $type, 'group' => $group, 'name' => $name, 'language' => $language, 'default' => $default, 'autoselect' => $autoselect, 'forced' => $forced, 'channels' => $channels];

        return $this;
    }

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

    /** @internal */
    public function outputs(array $default, ?string $path): array
    {
        if (count($this->variants) === count($this->renditions)) {
            throw new InvalidArgumentException('An HLS export requires at least one variant.');
        }

        if ($this->encryption instanceof HlsEncryption && array_any($this->renditions, fn ($item): bool => $item['type'] === 'SUBTITLES')) {
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
            $rendition = $this->renditions[$name] ?? null;
            $subtitle = ($rendition['type'] ?? null) === 'SUBTITLES';
            $segments = $this->variantPaths[$name]['segments'];
            if (($segments !== null && strtolower(pathinfo($segments, PATHINFO_EXTENSION)) !== ($subtitle ? 'vtt' : ($this->segmentType === 'fmp4' ? 'm4s' : 'ts')))
                || ($this->variantPaths[$name]['init'] !== null && $this->segmentType !== 'fmp4')) {
                throw new InvalidArgumentException('Segment and initialization paths must match the selected HLS packaging.');
            }

            $settings = $variant->inherit($this->export->outputConfiguration());
            if ($rendition !== null) {
                if ($settings['maps'] === [] || array_any($variant->groups(), fn ($group): bool => $group !== null)) {
                    throw new InvalidArgumentException('Alternate renditions require explicit maps and cannot reference groups.');
                }

                $settings['omitVideo'] = false;
                $settings['omitAudio'] = false;
                $settings['video'] = [];
                $settings['videoFilters'] = [];
                if ($subtitle) {
                    $settings['audio'] = [];
                    $settings['audioFilters'] = [];
                }
            } else {
                foreach ($variant->groups() as $type => $group) {
                    if ($group !== null && ! array_any($this->renditions, fn ($item): bool => $item['type'] === $type && $item['group'] === $group)) {
                        throw new InvalidArgumentException('A variant references an undefined HLS rendition group.');
                    }
                }

                if ($variant->groups()['AUDIO'] !== null) {
                    $settings['omitAudio'] = $settings['maps'] === [];
                    $settings['audio'] = [];
                    $settings['audioFilters'] = [];
                }
            }

            $outputs[] = new ExportOutput($this->variantPaths[$name]['playlist'] ?? $directory.$name.'/index.m3u8', $this->export->filesystem())->configure($settings);
        }

        $outputs[] = $master;
        foreach ($this->variants as $name => $variant) {
            if (isset($this->renditions[$name])) {
                continue;
            }

            foreach ($variant->groups() as $type => $group) {
                foreach ($this->renditions as $id => $rendition) {
                    if ($group !== null && $rendition['type'] === $type && $rendition['group'] === $group
                        && $outputs[array_search($name, array_keys($this->variants), true)]->clipSettings()
                            !== $outputs[array_search($id, array_keys($this->variants), true)]->clipSettings()) {
                        throw new InvalidArgumentException('Variants and their reusable renditions require the same clip.');
                    }
                }
            }
        }

        return $outputs;
    }

    /** @internal Keep variants sharing alternate groups together so each rendition encodes once. */
    public function queueParts(string $path, string $prefix): array
    {
        $this->outputs([], $path);
        if ($this->playlistType !== 'vod') {
            throw new InvalidArgumentException('Parallel HLS composition requires VOD playlists. Use a single queued export for event or live playlist types.');
        }

        if ($this->playlistCallback instanceof Closure || in_array('-filter_complex', $this->export->inspect(), true)) {
            throw new InvalidArgumentException('Parallel HLS does not accept custom playlist callbacks or complex graphs. Use a single queued export.');
        }

        $remaining = array_diff(array_keys($this->variants), array_keys($this->renditions));
        foreach ($this->renditions as $rendition) {
            if (! array_any($remaining, fn (int|string $name): bool => $this->variants[$name]->groups()[$rendition['type']] === $rendition['group'])) {
                throw new InvalidArgumentException('Parallel HLS requires every alternate rendition group to be referenced by a variant.');
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
                            $groups[$type.':'.$group] = true;
                        }
                    }
                }

                foreach ($remaining as $index => $name) {
                    foreach ($this->variants[$name]->groups() as $type => $group) {
                        if ($group !== null && isset($groups[$type.':'.$group])) {
                            $members[] = $name;
                            unset($remaining[$index]);
                            break;
                        }
                    }
                }
            } while (count($members) !== $previous);

            foreach ($this->renditions as $id => $rendition) {
                if (isset($groups[$rendition['type'].':'.$rendition['group']])) {
                    $members[] = $id;
                }
            }

            $part = new ExportPlan($this)->restore();
            $part->variants = array_intersect_key($part->variants, array_flip($members));
            $part->renditions = array_intersect_key($part->renditions, array_flip($members));
            $directory = $prefix.'/parts/'.count($parts);
            $part->variantPaths = array_map(fn ($paths): array => array_map(
                fn ($value): ?string => $value === null ? null : $directory.'/custom/'.$value, $paths),
                array_intersect_key($part->variantPaths, array_flip($members)));
            $parts[] = [$part, $directory.'/master.m3u8'];
        }

        return $parts;
    }

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
            // The workspace belongs to one execution; a saved builder may still be queued.
            $this->workspace = null;
        }

        [$keys, $files] = $result->files()->partition(fn ($file): bool => $file->role === 'hls-key');

        $renditions = [];
        foreach ($this->renditions as $id => $rendition) {
            $renditions[$rendition['type']][$rendition['group']][$rendition['name']] = $id;
        }

        return new HlsExportResult([...$files, ...$keys], $result->processes()->all(), $this->variantFiles, $renditions, $this->keyReferences,
            variantGroups: array_map(fn ($variant): array => array_filter($variant->groups(), fn ($group): bool => $group !== null), $this->variants), provenance: $this->provenance);
    }

    /** @internal */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        foreach ($outputs as $output) {
            if (in_array('-f', $output->outputConfiguration()['outputOptions'], true)) {
                throw new InvalidArgumentException('HLS owns the output muxer.');
            }
        }

        $result = [];
        foreach ($default as $token) {
            $index = array_search($token, $outputPaths ?? array_map(fn (int|string $i): string => '[output:'.$i.']', array_keys($outputs)), true);
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
                $result[] = 'segment-'.$index.'-%08d.'.($this->segmentType === 'fmp4' ? 'm4s' : 'ts');
                $result[] = '-master_pl_name';
                $result[] = 'master-'.$index.'.m3u8';
                if ($this->playlistType !== 'live') {
                    $result[] = '-hls_playlist_type';
                    $result[] = $this->playlistType;
                }

                if ($this->segmentType === 'fmp4') {
                    $result[] = '-hls_fmp4_init_filename';
                    $result[] = 'init-'.$index.'.mp4';
                }
            }

            $result[] = $token;
        }

        return $result;
    }

    /** @internal */
    public function selections(array $expected): void
    {
        foreach (array_keys($this->variants) as $index => $name) {
            if (isset($this->renditions[$name])) {
                $type = $this->renditions[$name]['type'] === 'AUDIO' ? 'audio' : 'subtitle';
                if ($expected[$index] !== [$type]) {
                    throw MediaException::probing('An HLS rendition must map exactly one stream of its declared type.', 'mapping');
                }
            } elseif ($this->variants[$name]->groups()['AUDIO'] !== null && in_array('audio', $expected[$index], true)) {
                throw MediaException::probing('Variants with an alternate audio group must not map muxed audio.', 'mapping');
            }
        }
    }

    /** @internal Alternate renditions are packaged, not two-pass encoded. */
    public function twoPassOutputs(array $default, array $outputs): array
    {
        return array_values(array_filter(array_keys(array_values($this->variants)), fn (int $index): bool => ! isset($this->renditions[array_keys($this->variants)[$index]])));
    }

    /** @internal */
    public function analysisCommand(array $command): array
    {
        $analysis = [];
        for ($index = 0; $index < count($command); $index++) {
            if (in_array($command[$index], ['-f', '-hls_time', '-hls_list_size', '-hls_playlist_type', '-hls_segment_type',
                '-start_number', '-hls_segment_filename', '-master_pl_name', '-hls_fmp4_init_filename'], true)) {
                $index++;
            } else {
                $analysis[] = $command[$index];
            }
        }

        return $analysis;
    }

    /** @internal */
    public function outputPaths(array $default, array $outputs, Workspace $workspace): array
    {
        $this->workspace = $workspace;

        return array_map(fn (int|string $index): string => $workspace->path.'/'.($this->isSubtitle($index) ? 'segment-'.$index.'-00000000.vtt' : 'playlist-'.$index.'.m3u8'), array_keys($outputs));
    }

    protected function isSubtitle(int $index): bool
    {
        return ($this->renditions[array_keys($this->variants)[$index] ?? '']['type'] ?? null) === 'SUBTITLES';
    }

    /** @internal Subtitle playlists need the presentation duration later, in artifacts(). */
    public function duration(array $metadata, ?float $default): ?float
    {
        $this->presentationDuration = $default;
        if (array_any($this->renditions, fn ($item): bool => $item['type'] === 'SUBTITLES')
            && ($this->presentationDuration === null || $this->presentationDuration <= 0)) {
            throw new InvalidArgumentException('Subtitle renditions require a known presentation duration without a complex timing graph.');
        }

        return $this->presentationDuration;
    }

    /** @internal */
    public function artifacts(array $default, array $outputs, array $paths): array
    {
        $segments = [];
        $playlists = [];
        $encryption = $this->encryption instanceof HlsEncryption ? clone $this->encryption : null;
        $masterLines = ['#EXTM3U', '#EXT-X-VERSION:'.($this->segmentType === 'fmp4' ? '7' : '3')];
        $streamInfo = [];
        foreach (array_slice($outputs, 0, -1) as $index => $output) {
            if ($this->isSubtitle($index)) {
                $clip = $output->clipSettings();
                $duration = $clip === null ? $this->presentationDuration : min($clip['duration'], $this->presentationDuration - $clip['start']);
                if ($duration <= 0) {
                    throw MediaException::validation('An HLS subtitle rendition has no presentation duration.', 'artifact');
                }

                $paths[$index] = dirname($paths[$index]).'/playlist-'.$index.'.m3u8';
                $this->writePlaylist($paths[$index], ['#EXTM3U', '#EXT-X-VERSION:3', '#EXT-X-TARGETDURATION:'.max(1, (int) ceil($duration)),
                    '#EXT-X-MEDIA-SEQUENCE:0', ...($this->playlistType === 'live' ? [] : ['#EXT-X-PLAYLIST-TYPE:'.strtoupper($this->playlistType)]), '#EXTINF:'.sprintf('%.6f', $duration).',',
                    'segment-'.$index.'-00000000.vtt', '#EXT-X-ENDLIST'], $output->destination()['path'], null, false);
            }

            $lines = $this->playlistLines($paths[$index]);
            $targets = array_keys(array_filter($lines, fn ($line): bool => str_starts_with($line, '#EXT-X-TARGETDURATION:')));
            $types = array_values(array_filter($lines, fn ($line): bool => str_starts_with($line, '#EXT-X-PLAYLIST-TYPE:')));
            if ($lines[0] !== '#EXTM3U' || end($lines) !== '#EXT-X-ENDLIST'
                || count($targets) !== 1 || count(array_keys($lines, '#EXTM3U', true)) !== 1
                || count(array_keys($lines, '#EXT-X-ENDLIST', true)) !== 1
                || $types !== ($this->playlistType === 'live' ? [] : ['#EXT-X-PLAYLIST-TYPE:'.strtoupper($this->playlistType)])) {
                throw MediaException::validation('FFmpeg did not produce a complete '.$this->playlistType.' playlist.', 'artifact');
            }

            $duration = null;
            $longest = 0;
            $count = 0;
            $bytes = 0;
            $total = 0;
            $peak = 0;
            $owned = [];
            $keyTags = [];
            $init = null;
            $start = null;
            $streamType = 'subtitle';
            $subtitlePath = null;
            $name = array_keys($this->variants)[$index];
            $extension = $this->isSubtitle($index) ? 'vtt' : ($this->segmentType === 'fmp4' ? 'm4s' : 'ts');
            $pattern = $this->variantPaths[$name]['segments'] ?? dirname($output->destination()['path']).'/segment-%08d.'.$extension;
            $directory = dirname($pattern);
            foreach ($lines as $lineIndex => &$line) {
                if (str_starts_with($line, '#EXTINF:')) {
                    if ($duration !== null || ! preg_match('/\A#EXTINF:([0-9]+(?:\.[0-9]+)?),\z/', $line, $match)
                        || ! is_finite($duration = (float) $match[1]) || $duration <= 0) {
                        throw MediaException::validation('Invalid HLS segment duration.', 'artifact');
                    }
                } elseif (str_starts_with($line, '#EXT-X-MAP:')) {
                    if ($this->segmentType !== 'fmp4' || $init !== null || $count !== 0
                        || $line !== '#EXT-X-MAP:URI="init-'.$index.'.mp4"') {
                        throw MediaException::validation('An HLS playlist references an unexpected initialization file.', 'artifact');
                    }

                    $init = dirname($paths[$index]).'/init-'.$index.'.mp4';
                    $this->export->validateArtifact($init, 'hls-init');
                    $destination = $output->destination();
                    $destination['path'] = $this->variantPaths[$name]['init'] ?? (dirname($destination['path']) === '.' ? '' : dirname($destination['path']).'/').'init.mp4';
                    $line = '#EXT-X-MAP:URI="'.HlsPresentation::relativeUrl($destination['path'], $output->destination()['path']).'"';
                    $this->variantFiles[$name][$destination['path']] = true;
                    $segments[] = ['path' => $init, 'output' => $index, 'role' => 'hls-init', 'destination' => $destination];
                } elseif (str_starts_with($line, '#')) {
                    if (! preg_match('/\A(?:#EXTM3U|#EXT-X-VERSION:[0-9]+|#EXT-X-TARGETDURATION:[0-9]+|#EXT-X-MEDIA-SEQUENCE:0|#EXT-X-PLAYLIST-TYPE:(?:VOD|EVENT)|#EXT-X-ENDLIST)\z/', $line)) {
                        throw MediaException::validation('Unexpected HLS playlist directive.', 'artifact');
                    }
                } else {
                    $filename = 'segment-'.$index.'-'.sprintf('%08d', $count).'.'.$extension;
                    if ($duration === null || $line !== $filename || ($this->segmentType === 'fmp4' && ! $this->isSubtitle($index) && $init === null)) {
                        throw MediaException::validation('An HLS playlist references an unowned or unordered segment.', 'artifact');
                    }

                    $local = dirname($paths[$index]).'/'.$filename;
                    $size = $this->export->validateArtifact($local, 'hls-segment');
                    $owned[] = $local;
                    $encrypted = $this->isSubtitle($index) ? null : $encryption?->segment($local, $count, $output->destination()['path'], $name);
                    if ($encrypted !== null) {
                        $keyTags[$lineIndex] = $encrypted['tag'];
                        $size = $this->export->validateArtifact($encrypted['path'], 'hls-segment');
                    }

                    $bytes += $size;
                    $total += $duration;
                    $peak = max($peak, (int) ceil(8 * $size / $duration));
                    $longest = max($longest, $duration);
                    $destination = $output->destination();
                    $destination['path'] = ($directory === '.' ? '' : $directory.'/').sprintf(basename($pattern), $count++);
                    $line = HlsPresentation::relativeUrl($destination['path'], $output->destination()['path']);
                    $this->variantFiles[$name][$destination['path']] = true;
                    $probePath = $count === 1 && $init !== null ? PendingExport::fragmentProbe($init, $local, $local.'.probe.mp4') : $local;
                    if ($this->isSubtitle($index)) {
                        $subtitlePath = $local;
                    } elseif ($count === 1) {
                        $metadata = $this->media->probeArtifact($probePath, $this->workspace);
                        $stream = $metadata->video() ?? $metadata->audio();
                        $streamType = $metadata->video() instanceof Stream ? 'video' : 'audio';
                        $start = $stream?->get('start_time');
                    }

                    $segments[] = ['path' => $encrypted['path'] ?? $local, 'probePath' => $probePath, 'output' => $index,
                        'role' => 'hls-segment', 'destination' => $destination, 'probe' => $count === 1];
                    $duration = null;
                }
            }

            unset($line);
            $actual = glob(dirname($paths[$index]).'/segment-'.$index.'-*.'.$extension) ?: [];
            sort($actual);
            $initializations = array_values(array_filter(glob(dirname($paths[$index]).'/init-*.mp4') ?: [],
                fn (string $file): bool => (bool) preg_match('/\Ainit-'.$index.'(?:-|\.)/', basename($file))));
            if ($count === 0 || $duration !== null || $actual !== $owned || $initializations !== ($init === null ? [] : [$init])) {
                throw MediaException::validation('The HLS segment inventory is incomplete.', 'artifact');
            }

            $info = $this->isSubtitle($index) ? [] : $this->playlistLines(dirname($paths[$index]).'/master-'.$index.'.m3u8');
            $attributes = '';
            foreach ($info as $line) {
                if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
                    $attributes = trim(preg_replace('/(?:^|,)(?:AVERAGE-)?BANDWIDTH=[0-9]+/', '', substr($line, 18)), ',');
                }
            }

            $streamInfo[$name] = ['peak' => $peak, 'average' => (int) ceil(8 * $bytes / $total), 'attributes' => $attributes,
                'path' => $output->destination()['path'], 'start' => $start, 'subtitle' => $subtitlePath, 'duration' => $total];
            $this->provenance[$output->destination()['path']] = ['version' => 9, 'start' => $start, 'duration' => $total,
                'clip' => $output->clipSettings(), 'segmentType' => $this->segmentType, 'subtitle' => $this->isSubtitle($index),
                'streamType' => $streamType];
            $lines[$targets[0]] = '#EXT-X-TARGETDURATION:'.max(1, (int) ceil($longest));
            $rendered = [];
            foreach ($lines as $lineIndex => $line) {
                if (isset($keyTags[$lineIndex])) {
                    $rendered[] = $keyTags[$lineIndex];
                }

                $rendered[] = $line;
            }

            $this->writePlaylist($paths[$index], $rendered, $output->destination()['path'], $name);
            $this->variantFiles[$name][$output->destination()['path']] = true;
            $playlists[] = ['path' => $paths[$index], 'output' => $index, 'role' => 'hls-playlist', 'destination' => $output->destination()];
        }

        $master = array_key_last($outputs);
        foreach ($this->renditions as $name => $rendition) {
            if ($rendition['type'] === 'SUBTITLES') {
                $starts = [];
                foreach ($this->variants as $variantName => $variant) {
                    if (! isset($this->renditions[$variantName]) && $variant->groups()['SUBTITLES'] === $rendition['group']) {
                        $start = $streamInfo[$variantName]['start'];
                        if (! is_numeric($start) || ! is_finite((float) $start)) {
                            throw MediaException::validation('Cannot establish the HLS subtitle presentation clock.', 'artifact');
                        }

                        $starts[] = (int) round((float) $start * 90000);
                    }
                }

                if ($starts !== [] && max($starts) - min($starts) > 1) {
                    throw MediaException::validation('A shared subtitle group requires aligned variant presentation clocks.', 'artifact');
                }

                $added = $this->timestampWebVtt($streamInfo[$name]['subtitle'], $starts[0] ?? 0);
                $overhead = (int) ceil(8 * $added / $streamInfo[$name]['duration']);
                $streamInfo[$name]['peak'] += $overhead;
                $streamInfo[$name]['average'] += $overhead;
            }

            $tag = '#EXT-X-MEDIA:TYPE='.$rendition['type'].',GROUP-ID="'.$rendition['group'].'",NAME="'.$rendition['name'].'"'
                .',DEFAULT='.($rendition['default'] ? 'YES' : 'NO').',AUTOSELECT='.($rendition['autoselect'] ? 'YES' : 'NO');
            if ($rendition['language'] !== null) {
                $tag .= ',LANGUAGE="'.$rendition['language'].'"';
            }

            if ($rendition['type'] === 'SUBTITLES') {
                $tag .= ',FORCED='.($rendition['forced'] ? 'YES' : 'NO');
            } elseif ($rendition['channels'] !== null) {
                $tag .= ',CHANNELS="'.$rendition['channels'].'"';
            }

            $masterLines[] = $tag.',URI="'.HlsPresentation::relativeUrl($streamInfo[$name]['path'], $outputs[$master]->destination()['path']).'"';
        }

        foreach ($this->variants as $name => $variant) {
            if (isset($this->renditions[$name])) {
                continue;
            }

            $info = $streamInfo[$name];
            $groups = '';
            foreach ($variant->groups() as $type => $group) {
                if ($group === null) {
                    continue;
                }

                $groups .= ','.$type.'="'.$group.'"';
                $members = array_keys(array_filter($this->renditions, fn (array $item): bool => $item['type'] === $type && $item['group'] === $group));
                $info['peak'] += max(array_map(fn (int|string $member): int => $streamInfo[$member]['peak'], $members));
                $info['average'] += max(array_map(fn (int|string $member): int => $streamInfo[$member]['average'], $members));
                if ($type === 'AUDIO') {
                    $codecs = [];
                    foreach ([$name, ...$members] as $member) {
                        if (preg_match('/(?:^|,)CODECS="([^"]+)"/', $streamInfo[$member]['attributes'], $match)) {
                            array_push($codecs, ...explode(',', $match[1]));
                        }
                    }

                    $info['attributes'] = trim(preg_replace('/(?:^|,)CODECS="[^"]+"/', '', $info['attributes']), ',');
                    if ($codecs !== []) {
                        $info['attributes'] .= ($info['attributes'] === '' ? '' : ',').'CODECS="'.implode(',', array_unique($codecs)).'"';
                    }
                }
            }

            $masterLines[] = '#EXT-X-STREAM-INF:BANDWIDTH='.$info['peak'].',AVERAGE-BANDWIDTH='.$info['average']
                .($info['attributes'] === '' ? '' : ','.$info['attributes']).$groups;
            $masterLines[] = HlsPresentation::relativeUrl($info['path'], $outputs[$master]->destination()['path']);
        }

        $this->writePlaylist($paths[$master], $masterLines, $outputs[$master]->destination()['path'], null);
        $this->keyReferences = $encryption?->references() ?? [];
        $artifacts = [...($encryption?->artifacts() ?? []), ...$segments, ...$playlists, [
            'path' => $paths[$master], 'output' => $master, 'role' => 'hls-master', 'destination' => $outputs[$master]->destination(),
        ]];
        $this->export->validateDestinations(array_map(fn (array $artifact): ExportOutput => new ExportOutput(
            $artifact['destination']['path'], ($artifact['target'] ?? $outputs[$artifact['output']])->filesystem(),
        ), $artifacts));

        return $artifacts;
    }

    protected function timestampWebVtt(string $path, int $ticks): int
    {
        $input = @fopen($path, 'rb');
        $output = @fopen($path.'.timed', 'wb');
        try {
            if ($input === false || $output === false || fgets($input) !== "WEBVTT\n") {
                throw MediaException::validation('Invalid generated WebVTT header.', 'artifact');
            }

            // HLS clocks are unsigned 33-bit MPEG timestamps, including with fragmented MP4.
            $ticks = (($ticks % 8589934592) + 8589934592) % 8589934592;
            $header = 'X-TIMESTAMP-MAP=LOCAL:00:00:00.000,MPEGTS:'.$ticks."\n";
            if (fwrite($output, "WEBVTT\n".$header) !== 7 + strlen($header)
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

        if (! chmod($path.'.timed', 0600) || ! rename($path.'.timed', $path)) {
            throw MediaException::validation('Cannot finalize timestamped WebVTT.', 'filesystem');
        }

        return strlen($header);
    }

    protected function playlistLines(string $path): array
    {
        $bytes = @file_get_contents($path, length: 16777217);
        if ($bytes === false || trim($bytes) === '' || strlen($bytes) > 16777216 || str_contains($bytes, "\0")) {
            throw MediaException::validation('Cannot read the generated HLS playlist within its 16 MiB limit.', 'artifact');
        }

        return array_values(array_filter(explode("\n", str_replace("\r\n", "\n", rtrim($bytes, "\r\n"))), fn ($line): bool => $line !== ''));
    }

    protected function writePlaylist(string $path, array $lines, string $destination, ?string $variant, bool $hook = true): void
    {
        $bytes = implode("\n", $lines)."\n";
        if (strlen($bytes) > 16777216) {
            throw MediaException::validation('Finalized HLS playlists must fit within the 16 MiB limit.', 'artifact');
        }

        if ($hook && $this->playlistCallback instanceof Closure) {
            $modified = ($this->playlistCallback)($bytes, $destination, $variant);
            if (! is_string($modified) || strlen($modified) > 16777216) {
                throw MediaException::validation('Playlist hooks must return text within the 16 MiB limit.', 'artifact');
            }

            $bytes = str_replace("\r\n", "\n", $modified);
            $modified = array_values(array_filter(explode("\n", rtrim($bytes, "\n")), fn ($line): bool => $line !== ''));
            if (! str_starts_with($bytes, "#EXTM3U\n") || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $bytes) || ! preg_match('//u', $bytes)) {
                throw MediaException::validation('Playlist hooks must return a UTF-8 playlist without control characters.', 'artifact');
            }

            $position = 0;
            foreach ($modified as $line) {
                if ($line === ($lines[$position] ?? null)) {
                    $position++;
                } elseif (! str_starts_with($line, '#') || (str_starts_with($line, '#EXT')
                    && (! preg_match('/\A#EXT-X-(?:START|SESSION-DATA|DATERANGE|PROGRAM-DATE-TIME):/', $line) || preg_match('/\bURI\s*=/i', $line)))) {
                    throw MediaException::validation('Playlist hooks may only add comments and non-resource START, SESSION-DATA, DATERANGE, or PROGRAM-DATE-TIME tags.', 'artifact');
                }
            }

            if ($position !== count($lines)) {
                throw MediaException::validation('Playlist hooks must preserve generated lines in order.', 'artifact');
            }
        }

        if (@file_put_contents($path, $bytes) !== strlen($bytes) || ! chmod($path, 0600)) {
            throw MediaException::validation('Cannot finalize the generated HLS playlist.', 'filesystem');
        }
    }
}

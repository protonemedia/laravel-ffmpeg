<?php

namespace ProtoneMedia\LaravelFFMpeg;

use DOMDocument;
use DOMElement;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\CommandCompiler;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\MeasuresDuration;
use ProtoneMedia\LaravelFFMpeg\Pipeline\PackagesArtifacts;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesCommand;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesOutputs;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ValidatesSelections;
use ProtoneMedia\LaravelFFMpeg\Pipeline\WritesManifest;

/**
 * A finite, unencrypted DASH export of H.264 and AAC in fragmented MP4.
 *
 * This builder makes no claims about live streaming or DRM.
 */
class PendingDashExport implements Exportable, MeasuresDuration, PackagesArtifacts, ShapesCommand, ShapesOutputs, ValidatesSelections, WritesManifest
{
    use Concerns\Clips;
    use Concerns\Fades;
    use Concerns\GlobalOptions;
    use Concerns\Metadata;
    use Concerns\OutputOptions;
    use Concerns\ResourceFilters;
    use Concerns\Resources;
    use Concerns\RunsExport;
    use Concerns\VideoFilters;
    use Conditionable, Macroable;

    /**
     * The export pipeline this builder configures.
     */
    protected readonly PendingExport $export;

    /**
     * The adaptation sets, keyed by name.
     *
     * Each set holds its type and the names of its representations.
     */
    protected array $sets = [];

    /**
     * The representations, keyed by name.
     */
    protected array $representations = [];

    /**
     * The destination paths each representation owns, collected during the save.
     */
    protected array $representationFiles = [];

    /**
     * Indicates if clear HLS playlists should share the DASH media files.
     */
    protected bool $sharedHls = false;

    /**
     * Create a new pending DASH export instance.
     */
    public function __construct(Media $media, string|Filesystem $disk, protected readonly float $segmentDuration)
    {
        if (! is_finite($segmentDuration) || $segmentDuration < 0.001 || $segmentDuration > 86400) {
            throw new InvalidArgumentException(
                'The DASH segment duration must be between 0.001 and 86400 seconds.'
            );
        }

        $this->export = new PendingExport($media, $disk, $this, 'dash-manifest');
        $this->export->video('libx264')->audio('aac');
    }

    /**
     * Add an input and get its stable FFmpeg input index for representation maps.
     */
    public function addInput(Media $media): int
    {
        return $this->export->addInput($media);
    }

    /**
     * Set the video codec and its rate control.
     */
    public function video(
        ?string $codec = null,
        mixed $bitrate = null,
        ?float $crf = null,
        ?string $preset = null,
    ): self {
        $this->export->video($codec, $bitrate, $crf, $preset);

        return $this;
    }

    /**
     * Set additional video encoder options.
     */
    public function videoOptions(array $tokens): self
    {
        $this->export->videoOptions($tokens);

        return $this;
    }

    /**
     * Set the signaled color characteristics without transforming the pixel values.
     */
    public function color(string $primaries, string $transfer, string $space, string $range = 'tv'): self
    {
        $this->export->color($primaries, $transfer, $space, $range);

        return $this;
    }

    /**
     * Set the audio codec and bitrate.
     */
    public function audio(?string $codec = null, mixed $bitrate = null): self
    {
        $this->export->audio($codec, $bitrate);

        return $this;
    }

    /**
     * Set additional audio encoder options.
     */
    public function audioOptions(array $tokens): self
    {
        $this->export->audioOptions($tokens);

        return $this;
    }

    /**
     * Add an audio filter expression.
     */
    public function audioFilter(string $expression): self
    {
        $this->export->audioFilter($expression);

        return $this;
    }

    /**
     * Set the linear amplitude multiplier of the audio.
     *
     * A multiplier of zero mutes the audio.
     */
    public function volume(float $multiplier): self
    {
        $this->export->volume($multiplier);

        return $this;
    }

    /**
     * Map the streams that match the given FFmpeg specifier.
     */
    public function map(string $specifier): self
    {
        $this->export->map($specifier);

        return $this;
    }

    /**
     * Map a video stream.
     */
    public function mapVideo(
        Stream|int|null $stream = null,
        ?int $input = null,
        ?int $track = null,
        bool $optional = false,
    ): self {
        $this->export->mapVideo($stream, $input, $track, $optional);

        return $this;
    }

    /**
     * Map an audio stream.
     */
    public function mapAudio(
        Stream|int|null $stream = null,
        ?int $input = null,
        ?int $track = null,
        ?string $language = null,
        bool $optional = false,
    ): self {
        $this->export->mapAudio($stream, $input, $track, $language, $optional);

        return $this;
    }

    /**
     * Define an adaptation set of the given type.
     */
    public function adaptationSet(string $name, string $type): self
    {
        $this->export->assertOutputMutable();
        $this->identifier($name);
        if (isset($this->sets[$name])) {
            throw new InvalidArgumentException("The DASH adaptation set [{$name}] is already defined.");
        }

        if (! in_array($type, ['video', 'audio'], true)) {
            throw new InvalidArgumentException("The DASH adaptation set type [{$type}] must be video or audio.");
        }

        $this->sets[$name] = ['type' => $type, 'representations' => []];

        return $this;
    }

    /**
     * Define a representation within the given adaptation set.
     *
     * @param  callable(DashRepresentation): mixed  $configure
     */
    public function representation(string $name, string $adaptationSet, callable $configure): self
    {
        $this->export->assertOutputMutable();
        $this->identifier($name);
        if (isset($this->representations[$name])) {
            throw new InvalidArgumentException("The DASH representation [{$name}] is already defined.");
        }

        if (! isset($this->sets[$adaptationSet])) {
            throw new InvalidArgumentException("The DASH adaptation set [{$adaptationSet}] is not defined.");
        }

        if (count($this->representations) >= 64) {
            throw new InvalidArgumentException('A DASH export supports at most 64 representations.');
        }

        $representation = new DashRepresentation;
        $configure($representation);
        $this->representations[$name] = $representation;
        $this->sets[$adaptationSet]['representations'][] = $name;

        return $this;
    }

    /**
     * Write clear HLS VOD playlists that reference the same initialization and media files.
     */
    public function sharedHls(bool $enabled = true): self
    {
        $this->export->assertOutputMutable();
        $this->sharedHls = $enabled;

        return $this;
    }

    /**
     * Ensure the given name is a simple path component.
     */
    protected function identifier(string $name): void
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/', $name)) {
            throw new InvalidArgumentException('DASH names must be simple path components of at most 64 characters.');
        }
    }

    /**
     * Get the stream type of the given representation.
     */
    protected function type(string $name): string
    {
        foreach ($this->sets as $set) {
            if (in_array($name, $set['representations'], true)) {
                return $set['type'];
            }
        }

        throw new InvalidArgumentException('Undefined DASH representation.');
    }

    /**
     * Get one output per representation followed by the manifest output.
     *
     * @internal
     */
    public function outputs(array $default, ?string $path): array
    {
        if ($this->representations === []) {
            throw new InvalidArgumentException('A DASH export requires at least one representation.');
        }

        foreach ($this->sets as $name => $set) {
            if ($set['representations'] === []) {
                throw new InvalidArgumentException("The DASH adaptation set [{$name}] has no representations.");
            }
        }

        if (! class_exists(DOMDocument::class)) {
            throw new InvalidArgumentException('DASH requires the PHP DOM XML extension.');
        }

        $manifest = new ExportOutput($path ?? 'manifest.mpd', $this->export->filesystem());
        $destination = $manifest->destination()['path'];
        if (strtolower(pathinfo($destination, PATHINFO_EXTENSION)) !== 'mpd') {
            throw new InvalidArgumentException('A DASH manifest destination must have an mpd extension.');
        }

        $prefix = dirname($destination) === '.' ? '' : dirname($destination).'/';
        $outputs = [];
        $clip = null;
        foreach ($this->representations as $name => $representation) {
            $settings = $representation->inherit($this->export->outputConfiguration());
            $type = $this->type($name);
            if ($settings['maps'] === []) {
                throw new InvalidArgumentException("The DASH representation [{$name}] requires an explicit map.");
            }

            if ($settings['omitAudio'] || $settings['omitVideo']) {
                throw new InvalidArgumentException("The DASH representation [{$name}] cannot omit streams.");
            }

            if ($settings['videoStreams'] !== [] || ($settings['audioStreams'] ?? []) !== []) {
                throw new InvalidArgumentException(
                    "The DASH representation [{$name}] cannot override per-track settings."
                );
            }

            $codec = $type === 'video' ? 'libx264' : 'aac';
            if (($settings[$type]['codec'] ?? null) !== $codec) {
                throw new InvalidArgumentException(
                    "The DASH representation [{$name}] must encode {$type} with [{$codec}]."
                );
            }

            if (($settings['clip']['seek'] ?? 'accurate') !== 'accurate') {
                throw new InvalidArgumentException("The DASH representation [{$name}] requires an accurate clip.");
            }

            if ($outputs !== [] && $settings['clip'] !== $clip) {
                throw new InvalidArgumentException('DASH representations require identical clips.');
            }

            $clip = $settings['clip'];
            if ($type === 'video' && array_intersect(
                ['g', 'keyint_min', 'sc_threshold', 'x264-params', 'x265-params'],
                array_keys($settings['video']),
            ) !== []) {
                throw new InvalidArgumentException('DASH owns video encoder GOP and segment alignment settings.');
            }

            foreach ($settings['outputOptions'] as $token) {
                if ($token === '-f'
                    || preg_match('/\A-(?:c|r|g|keyint_min|sc_threshold|x264-params|x265-params)(?::|\z)/', $token)) {
                    throw new InvalidArgumentException(
                        'DASH owns its muxer, stream codecs, frame rate, and segment alignment.'
                    );
                }
            }

            $settings[$type === 'video' ? 'audio' : 'video'] = [];
            $settings[$type === 'video' ? 'audioStreams' : 'videoStreams'] = [];
            $settings[$type === 'video' ? 'audioFilters' : 'videoFilters'] = [];
            $outputs[] = new ExportOutput("{$prefix}{$name}/index.mpd", $this->export->filesystem())
                ->configure($settings);
        }

        $outputs[] = $manifest->configure($this->export->outputConfiguration());

        return $outputs;
    }

    /**
     * Get the command with the DASH muxer options in front of each representation output.
     *
     * @internal
     */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        $result = [];
        $targets = $outputPaths ?? array_map(fn (int|string $i): string => "[output:{$i}]", array_keys($outputs));
        foreach ($default as $token) {
            $index = array_search($token, $targets, true);
            if ($index !== false) {
                if ($this->type(array_keys($this->representations)[$index]) === 'video') {
                    $result[] = '-force_key_frames:v';
                    $result[] = 'expr:gte(t,n_forced*'.CommandCompiler::timestamp($this->segmentDuration).')';
                    $result[] = '-flags:v';
                    $result[] = '+cgop';
                    $result[] = '-sc_threshold:v';
                    $result[] = '0';
                }

                $result[] = '-f';
                $result[] = 'dash';
                $result[] = '-seg_duration';
                $result[] = CommandCompiler::timestamp($this->segmentDuration);
                $result[] = '-use_template';
                $result[] = '1';
                $result[] = '-use_timeline';
                $result[] = '1';
                $result[] = '-window_size';
                $result[] = '0';
                $result[] = '-extra_window_size';
                $result[] = '0';
                $result[] = '-init_seg_name';
                $result[] = 'init.mp4';
                $result[] = '-media_seg_name';
                $result[] = 'segment-$Number%08d$.m4s';
            }

            $result[] = $token;
        }

        return $result;
    }

    /**
     * Ensure each representation maps exactly one stream of its declared type.
     *
     * @internal
     */
    public function selections(array $expected): void
    {
        foreach (array_keys($this->representations) as $index => $name) {
            if ($expected[$index] !== [$this->type($name)]) {
                throw MediaException::probing(
                    'A DASH representation must map exactly one stream of its declared type.',
                    'mapping',
                );
            }
        }
    }

    /**
     * Ensure the duration is known and keeps the segment inventory within its limit.
     *
     * @internal
     */
    public function duration(array $metadata, ?float $default): float
    {
        if ($default === null || $default <= 0) {
            throw new InvalidArgumentException('DASH requires a known positive presentation duration.');
        }

        if (ceil($default / $this->segmentDuration) * count($this->representations) > DashManifest::LIMIT) {
            throw new InvalidArgumentException('The DASH segment inventory must stay within 100000 segments.');
        }

        return $default;
    }

    /**
     * Get a private directory per representation followed by the manifest path.
     *
     * @internal
     */
    public function outputPaths(array $default, array $outputs, Workspace $workspace): array
    {
        $paths = [];
        foreach (array_keys($this->representations) as $name) {
            $directory = "{$workspace->path}/dash-{$name}";
            if (! mkdir($directory, 0700)) {
                throw MediaException::encoding('Cannot create the private DASH workspace.', 'filesystem');
            }

            $paths[] = "{$directory}/index.mpd";
        }

        $paths[] = "{$workspace->path}/manifest.mpd";

        return $paths;
    }

    /**
     * Run the export and publish the manifest and its representations.
     */
    public function save(?string $path = null): DashExportResult
    {
        $this->export->assertOutputMutable();
        if ($path === null) {
            throw new InvalidArgumentException('DASH requires a manifest destination.');
        }

        $this->outputs([], $path);
        $this->export->inspect();
        foreach ($this->representations as $representation) {
            $representation->freeze();
        }

        $this->representationFiles = [];
        $result = $this->export->save($path);

        return new DashExportResult(
            $result->files()->all(),
            $result->processes()->all(),
            $this->representationFiles,
            $this->sets,
        );
    }

    /**
     * Combine the representation manifests into one and get every artifact to publish.
     *
     * @internal
     */
    public function artifacts(array $default, array $outputs, array $paths): array
    {
        [$document, $root, $sets] = $this->createManifest();
        $segments = [];
        $playlists = [];
        $info = [];
        $clocks = [];
        $total = 0;
        $longest = 0;
        foreach (array_keys($this->representations) as $index => $name) {
            $type = $this->type($name);
            $parsed = DashManifest::read($paths[$index], $type);
            $total += count($parsed['durations']);
            if ($total > DashManifest::LIMIT) {
                DashManifest::fail();
            }

            // Each adaptation set declares segment alignment, so all of its representations
            // must share one segment clock or players could not switch between them.
            $setName = array_find_key(
                $this->sets,
                fn ($set): bool => in_array($name, $set['representations'], true),
            );
            $this->ensureClocksAlign($clocks[$setName] ?? null, $parsed['durations']);
            $clocks[$setName] = $parsed['durations'];
            $longest = max($longest, array_sum($parsed['durations']));
            $representation = $this->importRepresentation($document, $parsed['representation'], $name);
            $sets[$setName]->appendChild($representation);

            [$files, $peak, $bytes] = $this->representationArtifacts(
                $index,
                $name,
                $outputs[$index],
                $paths[$index],
                $parsed,
            );
            array_push($segments, ...$files);
            $info[$name] = [
                'type' => $type,
                'bandwidth' => $peak,
                'average' => (int) ceil(8 * $bytes / array_sum($parsed['durations'])),
                'codecs' => $representation->getAttribute('codecs'),
                'width' => $representation->getAttribute('width'),
                'height' => $representation->getAttribute('height'),
            ];
            if ($this->sharedHls) {
                $playlists[] = $this->writeRepresentationPlaylist(
                    $index,
                    $name,
                    $outputs[$index],
                    $paths[$index],
                    $parsed['durations'],
                );
            }
        }

        $root->setAttribute('mediaPresentationDuration', 'PT'.CommandCompiler::timestamp($longest).'S');
        $root->setAttribute(
            'maxSegmentDuration',
            'PT'.CommandCompiler::timestamp(max(array_map(max(...), $clocks))).'S',
        );

        $master = array_key_last($outputs);
        if ($this->sharedHls) {
            $playlists[] = $this->writeSharedHlsMaster($info, $master, $outputs[$master], $paths[$master]);
        }

        $this->write($paths[$master], $document->saveXML());
        $artifacts = [...$segments, ...$playlists, ['path' => $paths[$master], 'output' => $master,
            'role' => 'dash-manifest', 'destination' => $outputs[$master]->destination()]];
        $this->export->validateDestinations(array_map(
            fn (array $artifact): ExportOutput => new ExportOutput(
                $artifact['destination']['path'],
                $this->export->filesystem(),
            ),
            $artifacts,
        ));

        return $artifacts;
    }

    /**
     * Create the static manifest document with an empty node for each adaptation set.
     *
     * @return array{0: DOMDocument, 1: DOMElement, 2: array<int|string, DOMElement>}
     */
    protected function createManifest(): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(DashManifest::NS, 'MPD');
        $document->appendChild($root);
        $root->setAttribute('type', 'static');
        $root->setAttribute('profiles', 'urn:mpeg:dash:profile:isoff-live:2011');
        $root->setAttribute('minBufferTime', 'PT'.CommandCompiler::timestamp($this->segmentDuration * 2).'S');

        $period = $root->appendChild($document->createElementNS(DashManifest::NS, 'Period'));
        $period->setAttribute('start', 'PT0S');

        $sets = [];
        foreach ($this->sets as $name => $set) {
            $node = $period->appendChild($document->createElementNS(DashManifest::NS, 'AdaptationSet'));
            $node->setAttribute('id', (string) count($sets));
            $node->setAttribute('contentType', $set['type']);
            $node->setAttribute('segmentAlignment', 'true');
            $node->setAttribute('startWithSAP', '1');
            $sets[$name] = $node;
        }

        return [$document, $root, $sets];
    }

    /**
     * Ensure the segment durations match those of the adaptation set's earlier representations.
     *
     * @param  list<float>|null  $clock
     * @param  list<float>  $durations
     */
    protected function ensureClocksAlign(?array $clock, array $durations): void
    {
        if ($clock === null) {
            return;
        }

        if (count($clock) !== count($durations)) {
            DashManifest::fail();
        }

        foreach ($durations as $i => $duration) {
            if (abs($duration - $clock[$i]) > 0.000001) {
                DashManifest::fail();
            }
        }
    }

    /**
     * Import the parsed representation and point its segment template at the representation's files.
     */
    protected function importRepresentation(DOMDocument $document, DOMElement $parsed, int|string $name): DOMElement
    {
        $representation = $document->importNode($parsed, true);
        if (! $representation instanceof DOMElement) {
            DashManifest::fail();
        }

        $representation->setAttribute('id', $name);
        $template = $representation->getElementsByTagNameNS(DashManifest::NS, 'SegmentTemplate')->item(0);
        if (! $template instanceof DOMElement) {
            DashManifest::fail();
        }

        $template->setAttribute('initialization', "{$name}/init.mp4");
        $template->setAttribute('media', $name.'/segment-$Number%08d$.m4s');

        return $representation;
    }

    /**
     * Validate the representation's files and return their artifacts, peak bitrate and media bytes.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    protected function representationArtifacts(
        int $index,
        int|string $name,
        ExportOutput $output,
        string $path,
        array $parsed,
    ): array {
        $destination = $output->destination();
        $prefix = dirname($destination['path']).'/';
        $artifacts = [];
        $peak = 0;
        $bytes = 0;
        foreach ($parsed['files'] as $fileIndex => $filename) {
            $local = dirname($path)."/{$filename}";
            if ($fileIndex > 0) {
                $size = $this->export->validateArtifact($local, 'dash-segment');
                $bytes += $size;
                $peak = max($peak, (int) ceil(8 * $size / $parsed['durations'][$fileIndex - 1]));
            }

            $target = $destination;
            $target['path'] = "{$prefix}{$filename}";
            $this->representationFiles[$name][$target['path']] = true;
            $artifact = [
                'path' => $local,
                'output' => $index,
                'role' => $fileIndex === 0 ? 'dash-init' : 'dash-segment',
                'destination' => $target,
            ];
            if ($fileIndex === 1) {
                $directory = dirname($path);
                $artifact += [
                    'probe' => true,
                    'probePath' => PendingExport::fragmentProbe(
                        "{$directory}/init.mp4",
                        $local,
                        "{$directory}/probe.mp4",
                    ),
                ];
            }

            $artifacts[] = $artifact;
        }

        return [$artifacts, $peak, $bytes];
    }

    /**
     * Write the clear HLS playlist that references the representation's DASH files.
     *
     * @param  list<float>  $durations
     */
    protected function writeRepresentationPlaylist(
        int $index,
        int|string $name,
        ExportOutput $output,
        string $path,
        array $durations,
    ): array {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:7', '#EXT-X-TARGETDURATION:'.max(1, (int) ceil(max($durations))),
            '#EXT-X-MEDIA-SEQUENCE:1', '#EXT-X-PLAYLIST-TYPE:VOD', '#EXT-X-MAP:URI="init.mp4"'];
        foreach ($durations as $i => $duration) {
            $lines[] = '#EXTINF:'.sprintf('%.9f', $duration).',';
            $lines[] = sprintf('segment-%08d.m4s', $i + 1);
        }

        $lines[] = '#EXT-X-ENDLIST';
        $local = dirname($path).'/index.m3u8';
        $this->write($local, implode("\n", $lines)."\n");
        $destination = $output->destination();
        $destination['path'] = dirname($destination['path']).'/index.m3u8';
        $this->representationFiles[$name][$destination['path']] = true;

        return ['path' => $local, 'output' => $index, 'role' => 'hls-playlist', 'destination' => $destination];
    }

    /**
     * Write the clear HLS master playlist next to the DASH manifest.
     *
     * @param  array<int|string, array<string, mixed>>  $info
     */
    protected function writeSharedHlsMaster(array $info, int $master, ExportOutput $output, string $path): array
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:7'];
        $audio = array_filter($info, fn (array $item): bool => $item['type'] === 'audio');
        foreach ($audio as $name => $item) {
            $lines[] = "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"audio\",NAME=\"{$name}\""
                .',DEFAULT='.(array_key_first($audio) === $name ? 'YES' : 'NO')
                .",AUTOSELECT=YES,URI=\"{$name}/index.m3u8\"";
        }

        foreach ($info as $name => $item) {
            if ($item['type'] === 'audio' && count($info) !== count($audio)) {
                continue;
            }

            $bandwidth = (int) $item['bandwidth']
                + ($item['type'] === 'video' && $audio !== [] ? max(array_column($audio, 'bandwidth')) : 0);
            $average = $item['average']
                + ($item['type'] === 'video' && $audio !== [] ? max(array_column($audio, 'average')) : 0);
            $codecs = array_unique([
                $item['codecs'],
                ...($item['type'] === 'video' ? array_column($audio, 'codecs') : []),
            ]);
            $lines[] = "#EXT-X-STREAM-INF:BANDWIDTH={$bandwidth},AVERAGE-BANDWIDTH={$average}"
                .',CODECS="'.implode(',', $codecs).'"'
                .($item['type'] === 'video'
                    ? ",RESOLUTION={$item['width']}x{$item['height']}".($audio !== [] ? ',AUDIO="audio"' : '')
                    : '');
            $lines[] = "{$name}/index.m3u8";
        }

        $local = dirname($path).'/master.m3u8';
        $this->write($local, implode("\n", $lines)."\n");
        $destination = $output->destination();
        $destination['path'] = (dirname($destination['path']) === '.' ? '' : dirname($destination['path']).'/')
            .'master.m3u8';

        return ['path' => $local, 'output' => $master, 'role' => 'hls-master', 'destination' => $destination];
    }

    /**
     * Write a private manifest or playlist file within the 16 MiB limit.
     */
    protected function write(string $path, string $bytes): void
    {
        if (strlen($bytes) > Media::MAX_MANIFEST_BYTES
            || @file_put_contents($path, $bytes) !== strlen($bytes)
            || ! chmod($path, 0600)) {
            throw MediaException::validation(
                'Cannot finalize a DASH presentation within the 16 MiB manifest limit.',
                'artifact',
            );
        }
    }
}

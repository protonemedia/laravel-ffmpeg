<?php

namespace ProtoneMedia\LaravelFFMpeg;

use DOMDocument;
use DOMElement;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
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

/** Finite, clear H.264/AAC fragmented-MP4 packaging; no live or DRM claims. */
final class PendingDashExport implements Exportable, MeasuresDuration, PackagesArtifacts, ShapesCommand, ShapesOutputs, ValidatesSelections, WritesManifest
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
    use Conditionable;

    private readonly PendingExport $export;

    private array $sets = [];

    private array $representations = [];

    private array $representationFiles = [];

    private bool $sharedHls = false;

    public function __construct(Media $media, string|Filesystem $disk, private readonly float $segmentDuration)
    {
        if (! is_finite($segmentDuration) || $segmentDuration < 0.001 || $segmentDuration > 86400) {
            throw new InvalidArgumentException('DASH segment duration must be between 0.001 and 86400 seconds.');
        }

        $this->export = new PendingExport($media, $disk, $this, 'dash-manifest');
        $this->export->video('libx264')->audio('aac');
    }

    /** Returns the stable FFmpeg input index for representation maps. */
    public function addInput(Media $media): int
    {
        return $this->export->addInput($media);
    }

    public function video(?string $codec = null, mixed $bitrate = null, ?float $crf = null, ?string $preset = null): self
    {
        $this->export->video($codec, $bitrate, $crf, $preset);

        return $this;
    }

    public function videoOptions(array $tokens): self
    {
        $this->export->videoOptions($tokens);

        return $this;
    }

    /** Signal color characteristics; this does not transform pixel values. */
    public function color(string $primaries, string $transfer, string $space, string $range = 'tv'): self
    {
        $this->export->color($primaries, $transfer, $space, $range);

        return $this;
    }

    public function audio(?string $codec = null, mixed $bitrate = null): self
    {
        $this->export->audio($codec, $bitrate);

        return $this;
    }

    public function audioOptions(array $tokens): self
    {
        $this->export->audioOptions($tokens);

        return $this;
    }

    public function audioFilter(string $expression): self
    {
        $this->export->audioFilter($expression);

        return $this;
    }

    /** Linear amplitude multiplier; zero mutes the audio. */
    public function volume(float $multiplier): self
    {
        $this->export->volume($multiplier);

        return $this;
    }

    public function map(string $specifier): self
    {
        $this->export->map($specifier);

        return $this;
    }

    public function mapVideo(Stream|int|null $stream = null, ?int $input = null, ?int $track = null, bool $optional = false): self
    {
        $this->export->mapVideo($stream, $input, $track, $optional);

        return $this;
    }

    public function mapAudio(Stream|int|null $stream = null, ?int $input = null, ?int $track = null, ?string $language = null, bool $optional = false): self
    {
        $this->export->mapAudio($stream, $input, $track, $language, $optional);

        return $this;
    }

    public function adaptationSet(string $name, string $type): self
    {
        $this->export->assertOutputMutable();
        $this->identifier($name);
        if (isset($this->sets[$name]) || ! in_array($type, ['video', 'audio'], true)) {
            throw new InvalidArgumentException('DASH adaptation sets require unique names and video or audio type.');
        }

        $this->sets[$name] = ['type' => $type, 'representations' => []];

        return $this;
    }

    public function representation(string $name, string $adaptationSet, callable $configure): self
    {
        $this->export->assertOutputMutable();
        $this->identifier($name);
        if (isset($this->representations[$name]) || ! isset($this->sets[$adaptationSet]) || count($this->representations) >= 64) {
            throw new InvalidArgumentException('DASH requires unique representation names, a declared adaptation set, and at most 64 representations.');
        }

        $representation = new DashRepresentation;
        $configure($representation);
        $this->representations[$name] = $representation;
        $this->sets[$adaptationSet]['representations'][] = $name;

        return $this;
    }

    /** Opt in to clear HLS VOD playlists referencing the very same init/media files. */
    public function sharedHls(bool $enabled = true): self
    {
        $this->export->assertOutputMutable();
        $this->sharedHls = $enabled;

        return $this;
    }

    private function identifier(string $name): void
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/', $name)) {
            throw new InvalidArgumentException('DASH names must be simple path components of at most 64 characters.');
        }
    }

    private function type(string $name): string
    {
        foreach ($this->sets as $set) {
            if (in_array($name, $set['representations'], true)) {
                return $set['type'];
            }
        }

        throw new InvalidArgumentException('Undefined DASH representation.');
    }

    /** @internal */
    public function outputs(array $default, ?string $path): array
    {
        if ($this->representations === [] || array_any($this->sets, fn ($set): bool => $set['representations'] === [])) {
            throw new InvalidArgumentException('DASH requires populated adaptation sets.');
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
            if ($settings['maps'] === [] || $settings['omitAudio'] || $settings['omitVideo'] || $settings['videoStreams'] !== []
                || ($settings['audioStreams'] ?? []) !== []
                || ($settings[$type]['codec'] ?? null) !== ($type === 'video' ? 'libx264' : 'aac')
                || ($settings['clip']['seek'] ?? 'accurate') !== 'accurate'
                || ($outputs !== [] && $settings['clip'] !== $clip)) {
                throw new InvalidArgumentException('DASH representations require one explicit map, H.264/AAC encoding, identical accurate clips, and no stream omission or per-track overrides.');
            }

            $clip = $settings['clip'];
            if ($type === 'video' && array_intersect(['g', 'keyint_min', 'sc_threshold', 'x264-params', 'x265-params'], array_keys($settings['video'])) !== []) {
                throw new InvalidArgumentException('DASH owns video encoder GOP and segment alignment settings.');
            }

            foreach ($settings['outputOptions'] as $token) {
                if ($token === '-f' || preg_match('/\A-(?:c|r|g|keyint_min|sc_threshold|x264-params|x265-params)(?::|\z)/', $token)) {
                    throw new InvalidArgumentException('DASH owns its muxer, stream codecs, frame rate, and segment alignment.');
                }
            }

            $settings[$type === 'video' ? 'audio' : 'video'] = [];
            $settings[$type === 'video' ? 'audioStreams' : 'videoStreams'] = [];
            $settings[$type === 'video' ? 'audioFilters' : 'videoFilters'] = [];
            $outputs[] = new ExportOutput($prefix.$name.'/index.mpd', $this->export->filesystem())->configure($settings);
        }

        $outputs[] = $manifest->configure($this->export->outputConfiguration());

        return $outputs;
    }

    /** @internal */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        $result = [];
        $targets = $outputPaths ?? array_map(fn (int|string $i): string => '[output:'.$i.']', array_keys($outputs));
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

    /** @internal */
    public function selections(array $expected): void
    {
        foreach (array_keys($this->representations) as $index => $name) {
            if ($expected[$index] !== [$this->type($name)]) {
                throw new MediaException('A DASH representation must map exactly one stream of its declared type.', Progress::PROBING, 'mapping');
            }
        }
    }

    /** @internal */
    public function duration(array $metadata, ?float $default): float
    {
        if ($default === null || $default <= 0 || ceil($default / $this->segmentDuration) * count($this->representations) > DashManifest::LIMIT) {
            throw new InvalidArgumentException('DASH requires a known finite duration within its 100000 segment inventory limit.');
        }

        return $default;
    }

    /** @internal */
    public function outputPaths(array $default, array $outputs, Workspace $workspace): array
    {
        $paths = [];
        foreach (array_keys($this->representations) as $name) {
            $directory = $workspace->path.'/dash-'.$name;
            if (! mkdir($directory, 0700)) {
                throw new MediaException('Cannot create the private DASH workspace.', Progress::ENCODING, 'filesystem');
            }

            $paths[] = $directory.'/index.mpd';
        }

        $paths[] = $workspace->path.'/manifest.mpd';

        return $paths;
    }

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

        return new DashExportResult($result->files()->all(), $result->processes()->all(), $this->representationFiles, $this->sets);
    }

    /** @internal */
    public function artifacts(array $default, array $outputs, array $paths): array
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

            $setName = array_find_key($this->sets, fn ($set): bool => in_array($name, $set['representations'], true));
            if (isset($clocks[$setName])) {
                if (count($clocks[$setName]) !== count($parsed['durations'])) {
                    DashManifest::fail();
                }

                foreach ($parsed['durations'] as $i => $duration) {
                    if (abs($duration - $clocks[$setName][$i]) > 0.000001) {
                        DashManifest::fail();
                    }
                }
            }

            $clocks[$setName] = $parsed['durations'];
            $longest = max($longest, array_sum($parsed['durations']));
            $representation = $document->importNode($parsed['representation'], true);
            if (! $representation instanceof DOMElement) {
                DashManifest::fail();
            }

            $representation->setAttribute('id', $name);
            $template = $representation->getElementsByTagNameNS(DashManifest::NS, 'SegmentTemplate')->item(0);
            if (! $template instanceof DOMElement) {
                DashManifest::fail();
            }

            $template->setAttribute('initialization', $name.'/init.mp4');
            $template->setAttribute('media', $name.'/segment-$Number%08d$.m4s');
            $sets[$setName]->appendChild($representation);
            $destination = $outputs[$index]->destination();
            $prefix = dirname($destination['path']).'/';
            $peak = 0;
            $bytes = 0;
            foreach ($parsed['files'] as $fileIndex => $filename) {
                $local = dirname($paths[$index]).'/'.$filename;
                if ($fileIndex > 0) {
                    $size = $this->export->validateArtifact($local, 'dash-segment');
                    $bytes += $size;
                    $peak = max($peak, (int) ceil(8 * $size / $parsed['durations'][$fileIndex - 1]));
                }

                $target = $destination;
                $target['path'] = $prefix.$filename;
                $this->representationFiles[$name][$target['path']] = true;
                $artifact = ['path' => $local, 'output' => $index, 'role' => $fileIndex === 0 ? 'dash-init' : 'dash-segment', 'destination' => $target];
                if ($fileIndex === 1) {
                    $directory = dirname($paths[$index]);
                    $artifact += ['probe' => true, 'probePath' => PendingExport::fragmentProbe($directory.'/init.mp4', $local, $directory.'/probe.mp4')];
                }

                $segments[] = $artifact;
            }

            $info[$name] = ['type' => $type, 'bandwidth' => $peak, 'average' => (int) ceil(8 * $bytes / array_sum($parsed['durations'])),
                'codecs' => $representation->getAttribute('codecs'), 'width' => $representation->getAttribute('width'), 'height' => $representation->getAttribute('height')];
            if ($this->sharedHls) {
                $lines = ['#EXTM3U', '#EXT-X-VERSION:7', '#EXT-X-TARGETDURATION:'.max(1, (int) ceil(max($parsed['durations']))),
                    '#EXT-X-MEDIA-SEQUENCE:1', '#EXT-X-PLAYLIST-TYPE:VOD', '#EXT-X-MAP:URI="init.mp4"'];
                foreach ($parsed['durations'] as $i => $duration) {
                    $lines[] = '#EXTINF:'.sprintf('%.9f', $duration).',';
                    $lines[] = sprintf('segment-%08d.m4s', $i + 1);
                }

                $lines[] = '#EXT-X-ENDLIST';
                $local = dirname($paths[$index]).'/index.m3u8';
                $this->write($local, implode("\n", $lines)."\n");
                $destination['path'] = $prefix.'index.m3u8';
                $this->representationFiles[$name][$destination['path']] = true;
                $playlists[] = ['path' => $local, 'output' => $index, 'role' => 'hls-playlist', 'destination' => $destination];
            }
        }

        $root->setAttribute('mediaPresentationDuration', 'PT'.CommandCompiler::timestamp($longest).'S');
        $root->setAttribute('maxSegmentDuration', 'PT'.CommandCompiler::timestamp(max(array_map(max(...), $clocks))).'S');

        $master = array_key_last($outputs);
        if ($this->sharedHls) {
            $lines = ['#EXTM3U', '#EXT-X-VERSION:7'];
            $audio = array_filter($info, fn (array $item): bool => $item['type'] === 'audio');
            foreach ($audio as $name => $item) {
                $lines[] = '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="'.$name.'",DEFAULT='.(array_key_first($audio) === $name ? 'YES' : 'NO').',AUTOSELECT=YES,URI="'.$name.'/index.m3u8"';
            }

            foreach ($info as $name => $item) {
                if ($item['type'] === 'audio' && count($info) !== count($audio)) {
                    continue;
                }

                $bandwidth = (int) $item['bandwidth'] + ($item['type'] === 'video' && $audio !== [] ? max(array_column($audio, 'bandwidth')) : 0);
                $average = $item['average'] + ($item['type'] === 'video' && $audio !== [] ? max(array_column($audio, 'average')) : 0);
                $codecs = array_unique([$item['codecs'], ...($item['type'] === 'video' ? array_column($audio, 'codecs') : [])]);
                $lines[] = '#EXT-X-STREAM-INF:BANDWIDTH='.$bandwidth.',AVERAGE-BANDWIDTH='.$average.',CODECS="'.implode(',', $codecs).'"'
                    .($item['type'] === 'video' ? ',RESOLUTION='.$item['width'].'x'.$item['height'].($audio !== [] ? ',AUDIO="audio"' : '') : '');
                $lines[] = $name.'/index.m3u8';
            }

            $local = dirname($paths[$master]).'/master.m3u8';
            $this->write($local, implode("\n", $lines)."\n");
            $destination = $outputs[$master]->destination();
            $destination['path'] = (dirname($destination['path']) === '.' ? '' : dirname($destination['path']).'/').'master.m3u8';
            $playlists[] = ['path' => $local, 'output' => $master, 'role' => 'hls-master', 'destination' => $destination];
        }

        $this->write($paths[$master], $document->saveXML());
        $artifacts = [...$segments, ...$playlists, ['path' => $paths[$master], 'output' => $master,
            'role' => 'dash-manifest', 'destination' => $outputs[$master]->destination()]];
        $this->export->validateDestinations(array_map(fn (array $artifact): \ProtoneMedia\LaravelFFMpeg\ExportOutput => new ExportOutput($artifact['destination']['path'], $this->export->filesystem()), $artifacts));

        return $artifacts;
    }

    private function write(string $path, string $bytes): void
    {
        if (strlen($bytes) > 16777216 || @file_put_contents($path, $bytes) !== strlen($bytes) || ! chmod($path, 0600)) {
            throw new MediaException('Cannot finalize a DASH presentation within the 16 MiB manifest limit.', Progress::VALIDATION, 'artifact');
        }
    }
}

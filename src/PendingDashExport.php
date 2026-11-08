<?php

namespace ProtoneMedia\LaravelFFMpeg;

use DOMDocument;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/** Finite, clear H.264/AAC fragmented-MP4 packaging; no live or DRM claims. */
class PendingDashExport extends PendingExport
{
    protected string $outputRole = 'dash-manifest';

    private array $sets = [];

    private array $representations = [];

    private array $representationFiles = [];

    private bool $sharedHls = false;

    public function __construct(Media $media, string|Filesystem $disk, private readonly float $segmentDuration)
    {
        parent::__construct($media, $disk);
        if (! is_finite($segmentDuration) || $segmentDuration < 0.001 || $segmentDuration > 86400) {
            throw new InvalidArgumentException('DASH segment duration must be between 0.001 and 86400 seconds.');
        }
        $this->video('libx264')->audio('aac');
    }

    public function adaptationSet(string $name, string $type): self
    {
        $this->assertOutputMutable();
        $this->identifier($name);
        if (isset($this->sets[$name]) || ! in_array($type, ['video', 'audio'], true)) {
            throw new InvalidArgumentException('DASH adaptation sets require unique names and video or audio type.');
        }
        $this->sets[$name] = ['type' => $type, 'representations' => []];

        return $this;
    }

    public function representation(string $name, string $adaptationSet, callable $configure): self
    {
        $this->assertOutputMutable();
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
        $this->assertOutputMutable();
        $this->sharedHls = $enabled;

        return $this;
    }

    public function output(string $path, callable $configure): self
    {
        throw new InvalidArgumentException('Configure DASH outputs with adaptationSet() and representation().');
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

    protected function configuredOutputs(?string $path = null): array
    {
        if ($this->representations === [] || array_any($this->sets, fn ($set) => $set['representations'] === [])) {
            throw new InvalidArgumentException('DASH requires populated adaptation sets.');
        }
        if (! class_exists(DOMDocument::class)) {
            throw new InvalidArgumentException('DASH requires the PHP DOM XML extension.');
        }
        $manifest = new ExportOutput($path ?? 'manifest.mpd', $this->filesystem());
        $destination = $manifest->destination()['path'];
        if (strtolower(pathinfo($destination, PATHINFO_EXTENSION)) !== 'mpd') {
            throw new InvalidArgumentException('A DASH manifest destination must have an mpd extension.');
        }
        $prefix = dirname($destination) === '.' ? '' : dirname($destination).'/';
        $outputs = [];
        $clip = null;
        foreach ($this->representations as $name => $representation) {
            $settings = $representation->inherit($this->outputConfiguration());
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
            $outputs[] = (new ExportOutput($prefix.$name.'/index.mpd', $this->filesystem()))->configure($settings);
        }
        $outputs[] = $manifest->configure($this->outputConfiguration());

        return $outputs;
    }

    protected function command(array $outputs, array $paths, ?array $outputPaths = null): array
    {
        $encoding = array_slice($outputs, 0, -1);
        $command = parent::command($encoding, $paths, $outputPaths);
        $result = [];
        $targets = $outputPaths ?? array_map(fn ($i) => '[output:'.$i.']', array_keys($encoding));
        foreach ($command as $token) {
            $index = array_search($token, $targets, true);
            if ($index !== false) {
                if ($this->type(array_keys($this->representations)[$index]) === 'video') {
                    array_push($result, '-force_key_frames:v', 'expr:gte(t,n_forced*'.$this->clipTimestamp($this->segmentDuration).')',
                        '-flags:v', '+cgop', '-sc_threshold:v', '0');
                }
                array_push($result, '-f', 'dash', '-seg_duration', $this->clipTimestamp($this->segmentDuration),
                    '-use_template', '1', '-use_timeline', '1', '-window_size', '0', '-extra_window_size', '0',
                    '-init_seg_name', 'init.mp4', '-media_seg_name', 'segment-$Number%08d$.m4s');
            }
            $result[] = $token;
        }

        return $result;
    }

    protected function validateStreamSelections(array $expected): void
    {
        foreach (array_keys($this->representations) as $index => $name) {
            if ($expected[$index] !== [$this->type($name)]) {
                throw new MediaException('A DASH representation must map exactly one stream of its declared type.', Progress::PROBING, 'mapping');
            }
        }
    }

    protected function prepareEncoding(array $metadata): ?float
    {
        $duration = parent::prepareEncoding($metadata);
        if ($duration === null || $duration <= 0 || ceil($duration / $this->segmentDuration) * count($this->representations) > DashManifest::LIMIT) {
            throw new InvalidArgumentException('DASH requires a known finite duration within its 100000 segment inventory limit.');
        }

        return $duration;
    }

    protected function outputPaths(array $outputs, Workspace $workspace): array
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
        $this->assertOutputMutable();
        if ($path === null) {
            throw new InvalidArgumentException('DASH requires a manifest destination.');
        }
        $this->configuredOutputs($path);
        $this->inspect();
        foreach ($this->representations as $representation) {
            $representation->freeze();
        }
        $this->representationFiles = [];
        $result = parent::save($path);

        return new DashExportResult($result->files()->all(), $result->processes()->all(), $this->representationFiles, $this->sets);
    }

    protected function artifacts(array $outputs, array $paths): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(DashManifest::NS, 'MPD');
        $document->appendChild($root);
        $root->setAttribute('type', 'static');
        $root->setAttribute('profiles', 'urn:mpeg:dash:profile:isoff-live:2011');
        $root->setAttribute('minBufferTime', 'PT'.$this->clipTimestamp($this->segmentDuration * 2).'S');
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
        $segments = $playlists = $info = $clocks = [];
        $total = 0;
        $longest = 0;
        foreach (array_keys($this->representations) as $index => $name) {
            $type = $this->type($name);
            $parsed = DashManifest::read($paths[$index], $type);
            $total += count($parsed['durations']);
            if ($total > DashManifest::LIMIT) {
                DashManifest::fail();
            }
            $setName = array_find_key($this->sets, fn ($set) => in_array($name, $set['representations'], true));
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
            $representation->setAttribute('id', $name);
            $template = $representation->getElementsByTagNameNS(DashManifest::NS, 'SegmentTemplate')->item(0);
            $template->setAttribute('initialization', $name.'/init.mp4');
            $template->setAttribute('media', $name.'/segment-$Number%08d$.m4s');
            $sets[$setName]->appendChild($representation);
            $destination = $outputs[$index]->destination();
            $prefix = dirname($destination['path']).'/';
            $peak = $bytes = 0;
            foreach ($parsed['files'] as $fileIndex => $filename) {
                $local = dirname($paths[$index]).'/'.$filename;
                if ($fileIndex > 0) {
                    $size = $this->validateArtifact($local, 'dash-segment');
                    $bytes += $size;
                    $peak = max($peak, (int) ceil(8 * $size / $parsed['durations'][$fileIndex - 1]));
                }
                $target = $destination;
                $target['path'] = $prefix.$filename;
                $this->representationFiles[$name][$target['path']] = true;
                $artifact = ['path' => $local, 'output' => $index, 'role' => $fileIndex === 0 ? 'dash-init' : 'dash-segment', 'destination' => $target];
                if ($fileIndex === 1) {
                    $probe = dirname($paths[$index]).'/probe.mp4';
                    $stream = @fopen($probe, 'wb');
                    if ($stream === false) {
                        throw new MediaException('Cannot prepare DASH fragment validation.', Progress::VALIDATION, 'filesystem');
                    }
                    try {
                        foreach (['init.mp4', $filename] as $part) {
                            $input = @fopen(dirname($paths[$index]).'/'.$part, 'rb');
                            if ($input === false) {
                                throw new MediaException('Cannot read a DASH fragment.', Progress::VALIDATION, 'filesystem');
                            }
                            try {
                                if (stream_copy_to_stream($input, $stream) !== filesize(dirname($paths[$index]).'/'.$part)) {
                                    DashManifest::fail();
                                }
                            } finally {
                                fclose($input);
                            }
                        }
                    } finally {
                        fclose($stream);
                    }
                    if (! chmod($probe, 0600)) {
                        throw new MediaException('Cannot secure DASH fragment validation.', Progress::VALIDATION, 'filesystem');
                    }
                    $artifact += ['probe' => true, 'probePath' => $probe];
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
        $root->setAttribute('mediaPresentationDuration', 'PT'.$this->clipTimestamp($longest).'S');
        $root->setAttribute('maxSegmentDuration', 'PT'.$this->clipTimestamp(max(array_map('max', $clocks))).'S');
        $master = array_key_last($outputs);
        if ($this->sharedHls) {
            $lines = ['#EXTM3U', '#EXT-X-VERSION:7'];
            $audio = array_filter($info, fn ($item) => $item['type'] === 'audio');
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
        $this->validateDestinations(array_map(fn ($artifact) => new ExportOutput($artifact['destination']['path'], $this->filesystem()), $artifacts));

        return $artifacts;
    }

    private function write(string $path, string $bytes): void
    {
        if (strlen($bytes) > 16777216 || @file_put_contents($path, $bytes) !== strlen($bytes) || ! chmod($path, 0600)) {
            throw new MediaException('Cannot finalize a DASH presentation within the 16 MiB manifest limit.', Progress::VALIDATION, 'artifact');
        }
    }
}

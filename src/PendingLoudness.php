<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\ProcessRunner;
use ProtoneMedia\LaravelFFMpeg\Execution\ProgressParser;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;

/** Audio-only normalization. The normal export owns staging, events, encoding and publication. */
class PendingLoudness extends PendingExport
{
    private ?LoudnessStats $measurement = null;

    private ?LoudnessStats $normalization = null;

    private ?string $statisticsPath = null;

    private array $analysisProcesses = [];

    public function __construct(
        Media $media,
        string|Filesystem $disk,
        private readonly float $integrated = -23,
        private readonly float $truePeak = -2,
        private readonly float $range = 7,
        private readonly bool $linear = true,
        private readonly int $sampleRate = 48000,
        int $audio = 0,
    ) {
        foreach ([[$integrated, -70, -5], [$truePeak, -9, 0], [$range, 1, 50]] as [$value, $min, $max]) {
            if (! is_finite($value) || $value < $min || $value > $max) {
                throw new InvalidArgumentException('Loudness targets are outside FFmpeg loudnorm ranges.');
            }
        }

        if ($audio < 0 || $sampleRate < 8000 || $sampleRate > 192000) {
            throw new InvalidArgumentException('Choose a nonnegative audio track and a sample rate from 8000 to 192000.');
        }

        parent::__construct($media, $disk);
        $this->map('0:a:'.$audio)->audio('aac');
    }

    protected function command(array $outputs, array $paths, ?array $outputPaths = null): array
    {
        if (count($outputs) !== 1 || count($paths) !== 1) {
            throw new InvalidArgumentException('Loudness normalization requires one input and one output.');
        }

        $settings = $outputs[0]->outputConfiguration();

        if (($settings['audio']['codec'] ?? null) === 'copy' || $settings['videoFilters'] !== []
            || $settings['videoStreams'] !== [] || $settings['audioStreams'] !== []
            || $settings['video'] !== [] || $outputs[0]->inputSeekOptions() !== []) {
            throw new InvalidArgumentException('Loudness normalization requires audio encoding without video or keyframe seeking.');
        }

        $command = parent::command($outputs, $paths, $outputPaths);
        $maps = [];
        $filter = '';
        $result = [];

        for ($i = 0; $i < count($command); $i++) {
            $token = $command[$i];

            if (in_array($token, ['-filter_complex', '-pass:v'], true)) {
                throw new InvalidArgumentException('Loudness normalization does not support graphs or video passes.');
            }

            if (preg_match('/^-(?:ar|ac)(?::|$)/', $token)) {
                throw new InvalidArgumentException('Use audioFilter() for channel preprocessing; sampleRate is owned by normalizeLoudness().');
            }

            if ($token === '-map') {
                $maps[] = $command[$i + 1];
            }

            if ($token === '-filter:a') {
                $filter = $command[++$i];
                continue;
            }

            if (in_array($token, ['-ss', '-t'], true)) {
                $i++;
                continue;
            }

            $result[] = $token;
        }

        if (count($maps) !== 1 || ! preg_match('/^0:a:\d+$/D', $maps[0])) {
            throw new InvalidArgumentException('Loudness normalization requires exactly one nonoptional audio track map.');
        }

        // Trim before preprocessing and measurement, not as an output seek after loudnorm.
        $clip = $outputs[0]->clipSettings();
        $filters = $clip === null ? [] : ['atrim=start='.$this->number($clip['start']).':duration='.$this->number($clip['duration']), 'asetpts=PTS-STARTPTS'];
        if ($filter !== '') {
            $filters[] = $filter;
        }
        $filters[] = 'aresample='.$this->sampleRate;
        $filters[] = $this->loudnorm('loudness-final.json');
        $destination = array_pop($result);

        return [...$result, '-vn', '-filter:a', implode(',', $filters), '-ar', (string) $this->sampleRate, $destination];
    }

    /** Called by PendingExport after staging/probing and before its final encoding loop. */
    protected function prepareCommand(Workspace $workspace, ProcessRunner $runner, array $command, array $outputPaths, array $redactions, ?callable $onProgress = null): array
    {
        $this->measurement = $this->normalization = null;
        $this->analysisProcesses = [];
        $this->statisticsPath = $workspace->path.'/loudness-final.json';
        $inputEnd = array_search('-i', $command, true) + 2;
        $prefix = array_slice($command, 0, $inputEnd);
        $mapIndex = array_search('-map', $command, true);
        $filterIndex = array_search('-filter:a', $command, true);
        $preprocessing = substr($command[$filterIndex + 1], 0, -strlen(','.$this->loudnorm('loudness-final.json')));
        $prepared = 'loudness-prepared.nut';

        // Decode selected, trimmed, channel-processed samples only once. Both passes
        // reopen this private lossless file, never a mutable remote or local source.
        $this->runAnalysis($runner, [...$prefix, '-map', $command[$mapIndex + 1], '-vn', '-af', $preprocessing,
            '-map_metadata', '0', '-c:a', 'pcm_f64le', '-f', 'nut', $prepared], $workspace, $redactions, $onProgress);
        $measure = [$command[0], '-hide_banner', '-nostdin', '-y', '-xerror', '-progress', 'pipe:1', '-i', $prepared, '-af',
            $this->loudnorm('loudness-measure.json'), '-f', 'null', '-'];
        $this->runAnalysis($runner, $measure, $workspace, $redactions, $onProgress);
        $this->measurement = $this->readStats($workspace->path.'/loudness-measure.json');
        $normalizer = $this->loudnorm('loudness-final.json');

        if ($this->measurement->usableMeasurements()) {
            $stats = $this->measurement;
            $normalizer .= ':measured_I='.$this->number($stats->inputIntegrated)
                .':measured_TP='.$this->number($stats->inputTruePeak)
                .':measured_LRA='.$this->number($stats->inputRange)
                .':measured_thresh='.$this->number($stats->inputThreshold)
                .':offset='.$this->number($stats->targetOffset);
        }

        // Keep encoder/container/metadata/publication settings from the ordinary pipeline.
        $final = [$command[0], '-hide_banner', '-nostdin', '-nostats', '-y', '-xerror', '-progress', 'pipe:1', '-i', $prepared];
        for ($i = $inputEnd; $i < count($command); $i++) {
            $token = $command[$i];
            if ($token === '-map') {
                array_push($final, '-map', '0:a:0');
                $i++;
            } elseif ($token === '-filter:a') {
                array_push($final, '-filter:a', $normalizer);
                $i++;
            } else {
                $final[] = $token;
            }
        }

        return $final;
    }

    private function loudnorm(string $statistics): string
    {
        return 'loudnorm=I='.$this->number($this->integrated).':TP='.$this->number($this->truePeak)
            .':LRA='.$this->number($this->range).':linear='.($this->linear ? 'true' : 'false')
            .':print_format=json:stats_file='.$statistics;
    }

    private function number(float $number): string
    {
        return json_encode($number, JSON_THROW_ON_ERROR);
    }

    private function runAnalysis(ProcessRunner $runner, array $command, Workspace $workspace, array $redactions, ?callable $onProgress): void
    {
        $notify = $onProgress === null ? null : static fn (Progress $progress) => $onProgress(new Progress(
            'analysis', elapsedSeconds: $progress->elapsedSeconds, speed: $progress->speed,
        ));
        $notify?->__invoke(new Progress('analysis'));
        $result = $runner->run($command, $workspace->path, $redactions, phase: 'analysis', progress: new ProgressParser(null), onProgress: $notify);
        if ($result->exitCode !== 0) {
            throw new MediaException('Loudness analysis failed.', 'analysis', 'exit', $result->diagnostics);
        }
        $this->analysisProcesses[] = $result->summary();
    }

    private function readStats(string $path): LoudnessStats
    {
        if (! is_file($path) || filesize($path) > 65536 || ($json = file_get_contents($path)) === false) {
            throw new MediaException('Loudness statistics are missing or oversized.', 'validation', 'analysis');
        }

        return LoudnessStats::fromJson($json);
    }

    protected function validateArtifact(string $path, string $role = 'media'): int
    {
        $size = parent::validateArtifact($path, $role);
        $this->normalization = $this->readStats($this->statisticsPath ?? '');
        $probe = $this->runner->run([
            config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'), '-v', 'error', '-of', 'json',
            '-show_entries', 'stream=codec_type,sample_rate', $path,
        ], dirname($path), [$path => '[output:0]'], phase: 'validation');
        $streams = json_decode($probe->output, true)['streams'] ?? [];
        if ($probe->exitCode !== 0 || count($streams) !== 1 || ($streams[0]['codec_type'] ?? null) !== 'audio'
            || (int) ($streams[0]['sample_rate'] ?? 0) !== $this->sampleRate) {
            throw new MediaException('Normalized output must contain one audio stream at the requested sample rate.', 'validation', 'artifact');
        }

        return $size;
    }

    public function save(?string $path = null): LoudnessResult
    {
        $export = parent::save($path);
        $export = new ExportResult($export->files()->all(), [...$this->analysisProcesses, ...$export->processes()->all()]);

        return new LoudnessResult($export, $this->measurement, $this->normalization, $this->linear ? 'linear' : 'dynamic', $this->sampleRate);
    }
}

<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\RunsAnalysis;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesCommand;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ValidatesArtifacts;

/** Audio-only normalization. The held export pipeline owns staging, events, encoding and publication. */
final class PendingLoudness implements Exportable, RunsAnalysis, ShapesCommand, ValidatesArtifacts
{
    use Concerns\AudioFilters;
    use Concerns\Clips;
    use Concerns\Fades;
    use Concerns\Metadata;
    use Concerns\OutputOptions;
    use Concerns\RunsExport;
    use Conditionable;

    private readonly PendingExport $export;

    private ?LoudnessStats $measurement = null;

    private ?LoudnessStats $normalization = null;

    private ?Workspace $workspace = null;

    public function __construct(
        private readonly Media $source,
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

        $this->export = new PendingExport($source, $disk, $this);
        $this->export->map('0:a:'.$audio)->audio('aac');
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

    public function save(?string $path = null): LoudnessResult
    {
        return new LoudnessResult($this->export->save($path), $this->measurement, $this->normalization, $this->linear ? 'linear' : 'dynamic', $this->sampleRate);
    }

    /** @internal */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        $settings = $outputs[0]->outputConfiguration();

        // Video fades are the only way to reach video settings on this builder.
        if (($settings['audio']['codec'] ?? null) === 'copy' || $settings['videoFilters'] !== [] || $outputs[0]->inputSeekOptions() !== []) {
            throw new InvalidArgumentException('Loudness normalization requires audio encoding without video or keyframe seeking.');
        }

        $command = $default;
        $filter = '';
        $result = [];

        for ($i = 0; $i < count($command); $i++) {
            $token = $command[$i];

            if (preg_match('/^-(?:ar|ac)(?::|$)/', $token)) {
                throw new InvalidArgumentException('Use audioFilter() for channel preprocessing; sampleRate is owned by normalizeLoudness().');
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

    /** @internal Called after staging and probing, before the final encoding pass. */
    public function analyze(Workspace $workspace, array $command, array $outputPaths, Closure $analyze): array
    {
        $this->measurement = null;
        $this->normalization = null;
        $this->workspace = $workspace;
        $inputEnd = array_search('-i', $command, true) + 2;
        $prefix = array_slice($command, 0, $inputEnd);
        $mapIndex = array_search('-map', $command, true);
        $filterIndex = array_search('-filter:a', $command, true);
        $preprocessing = substr($command[$filterIndex + 1], 0, -strlen(','.$this->loudnorm('loudness-final.json')));
        $prepared = 'loudness-prepared.nut';

        // Decode selected, trimmed, channel-processed samples only once. Both passes
        // reopen this private lossless file, never a mutable remote or local source.
        $analyze([...$prefix, '-map', $command[$mapIndex + 1], '-vn', '-af', $preprocessing,
            '-map_metadata', '0', '-c:a', 'pcm_f64le', '-f', 'nut', $prepared], 'Loudness analysis failed.');
        $measure = [$command[0], '-hide_banner', '-nostdin', '-y', '-xerror', '-progress', 'pipe:1', '-i', $prepared, '-af',
            $this->loudnorm('loudness-measure.json'), '-f', 'null', '-'];
        $analyze($measure, 'Loudness analysis failed.');
        $this->measurement = $this->readStats($workspace->path.'/loudness-measure.json', Progress::ANALYSIS);
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
                $final[] = '-map';
                $final[] = '0:a:0';
                $i++;
            } elseif ($token === '-filter:a') {
                $final[] = '-filter:a';
                $final[] = $normalizer;
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

    private function readStats(string $path, string $phase = 'validation'): LoudnessStats
    {
        if (! is_file($path) || filesize($path) > 65536 || ($json = file_get_contents($path)) === false) {
            throw new MediaException('Loudness statistics are missing or oversized.', $phase, 'analysis');
        }

        return LoudnessStats::fromJson($json, $phase);
    }

    /** @internal */
    public function artifact(string $path, string $role, int $size): void
    {
        $this->normalization = $this->readStats($this->workspace->path.'/loudness-final.json');
        try {
            $streams = $this->source->probeArtifact($path, $this->workspace)->streams();
        } catch (MediaException $mediaException) {
            if ($mediaException->category === 'timeout') {
                throw $mediaException;
            }

            $streams = collect();
        }

        if ($streams->count() !== 1 || $streams->first()->get('codec_type') !== 'audio' || $streams->first()->sampleRate() !== $this->sampleRate) {
            throw new MediaException('Normalized output must contain one audio stream at the requested sample rate.', 'validation', 'artifact');
        }
    }
}

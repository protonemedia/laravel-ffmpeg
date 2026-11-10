<?php

namespace ProtoneMedia\LaravelFFMpeg;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Exceptions\MediaException;
use ProtoneMedia\LaravelFFMpeg\Execution\Workspace;
use ProtoneMedia\LaravelFFMpeg\Pipeline\Exportable;
use ProtoneMedia\LaravelFFMpeg\Pipeline\RunsAnalysis;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ShapesCommand;
use ProtoneMedia\LaravelFFMpeg\Pipeline\ValidatesArtifacts;

/**
 * An audio-only loudness normalization.
 * The export pipeline it holds owns staging, events, encoding and publication.
 */
class PendingLoudness implements Exportable, RunsAnalysis, ShapesCommand, ValidatesArtifacts
{
    use Concerns\AudioFilters;
    use Concerns\Clips;
    use Concerns\Fades;
    use Concerns\Metadata;
    use Concerns\OutputOptions;
    use Concerns\RunsExport;
    use Conditionable, Macroable;

    /**
     * The export pipeline this builder configures.
     */
    protected readonly PendingExport $export;

    /**
     * The statistics of the measurement pass.
     */
    protected ?LoudnessStats $measurement = null;

    /**
     * The statistics of the final normalization pass.
     */
    protected ?LoudnessStats $normalization = null;

    /**
     * The workspace of the running export.
     */
    protected ?Workspace $workspace = null;

    /**
     * Create a new pending loudness instance.
     */
    public function __construct(
        protected readonly Media $source,
        string|Filesystem $disk,
        protected readonly float $integrated = -23,
        protected readonly float $truePeak = -2,
        protected readonly float $range = 7,
        protected readonly bool $linear = true,
        protected readonly int $sampleRate = 48000,
        int $audio = 0,
    ) {
        foreach ([
            'integrated loudness' => [$integrated, -70, -5],
            'true peak' => [$truePeak, -9, 0],
            'loudness range' => [$range, 1, 50],
        ] as $target => [$value, $min, $max]) {
            if (! is_finite($value) || $value < $min || $value > $max) {
                throw new InvalidArgumentException(
                    "The {$target} target must be between {$min} and {$max}."
                );
            }
        }

        if ($audio < 0) {
            throw new InvalidArgumentException("The audio track [{$audio}] must not be negative.");
        }

        if ($sampleRate < 8000 || $sampleRate > 192000) {
            throw new InvalidArgumentException("The sample rate [{$sampleRate}] must be between 8000 and 192000.");
        }

        $this->export = new PendingExport($source, $disk, $this);

        $this->export->map("0:a:{$audio}")->audio('aac');
    }

    /**
     * Set the audio encoder and bitrate.
     */
    public function audio(?string $codec = null, mixed $bitrate = null): static
    {
        $this->export->audio($codec, $bitrate);

        return $this;
    }

    /**
     * Set allowed encoder options for every output audio track.
     */
    public function audioOptions(array $tokens): static
    {
        $this->export->audioOptions($tokens);

        return $this;
    }

    /**
     * Save the normalized audio to the given path.
     */
    public function save(?string $path = null): LoudnessResult
    {
        return new LoudnessResult(
            $this->export->save($path),
            $this->measurement,
            $this->normalization,
            $this->linear ? 'linear' : 'dynamic',
            $this->sampleRate,
        );
    }

    /**
     * Move the clip and the caller's filters ahead of loudnorm in a single audio filter chain.
     *
     * @internal
     */
    public function command(array $default, array $outputs, array $paths, ?array $outputPaths): array
    {
        $this->ensureNormalizable($outputs[0]);

        [$result, $filter] = $this->extractAudioFilter($default);

        // We trim before preprocessing and measurement, instead of seeking the output after loudnorm.
        $clip = $outputs[0]->clipSettings();
        $filters = $clip === null ? [] : [
            "atrim=start={$this->number($clip['start'])}:duration={$this->number($clip['duration'])}",
            'asetpts=PTS-STARTPTS',
        ];

        if ($filter !== '') {
            $filters[] = $filter;
        }

        $filters[] = "aresample={$this->sampleRate}";
        $filters[] = $this->loudnorm('loudness-final.json');
        $destination = array_pop($result);

        return [
            ...$result, '-vn', '-filter:a', implode(',', $filters), '-ar', (string) $this->sampleRate, $destination,
        ];
    }

    /**
     * Ensure the given output encodes audio without video filters or keyframe seek.
     */
    protected function ensureNormalizable(ExportOutput $output): void
    {
        $settings = $output->outputConfiguration();

        if (($settings['audio']['codec'] ?? null) === 'copy') {
            throw new InvalidArgumentException('Loudness normalization must encode audio, not copy it.');
        }

        // Video fades are the only way to reach video settings on this builder.
        if ($settings['videoFilters'] !== []) {
            throw new InvalidArgumentException('Loudness normalization does not support video filters.');
        }

        if ($output->inputSeekOptions() !== []) {
            throw new InvalidArgumentException('Loudness normalization requires accurate clips, not keyframe seek.');
        }
    }

    /**
     * Remove the audio filter and output clip from the given command and get both.
     *
     * @return array{0: list<string>, 1: string}
     */
    protected function extractAudioFilter(array $command): array
    {
        $filter = '';
        $result = [];

        for ($i = 0; $i < count($command); $i++) {
            $token = $command[$i];

            if (preg_match('/^-(?:ar|ac)(?::|$)/', $token)) {
                throw new InvalidArgumentException(
                    'Use audioFilter() for channel preprocessing; sampleRate is owned by normalizeLoudness().'
                );
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

        return [$result, $filter];
    }

    /**
     * Measure the prepared audio and build the final normalization pass.
     * This runs after staging and probing, before the final encoding pass.
     *
     * @internal
     */
    public function analyze(Workspace $workspace, array $command, array $outputPaths, Closure $analyze): array
    {
        $this->measurement = null;
        $this->normalization = null;
        $this->workspace = $workspace;
        $inputEnd = array_search('-i', $command, true) + 2;
        $prefix = array_slice($command, 0, $inputEnd);
        $mapIndex = array_search('-map', $command, true);
        $filterIndex = array_search('-filter:a', $command, true);
        $preprocessing = Str::beforeLast($command[$filterIndex + 1], ','.$this->loudnorm('loudness-final.json'));
        $prepared = 'loudness-prepared.nut';

        // We decode the selected, trimmed and channel-processed samples only once. Both passes
        // reopen this private lossless file, never a mutable remote or local source.
        $analyze([...$prefix, '-map', $command[$mapIndex + 1], '-vn', '-af', $preprocessing,
            '-map_metadata', '0', '-c:a', 'pcm_f64le', '-f', 'nut', $prepared], 'Loudness analysis failed.');

        $measure = [
            $command[0], '-hide_banner', '-nostdin', '-y', '-xerror', '-progress', 'pipe:1', '-i', $prepared,
            '-af', $this->loudnorm('loudness-measure.json'), '-f', 'null', '-',
        ];

        $analyze($measure, 'Loudness analysis failed.');

        $this->measurement = $this->readStats("{$workspace->path}/loudness-measure.json", Progress::ANALYSIS);
        $normalizer = $this->loudnorm('loudness-final.json');

        // Linear normalization needs the measured values, so we will pass them to the final
        // pass whenever they fit the ranges that loudnorm's measured options accept.
        if ($this->measurement->usableMeasurements()) {
            $stats = $this->measurement;
            $normalizer .= ":measured_I={$this->number($stats->inputIntegrated)}"
                .":measured_TP={$this->number($stats->inputTruePeak)}"
                .":measured_LRA={$this->number($stats->inputRange)}"
                .":measured_thresh={$this->number($stats->inputThreshold)}"
                .":offset={$this->number($stats->targetOffset)}";
        }

        // The final pass reads the prepared file but keeps the encoder, container, metadata
        // and publication settings of the ordinary pipeline.
        $final = [
            $command[0], '-hide_banner', '-nostdin', '-nostats', '-y', '-xerror', '-progress', 'pipe:1',
            '-i', $prepared,
        ];

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

    /**
     * Build the loudnorm filter that writes its statistics to the given file.
     */
    protected function loudnorm(string $statistics): string
    {
        return "loudnorm=I={$this->number($this->integrated)}:TP={$this->number($this->truePeak)}"
            .":LRA={$this->number($this->range)}:linear=".($this->linear ? 'true' : 'false')
            .":print_format=json:stats_file={$statistics}";
    }

    /**
     * Format the given number for a filter argument.
     */
    protected function number(float $number): string
    {
        return json_encode($number, JSON_THROW_ON_ERROR);
    }

    /**
     * Read and validate the loudnorm statistics file at the given path.
     */
    protected function readStats(string $path, string $phase = 'validation'): LoudnessStats
    {
        if (! is_file($path)) {
            throw new MediaException('Loudness statistics are missing.', $phase, 'analysis');
        }

        if (filesize($path) > 65536) {
            throw new MediaException('Loudness statistics exceed 64 KiB.', $phase, 'analysis');
        }

        if (($json = file_get_contents($path)) === false) {
            throw new MediaException('Loudness statistics cannot be read.', $phase, 'analysis');
        }

        return LoudnessStats::fromJson($json, $phase);
    }

    /**
     * Read the final statistics and ensure the output holds one audio stream at the requested sample rate.
     *
     * @internal
     */
    public function artifact(string $path, string $role, int $size): void
    {
        $this->normalization = $this->readStats("{$this->workspace->path}/loudness-final.json");

        try {
            $streams = $this->source->probeArtifact($path, $this->workspace)->streams();
        } catch (MediaException $mediaException) {
            if ($mediaException->category === 'timeout') {
                throw $mediaException;
            }

            $streams = collect();
        }

        if ($streams->count() !== 1 || $streams->first()->get('codec_type') !== 'audio') {
            throw MediaException::validation('Normalized output must contain exactly one audio stream.', 'artifact');
        }

        if ($streams->first()->sampleRate() !== $this->sampleRate) {
            throw MediaException::validation(
                "Normalized output must use the requested sample rate [{$this->sampleRate}].",
                'artifact',
            );
        }
    }
}
